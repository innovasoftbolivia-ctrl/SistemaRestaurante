<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Lote;
use App\Models\MetodoPago;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\Compras;
use App\Services\Inventario;
use App\Services\Lotes;
use App\Services\Ventas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El stock partido por fecha de vencimiento.
 *
 * La regla que estas pruebas defienden es la misma que ordena todo el módulo:
 * `productos.stock_actual` manda, y los lotes son ese saldo repartido por
 * fecha. La suma de los lotes tiene que dar el stock — si un día no diera, la
 * cifra buena sería la de `productos` y la de lotes la sospechosa.
 *
 * La segunda regla es el orden de salida: se despacha lo que vence antes
 * (FEFO), que es lo que hace que la alerta de «se me vence en dos semanas»
 * sirva para algo. Si el mostrador descontara de cualquier lote, la alerta
 * diría que quedan 40 unidades por vencer cuando en realidad ya se vendieron.
 */
class VencimientoTest extends TestCase
{
    use DatabaseTransactions;

    private function almacenero(): Usuario
    {
        return Usuario::where('usuario', 'almacen')->firstOrFail();
    }

    private function cajero(): Usuario
    {
        return Usuario::where('usuario', 'cajero1')->firstOrFail();
    }

    /** Un producto que vence, sin nada cargado todavía. */
    private function perecedero(): Producto
    {
        $producto = Producto::activos()
            ->whereHas('unidadMedida', fn ($q) => $q->where('permite_decimal', 0))
            ->firstOrFail();

        $producto->forceFill([
            'controla_vencimiento' => 1,
            'contenido_empaque' => null,
            'nombre_empaque' => null,
        ])->save();

        // Se parte de cero para que las cuentas de la prueba sean las de la
        // prueba y no las del catálogo de ejemplo.
        Lote::where('producto_id', $producto->id)->delete();
        $producto->forceFill(['stock_actual' => 0])->save();

        return $producto->fresh();
    }

    /** Quien ajusta inventario: es el permiso que pide dar de baja una tanda. */
    private function queAjusta(): Usuario
    {
        return Usuario::whereHas('rol.permisos', fn ($q) => $q->where('codigo', 'inventario.ajustar'))
            ->firstOrFail();
    }

    // ------------------------------------------------- dar de baja lo vencido

    /**
     * Lo vencido sigue contando como stock hasta que alguien lo saca.
     *
     * Mientras no se haga, el mostrador lo deja vender y el reporte lo valora.
     * Esta es la salida, y es un ajuste: la mercadería no se vendió ni volvió
     * al proveedor, dejó de existir.
     */
    public function test_dar_de_baja_una_tanda_vencida_la_saca_del_stock(): void
    {
        $producto = $this->perecedero();

        $this->cargar($producto, 10, now()->subDays(4)->toDateString());
        $this->cargar($producto, 15, now()->addMonths(6)->toDateString());

        $vencida = Lote::where('producto_id', $producto->id)
            ->orderBy('fecha_vencimiento')
            ->firstOrFail();

        $this->actingAs($this->queAjusta())
            ->post(route('vencimientos.baja', $vencida))
            ->assertRedirect();

        $this->assertSame(0.0, (float) $vencida->fresh()->cantidad_actual);
        $this->assertSame(15.0, (float) $producto->fresh()->stock_actual);

        // La regla de siempre: los lotes son el stock repartido.
        $this->assertSame(
            15.0,
            (float) Lote::where('producto_id', $producto->id)->sum('cantidad_actual'),
        );
    }

    /**
     * Sale ESA tanda y no la que tocaría por orden.
     *
     * Es lo que distingue la baja del ajuste normal: no se está descontando a
     * ciegas, se está tirando un lote concreto porque venció.
     */
    public function test_la_baja_descuenta_la_tanda_elegida(): void
    {
        $producto = $this->perecedero();

        $this->cargar($producto, 8, now()->subDays(10)->toDateString());
        $this->cargar($producto, 12, now()->subDays(2)->toDateString());

        $laSegunda = Lote::where('producto_id', $producto->id)
            ->orderByDesc('fecha_vencimiento')
            ->firstOrFail();

        $this->actingAs($this->queAjusta())
            ->post(route('vencimientos.baja', $laSegunda))
            ->assertRedirect();

        $this->assertSame(0.0, (float) $laSegunda->fresh()->cantidad_actual);
        $this->assertSame(
            8.0,
            (float) Lote::where('producto_id', $producto->id)
                ->orderBy('fecha_vencimiento')
                ->firstOrFail()
                ->cantidad_actual,
        );
    }

