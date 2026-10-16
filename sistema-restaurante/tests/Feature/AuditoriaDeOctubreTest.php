<?php

namespace Tests\Feature;

use App\Http\Controllers\CocinaController;
use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Inventario;
use App\Services\Pedidos;
use App\Support\AlertasStock;
use App\Support\Config;
use App\Support\Notificaciones;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lo que encontró la segunda auditoría, arreglado y con su prueba: cada caso
 * describe cómo se rompía antes.
 */
class AuditoriaDeOctubreTest extends TestCase
{
    use ConDatosDeInventario;
    use DatabaseTransactions;

    private function usuario(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    private function turno(?Usuario $quien = null): SesionCaja
    {
        $quien ??= $this->usuario('admin');

        return Cajas::sesionDe($quien) ?? Cajas::abrir(Caja::firstOrFail(), $quien, 100);
    }

    /** La bebida con stock: se compra hecha y se vende por unidad. */
    private function bebida(float $stock = 20, float $minimo = 6): Producto
    {
        $p = Producto::where('codigo', 'P-0005')->firstOrFail();
        $p->forceFill([
            'controla_stock' => true, 'contenido_empaque' => 12, 'nombre_empaque' => 'Caja',
            'stock_minimo' => $minimo, 'costo' => 4, 'stock_actual' => 0,
        ])->save();

        if ($stock > 0) {
            Inventario::inicial($p->fresh(), $stock, $this->usuario('admin'));
        }

        return $p->fresh();
    }

    /** Un plato cobrado en el mostrador. */
    private function vender(int $cantidad = 1): Venta
    {
        return Pedidos::venderEnMostrador(
            $this->turno()->fresh(), $this->usuario('admin'),
            [['producto_id' => Producto::where('codigo', 'P-0004')->value('id'), 'cantidad' => $cantidad]],
            [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        )->fresh();
    }

    // ============================================ la jornada, guardada con la venta

    /** La venta nace con su jornada, como el pedido. */
    public function test_la_venta_guarda_a_que_jornada_pertenece(): void
    {
        $venta = $this->vender();

        $this->assertSame(Config::jornadaActual(), $venta->jornada->toDateString());
        $this->assertSame($venta->pedido->jornada->toDateString(), $venta->jornada->toDateString());
    }

    /**
     * El hallazgo: la jornada se calculaba al vuelo con la hora de corte de
     * ese momento, así que cambiarla reescribía los reportes de los meses
     * pasados —y dejaban de cuadrar con los cierres de caja ya firmados—.
     * Ahora lo vendido ayer sigue siendo de ayer.
     */
    public function test_cambiar_la_hora_de_corte_no_mueve_las_ventas_ya_cobradas(): void
    {
        // Una venta de la madrugada: con el corte en 5 es de la noche anterior.
        $venta = $this->moverVenta($this->vender(), Carbon::parse(Config::jornadaActual())->addDay()->setTime(2, 0));
        $jornada = $venta->fresh()->jornada->toDateString();

        $lee = fn () => (float) DB::table('ventas')->where('jornada', $jornada)->sum('total');
        $antes = $lee();

        // El negocio adelanta la hora de corte: con 1, esa misma venta sería
        // del día siguiente si se recalculara.
        DB::table('configuracion')->where('clave', 'hora_corte_jornada')->update(['valor' => '1']);
        Config::olvidar();

        $this->assertSame(1, Config::horaCorteJornada());
        $this->assertSame($jornada, $venta->fresh()->jornada->toDateString(), 'la venta no se mueve de día');
        $this->assertSame($antes, $lee(), 'lo vendido esa jornada sigue siendo lo mismo');
    }

    /** Y la de mañana sí nace con la hora nueva: el cambio rige de ahora en adelante. */
    public function test_la_hora_nueva_rige_para_lo_que_se_cobre_despues(): void
    {
        DB::table('configuracion')->where('clave', 'hora_corte_jornada')->update(['valor' => '0']);
        Config::olvidar();
        Pedidos::olvidar();

        $venta = $this->vender();

        $this->assertSame(now()->toDateString(), $venta->jornada->toDateString(), 'sin corte, la jornada es el día de calendario');
    }

    // ============================================ el ajuste de stock, en la bitácora

    /**
     * El ajuste a mano es la forma de tapar un faltante: queda en el kardex,
     * pero el administrador lo busca en la bitácora, donde está todo lo demás
     * que hace una persona.
     */
    public function test_el_ajuste_de_stock_queda_en_la_bitacora(): void
    {
        $bebida = $this->bebida(stock: 20);
        $admin = $this->usuario('admin');

        Inventario::ajuste($bebida, 17, 'Se rompieron tres botellas', $admin);

        $entrada = Auditoria::where('accion', 'INVENTARIO_AJUSTADO')
            ->where('entidad_id', $bebida->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($admin->id, $entrada->usuario_id);
        $this->assertSame('productos', $entrada->entidad);
        $this->assertSame(20.0, (float) $entrada->detalle['antes']);
        $this->assertSame(17.0, (float) $entrada->detalle['contado']);
        $this->assertSame(-3.0, (float) $entrada->detalle['diferencia']);
        $this->assertSame('Se rompieron tres botellas', $entrada->detalle['motivo']);
    }

    /** Un ajuste que no cambia nada no ensucia la bitácora. */
    public function test_contar_lo_mismo_no_deja_entrada(): void
    {
        $bebida = $this->bebida(stock: 12);
        $antes = Auditoria::where('accion', 'INVENTARIO_AJUSTADO')->count();

        $this->assertNull(Inventario::ajuste($bebida, 12, 'Conteo de rutina', $this->usuario('admin')));
        $this->assertSame($antes, Auditoria::where('accion', 'INVENTARIO_AJUSTADO')->count());
    }

    // ============================================ la campana, sin repetir consultas

    /**
     * La campana está en la cabecera de TODAS las pantallas y armar sus avisos
     * son diez consultas: se guardaban en cada carga. Ahora se calculan una
     * vez cada {@see Notificaciones::SEGUNDOS} segundos.
     */
    public function test_los_avisos_se_guardan_unos_segundos(): void
    {
        $admin = $this->usuario('admin');
        Notificaciones::olvidar();

        $consultas = 0;
        DB::listen(function () use (&$consultas) {
            $consultas++;
        });

        Notificaciones::para($admin);
        $primera = $consultas;
        $this->assertGreaterThan(0, $primera, 'la primera vez sí consulta');

        Notificaciones::para($admin);
        $this->assertSame($primera, $consultas, 'la segunda sale de lo guardado');

        Notificaciones::olvidar();
        Notificaciones::para($admin);
        $this->assertGreaterThan($primera, $consultas, 'y al olvidarlo vuelve a mirar');
    }

    /** Sin nada en la cocina no se pregunta por los acompañamientos. */
    public function test_la_cocina_vacia_no_consulta_de_mas(): void
    {
        DB::table('pedido_detalle')->delete();

        $consultas = [];
        DB::listen(function ($q) use (&$consultas) {
            $consultas[] = $q->sql;
        });

        CocinaController::tandas();

        $this->assertEmpty(
            array_filter($consultas, fn ($sql) => str_contains($sql, '0 = 1')),
            'una consulta que ya se sabe vacía no sale a la base'
        );
    }

    /** Las alertas de stock se calculaban dos veces en la portada. */
    public function test_el_stock_bajo_se_calcula_una_sola_vez_por_peticion(): void
    {
        $this->bebida(stock: 1, minimo: 10);
        $admin = $this->usuario('admin');
        AlertasStock::olvidar();

        $consultas = 0;
        DB::listen(function () use (&$consultas) {
            $consultas++;
        });

        $primera = AlertasStock::para($admin);
        $cuantas = $consultas;

        $segunda = AlertasStock::para($admin);

        $this->assertSame($cuantas, $consultas, 'la segunda vez sale de memoria');
        $this->assertSame($primera['total'], $segunda['total']);
    }
}