    /** El motivo lo escribe el sistema: un kardex con explicaciones sueltas no sirve. */
    public function test_la_baja_deja_su_ajuste_explicado_en_el_kardex(): void
    {
        $producto = $this->perecedero();
        $this->cargar($producto, 6, now()->subDays(3)->toDateString());

        $lote = Lote::where('producto_id', $producto->id)->sole();
        $lote->forceFill(['codigo' => 'L04821'])->save();

        $this->actingAs($this->queAjusta())
            ->post(route('vencimientos.baja', $lote), ['observacion' => 'Se botó'])
            ->assertRedirect();

        $movimiento = MovimientoInventario::where('producto_id', $producto->id)
            ->where('origen', 'AJUSTE')
            ->latest('id')
            ->sole();

        $this->assertSame('AJUSTE', $movimiento->tipo);
        $this->assertSame(6.0, (float) $movimiento->cantidad);
        $this->assertSame(0.0, (float) $movimiento->stock_resultante);
        $this->assertStringContainsString('Baja por vencimiento', $movimiento->motivo);
        $this->assertStringContainsString('L04821', $movimiento->motivo);
        $this->assertStringContainsString('Se botó', $movimiento->motivo);
    }

    /** Lo que todavía no vence se vende o se devuelve; tirarlo sería perderlo. */
    public function test_no_se_da_de_baja_lo_que_todavia_no_vencio(): void
    {
        $producto = $this->perecedero();
        $this->cargar($producto, 9, now()->addDays(20)->toDateString());

        $lote = Lote::where('producto_id', $producto->id)->sole();

        $this->actingAs($this->queAjusta())
            ->post(route('vencimientos.baja', $lote))
            ->assertRedirect();

        $this->assertSame(9.0, (float) $lote->fresh()->cantidad_actual);
        $this->assertSame(9.0, (float) $producto->fresh()->stock_actual);
    }

    /** El cajero no ajusta inventario, y esto es un ajuste. */
    public function test_el_cajero_no_da_de_baja_tandas(): void
    {
        $producto = $this->perecedero();
        $this->cargar($producto, 5, now()->subDays(1)->toDateString());

        $lote = Lote::where('producto_id', $producto->id)->sole();

        $this->actingAs($this->cajero())
            ->post(route('vencimientos.baja', $lote))
            ->assertForbidden();

        $this->assertSame(5.0, (float) $lote->fresh()->cantidad_actual);
    }

    private function efectivo(): MetodoPago
    {
        return MetodoPago::where('codigo', 'EFECTIVO')->firstOrFail();
    }

    private function turno(): SesionCaja
    {
        return Cajas::abrir(Caja::firstOrFail(), $this->cajero(), 100);
    }

    private function cargar(Producto $producto, float $cantidad, ?string $vence): void
    {
        $this->actingAs($this->almacenero());

        Inventario::ingreso($producto, $cantidad, vence: $vence);
    }

    // ----------------------------------------------------------- lo que entra

    public function test_la_mercaderia_que_entra_abre_su_tanda_con_la_fecha(): void
    {
        $producto = $this->perecedero();

        $this->cargar($producto, 30, '2026-12-31');

        $lote = Lote::where('producto_id', $producto->id)->firstOrFail();

        $this->assertSame('30.000', $lote->cantidad_inicial);
        $this->assertSame('30.000', $lote->cantidad_actual);
        $this->assertSame('2026-12-31', $lote->fecha_vencimiento->toDateString());
        $this->assertSame('30.000', $producto->fresh()->stock_actual);
    }

    /** El detergente no vence: pedirle una fecha sería ruido, y no se guarda. */
    public function test_un_producto_sin_control_no_genera_lotes(): void
    {
        $producto = Producto::activos()->firstOrFail();
        $producto->forceFill(['controla_vencimiento' => 0])->save();

        $antes = Lote::where('producto_id', $producto->id)->count();

        $this->cargar($producto->fresh(), 10, '2026-12-31');

        $this->assertSame($antes, Lote::where('producto_id', $producto->id)->count());
    }

    // ------------------------------------------------------------ FEFO

    /**
     * El corazón del asunto: se vende lo que vence antes, aunque haya entrado
     * después.
     */
    public function test_la_venta_descuenta_primero_lo_que_vence_antes(): void
    {
        $producto = $this->perecedero();

        // Entra primero lo que vence TARDE, para que el orden de entrada no
        // pueda confundirse con el orden de salida.
        $this->cargar($producto, 10, '2027-06-30');
        $this->cargar($producto, 10, '2026-10-15');

        $sesion = $this->turno();

        Ventas::registrar(
            sesion: $sesion,
            usuario: $this->cajero(),
            lineas: [['producto_id' => $producto->id, 'cantidad' => 6, 'precio_unitario' => 1.0]],
            pagos: [['metodo_pago_id' => $this->efectivo()->id, 'monto' => null]],
        );

        $lotes = Lote::where('producto_id', $producto->id)->enOrdenDeSalida()->get();

        // El que vence en octubre queda con 4; el de junio, intacto.
        $this->assertSame('2026-10-15', $lotes[0]->fecha_vencimiento->toDateString());
        $this->assertSame('4.000', $lotes[0]->cantidad_actual);
        $this->assertSame('10.000', $lotes[1]->cantidad_actual);
    }

    /** Una venta puede barrer varias tandas si la primera no alcanza. */
    public function test_una_venta_grande_barre_varias_tandas(): void
    {
        $producto = $this->perecedero();

        $this->cargar($producto, 5, '2026-10-15');
        $this->cargar($producto, 10, '2027-06-30');

        Ventas::registrar(
            sesion: $this->turno(),
            usuario: $this->cajero(),
            lineas: [['producto_id' => $producto->id, 'cantidad' => 12, 'precio_unitario' => 1.0]],
            pagos: [['metodo_pago_id' => $this->efectivo()->id, 'monto' => null]],
        );

        $lotes = Lote::where('producto_id', $producto->id)->enOrdenDeSalida()->get();

        $this->assertSame('0.000', $lotes[0]->cantidad_actual);   // se agotó
        $this->assertSame('3.000', $lotes[1]->cantidad_actual);   // 10 − 7
    }

    /**
     * La invariante que sostiene todo: la suma de los lotes es el stock. Si
     * esta prueba cayera, habría dos contabilidades diciendo cosas distintas.
     */
    public function test_los_lotes_siempre_suman_el_stock(): void
    {
        $producto = $this->perecedero();

        $this->cargar($producto, 20, '2026-10-15');
        $this->cargar($producto, 15, '2027-01-31');

        Ventas::registrar(
            sesion: $this->turno(),
            usuario: $this->cajero(),
            lineas: [['producto_id' => $producto->id, 'cantidad' => 9, 'precio_unitario' => 1.0]],
            pagos: [['metodo_pago_id' => $this->efectivo()->id, 'monto' => null]],
        );

        $this->actingAs($this->almacenero());
        Inventario::ajuste($producto->fresh(), 20, 'Conteo de prueba');

        $producto = $producto->fresh();
        $enLotes = (float) Lote::where('producto_id', $producto->id)->sum('cantidad_actual');

        $this->assertSame((float) $producto->stock_actual, $enLotes);
    }

    /** Lo que vuelve del cliente se repone donde habría salido. */
    public function test_un_ajuste_al_alza_repone_en_la_tanda_que_vence_antes(): void
    {
        $producto = $this->perecedero();

        $this->cargar($producto, 10, '2026-10-15');
        $this->cargar($producto, 10, '2027-06-30');

        $this->actingAs($this->almacenero());
        Inventario::ajuste($producto->fresh(), 25, 'Aparecieron 5 más');

        $lotes = Lote::where('producto_id', $producto->id)->enOrdenDeSalida()->get();

        $this->assertSame('15.000', $lotes[0]->cantidad_actual);
        $this->assertSame('10.000', $lotes[1]->cantidad_actual);
    }

    // ---------------------------------------------------------- sin fecha

    /**
     * Al encender el control, el stock que ya había existe y hay que contarlo,
     * pero su fecha no la sabe nadie. Decirlo es más honesto que inventarla.
     */
    public function test_encender_el_control_cuadra_el_stock_existente_sin_fecha(): void
    {
        $producto = Producto::activos()->firstOrFail();
        $producto->forceFill(['controla_vencimiento' => 0, 'stock_actual' => 40])->save();
        Lote::where('producto_id', $producto->id)->delete();

        $producto->forceFill(['controla_vencimiento' => 1])->save();
        Lotes::cuadrarConElStock($producto->fresh());

        $lote = Lote::where('producto_id', $producto->id)->firstOrFail();

        $this->assertNull($lote->fecha_vencimiento);
        $this->assertSame('40.000', $lote->cantidad_actual);
        $this->assertSame(40.0, Lotes::sinFecha($producto->fresh()));
    }

    /**
     * Lo que no tiene fecha sale al final: no se sabe cuándo vence, así que no
     * puede pasar delante de algo que sí tiene fecha y está por vencerse.
     */
    public function test_lo_que_no_tiene_fecha_se_despacha_al_final(): void
    {
        $producto = $this->perecedero();

        $this->cargar($producto, 10, null);
        $this->cargar($producto, 10, '2027-06-30');

        Ventas::registrar(
            sesion: $this->turno(),
            usuario: $this->cajero(),
            lineas: [['producto_id' => $producto->id, 'cantidad' => 4, 'precio_unitario' => 1.0]],
            pagos: [['metodo_pago_id' => $this->efectivo()->id, 'monto' => null]],
        );

        $conFecha = Lote::where('producto_id', $producto->id)->whereNotNull('fecha_vencimiento')->firstOrFail();
        $sinFecha = Lote::where('producto_id', $producto->id)->whereNull('fecha_vencimiento')->firstOrFail();

        $this->assertSame('6.000', $conFecha->cantidad_actual);
        $this->assertSame('10.000', $sinFecha->cantidad_actual);
    }

    // ----------------------------------------------------------- la alerta

    public function test_la_alerta_lista_lo_vencido_y_lo_que_esta_por_vencer(): void
    {
        $producto = $this->perecedero();

        $this->cargar($producto, 5, now()->subDays(3)->toDateString());     // ya vencido
        $this->cargar($producto, 7, now()->addDays(10)->toDateString());    // por vencer
        $this->cargar($producto, 9, now()->addDays(200)->toDateString());   // lejano

        $filas = collect(Lotes::alertas(30)->get());

        $this->assertSame(2, $filas->count());
        $this->assertTrue($filas->contains(fn ($f) => (int) $f->dias < 0));
        $this->assertFalse($filas->contains(fn ($f) => (int) $f->dias > 30));
    }

    public function test_la_pantalla_de_vencimientos_responde(): void
    {
        $this->actingAs($this->almacenero())->get(route('vencimientos.index'))->assertOk();

        $producto = $this->perecedero();
        $this->cargar($producto, 5, '2026-12-31');

        $this->actingAs($this->almacenero())
            ->get(route('vencimientos.producto', $producto))
            ->assertOk()
            ->assertSee($producto->nombre);
    }

    public function test_el_cajero_no_entra_a_vencimientos(): void
    {
        $this->actingAs($this->cajero())->get(route('vencimientos.index'))->assertForbidden();
    }

    // ------------------------------------------------------------- compras

    /** La compra abre la tanda con la fecha que se anotó en su línea. */
    public function test_una_compra_abre_la_tanda_con_su_fecha(): void
    {
        $producto = $this->perecedero();

        $compra = Compras::registrar(
            usuario: $this->almacenero(),
            proveedor: Proveedor::activos()->firstOrFail(),
            lineas: [[
                'producto_id' => $producto->id,
                'cantidad' => 24,
                'costo_unitario' => 3.0,
                'vence' => '2027-03-31',
                'lote' => 'L-4417',
            ]],
            documentoExterno: 'F001-09999',
        );

        $lote = Lote::where('producto_id', $producto->id)->firstOrFail();

        $this->assertSame('2027-03-31', $lote->fecha_vencimiento->toDateString());
        $this->assertSame('L-4417', $lote->codigo);
        $this->assertSame('24.000', $lote->cantidad_actual);

        // Y queda enganchada a la línea de esa factura.
        $this->assertSame($compra->id, $lote->compraDetalle->compra_id);
    }
}
