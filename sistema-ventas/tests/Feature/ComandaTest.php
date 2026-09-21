<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Pedidos;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La comanda impresa para la cocina.
 *
 * Lo que se cuida: que lleve lo que la cocina lee (número, comer aquí o para
 * llevar, cada plato con su nota) y nada de dinero; que imprimir de nuevo no
 * repita lo que ya salió; que la reimpresión traiga todo, marcada, sin marcar
 * nada; que lo cancelado después de salir en papel se avise en papel una sola
 * vez; y que quede en la bitácora quién la imprimió.
 */
class ComandaTest extends TestCase
{
    use DatabaseTransactions;

    private function usuario(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    private function cajero(): Usuario
    {
        return $this->usuario('cajero1');
    }

    private function turno(?Usuario $usuario = null): SesionCaja
    {
        $usuario ??= $this->cajero();

        return (Cajas::sesionDe($usuario) ?? Cajas::abrir(Caja::firstOrFail(), $usuario, 100))->fresh();
    }

    private function plato(string $codigo): Producto
    {
        return Producto::where('codigo', $codigo)->firstOrFail();
    }

    /**
     * Vende en el mostrador. Por omisión: dos piques machos con nota, una
     * milanesa y un refresco (Bebidas, que no pasa por la cocina).
     *
     * @param  array<int, array<string, mixed>>|null  $lineas
     */
    private function vender(?array $lineas = null, string $tipo = Pedido::LOCAL): Venta
    {
        $lineas ??= [
            ['producto_id' => $this->plato('P-0010')->id, 'cantidad' => 2, 'nota' => 'uno sin picante'],
            ['producto_id' => $this->plato('P-0004')->id, 'cantidad' => 1],
            ['producto_id' => $this->plato('P-0005')->id, 'cantidad' => 1],
        ];

        return Pedidos::venderEnMostrador(
            sesion: $this->turno(),
            usuario: $this->cajero(),
            lineas: $lineas,
            pagos: [['metodo_pago_id' => (int) MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
            tipo: $tipo,
        )->fresh();
    }

    /** Pide la comanda como lo hace el botón y devuelve la hoja que se imprime. */
    private function imprimir(Pedido $pedido, ?Usuario $quien = null, string $ruta = 'pedidos.comanda.imprimir'): string
    {
        $respuesta = $this->actingAs($quien ?? $this->cajero())->post(route($ruta, $pedido))->assertRedirect();
        $destino = $respuesta->headers->get('Location');
        $this->assertStringContainsString('imprimir=1', $destino, 'la comanda recién pedida tiene que abrir la impresión');

        return $this->actingAs($quien ?? $this->cajero())->get($destino)->assertOk()->getContent();
    }

    private function texto(string $html): string
    {
        return preg_replace('/\s+/u', ' ', strip_tags($html));
    }

    /** @return array<string, mixed> */
    private function ultimaAuditoria(Pedido $pedido): array
    {
        return json_decode((string) DB::table('auditoria')
            ->where('accion', 'COMANDA_IMPRESA')->where('entidad_id', $pedido->id)
            ->orderByDesc('id')->value('detalle'), true);
    }

    // ------------------------------------------------------------ lo que lleva

    /**
     * El número y el destino en grande, cada plato con su cantidad y su nota,
     * y nada más: ni precios, ni la gaseosa, que no se cocina.
     */
    public function test_la_comanda_lleva_el_numero_el_destino_y_las_notas_sin_precios(): void
    {
        $venta = $this->vender();
        $pedido = $venta->pedido;

        $html = $this->imprimir($pedido);
        $texto = $this->texto($html);

        $this->assertStringContainsString("#{$pedido->numero_dia}", $texto);
        $this->assertStringContainsString('COMER AQUÍ', $texto);
        $this->assertStringContainsString('2 × Pique macho', $texto);
        $this->assertStringContainsString('» uno sin picante', $texto);
        $this->assertStringContainsString('1 × Milanesa de pollo', $texto);
        $this->assertStringNotContainsString('Refresco de la casa', $texto, 'la bebida no va a la cocina');
        $this->assertStringNotContainsString('REIMPRESIÓN', $texto);

        // Nada de dinero: ni el símbolo de la moneda ni los importes.
        $this->assertStringNotContainsString(Config::moneda().' ', $texto);
        $this->assertStringNotContainsString(number_format((float) $venta->total, 2), $texto);

        // Las líneas de la cocina quedaron marcadas; la bebida, no.
        $this->assertSame(2, $pedido->detalle()->whereNotNull('comandado_en')->count());
        $this->assertNull($pedido->detalle()->where('pasa_por_cocina', false)->value('comandado_en'));

        // (assertEquals: la columna JSON de MySQL guarda las claves en su propio orden.)
        $this->assertEquals(
            ['numero' => $pedido->numero_dia, 'lineas' => 2, 'canceladas' => 0, 'reimpresion' => false],
            $this->ultimaAuditoria($pedido),
        );
        $this->assertSame($this->cajero()->id, (int) DB::table('auditoria')->where('accion', 'COMANDA_IMPRESA')
            ->where('entidad_id', $pedido->id)->value('usuario_id'));
    }

    /** Para llevar, con el nombre para llamarlo, y también en A4. */
    public function test_para_llevar_con_nombre_y_en_a4(): void
    {
        $pedido = Pedidos::venderEnMostrador(
            sesion: $this->turno(), usuario: $this->cajero(),
            lineas: [['producto_id' => $this->plato('P-0001')->id, 'cantidad' => 1]],
            pagos: [['metodo_pago_id' => (int) MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
            tipo: Pedido::LLEVAR, nombreCliente: 'Rosaura',
        )->pedido;

        $texto = $this->texto($this->imprimir($pedido));
        $this->assertStringContainsString('PARA LLEVAR', $texto);
        $this->assertStringContainsString('Rosaura', $texto);

        $this->actingAs($this->cajero())->get(route('pedidos.comanda', [$pedido, 'formato' => 'a4']))->assertOk()
            ->assertSee('210mm')
            ->assertSee('#'.$pedido->numero_dia);
    }

    // --------------------------------------------------- una vez, y de nuevo

    /**
     * Imprimir otra vez no manda de nuevo lo que ya salió: sin nada pendiente,
     * es una reimpresión, marcada y registrada como tal.
     */
    public function test_imprimir_de_nuevo_no_repite_lo_que_ya_salio(): void
    {
        $pedido = $this->vender()->pedido;
        $this->imprimir($pedido);
        $marcas = $pedido->detalle()->pluck('comandado_en', 'id')->map(fn ($m) => $m?->toDateTimeString())->all();

        $this->travel(2)->minutes();
        $html = $this->imprimir($pedido);

        $this->assertStringContainsString('REIMPRESIÓN', $this->texto($html));
        $this->assertSame($marcas, $pedido->detalle()->pluck('comandado_en', 'id')->map(fn ($m) => $m?->toDateTimeString())->all(),
            'la reimpresión volvió a marcar las líneas');
        $this->assertTrue($this->ultimaAuditoria($pedido)['reimpresion']);
    }

    /** Se perdió el papel: la reimpresión trae la comanda completa y no marca nada. */
    public function test_la_reimpresion_trae_todo_y_no_marca_nada(): void
    {
        $pedido = $this->vender()->pedido;

        $texto = $this->texto($this->imprimir($pedido, ruta: 'pedidos.comanda.reimprimir'));

        $this->assertStringContainsString('REIMPRESIÓN', $texto);
        $this->assertStringContainsString('2 × Pique macho', $texto);
        $this->assertStringContainsString('1 × Milanesa de pollo', $texto);
        $this->assertSame(0, $pedido->detalle()->whereNotNull('comandado_en')->count(), 'la reimpresión marcó líneas');
        $this->assertEquals(
            ['numero' => $pedido->numero_dia, 'lineas' => 2, 'canceladas' => 0, 'reimpresion' => true],
            $this->ultimaAuditoria($pedido),
        );

        // Y la comanda de verdad sigue pendiente.
        $this->assertStringNotContainsString('REIMPRESIÓN', $this->texto($this->imprimir($pedido)));
    }

    // ------------------------------------------------ lo que se cancela después

    /**
     * Un plato que ya estaba en papel en la cocina y se cancela —aquí, en el
     * pedido que quedó para volver a cobrar al anular su venta— se avisa en papel, una
     * sola vez. Si no, el cocinero lo prepara igual.
     */
    public function test_un_plato_cancelado_despues_de_comandar_se_avisa_una_vez(): void
    {
        $venta = $this->vender();
        $pedido = $venta->pedido;
        $this->imprimir($pedido);

        Ventas::anular($venta, $this->usuario('admin'), 'Se cobró con la forma de pago equivocada');
        $pique = $pedido->detalle()->where('producto_id', $this->plato('P-0010')->id)->firstOrFail();

        $this->actingAs($this->cajero())->post(route('pedidos.lineas.cancelar', [$pedido, $pique]))->assertSessionHas('exito');

        // La pantalla para volver a cobrar el pedido ofrece el aviso.
        $this->actingAs($this->cajero())->get(route('pedidos.cobrar', $pedido))->assertOk()
            ->assertSee('data-aviso-cocina', false);

        $texto = $this->texto($this->imprimir($pedido));

        $this->assertStringContainsString('AVISO DE CANCELACIÓN', $texto);
        $this->assertStringContainsString('CANCELADO: 2 × Pique macho', $texto);
        $this->assertStringNotContainsString('Milanesa de pollo', $texto, 'la milanesa ya había salido: no se repite');
        $this->assertNotNull($pique->fresh()->cancelacion_comandada_en);
        $this->assertEquals(['numero' => $pedido->numero_dia, 'lineas' => 0, 'canceladas' => 1, 'reimpresion' => false], $this->ultimaAuditoria($pedido));

        // Avisado una vez: ya no se ofrece ni se repite.
        $this->actingAs($this->cajero())->get(route('pedidos.cobrar', $pedido))->assertOk()
            ->assertDontSee('data-aviso-cocina', false);
        $this->assertStringContainsString('REIMPRESIÓN', $this->texto($this->imprimir($pedido)));
    }

    /**
     * Lo que se cancela ANTES de salir en papel no se avisa: la cocina nunca
     * lo supo. Ni aparece en la comanda.
     */
    public function test_lo_cancelado_antes_de_comandar_no_se_avisa(): void
    {
        $venta = $this->vender();
        $pedido = $venta->pedido;
        Ventas::anular($venta, $this->usuario('admin'), 'Se cobró de más');
        $pique = $pedido->detalle()->where('producto_id', $this->plato('P-0010')->id)->firstOrFail();
        Pedidos::actualizarEstadoLinea($pique, PedidoDetalle::CANCELADO, $this->cajero());

        $texto = $this->texto($this->imprimir($pedido));

        $this->assertStringNotContainsString('Pique macho', $texto);
        $this->assertStringContainsString('1 × Milanesa de pollo', $texto);
    }

    /**
     * El pedido entero se cancela después de salir en papel: el punto de venta
     * ofrece imprimir el aviso, y la comanda dice que no se prepare.
     *
     * Lo cancela el administrador: el refresco se entregó con el ticket, así
     * que cuenta como consumido y el cajero no puede dejarlo sin cobrar (C1).
     */
    public function test_cancelar_el_pedido_despues_de_comandar_avisa_a_la_cocina(): void
    {
        $admin = $this->usuario('admin');
        $venta = $this->vender();
        $pedido = $venta->pedido;
        $this->imprimir($pedido);
        Ventas::anular($venta, $admin, 'El cliente se arrepintió');

        $this->actingAs($this->cajero())->post(route('pedidos.cancelar', $pedido), ['motivo' => 'Se fue sin esperar'])
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'administrador'));

        $this->actingAs($admin)->post(route('pedidos.cancelar', $pedido), ['motivo' => 'Se fue sin esperar'])
            ->assertRedirect(route('pos.index'))
            ->assertSessionHas('comanda_pendiente', $pedido->id);

        $this->actingAs($this->cajero())->withSession(['comanda_pendiente' => $pedido->id])
            ->get(route('pos.index'))->assertOk()
            ->assertSee('data-aviso-cocina', false)
            ->assertSee(route('pedidos.comanda.imprimir', $pedido), false);

        $texto = $this->texto($this->imprimir($pedido));

        $this->assertStringContainsString('PEDIDO CANCELADO', $texto);
        $this->assertStringContainsString('NO PREPARAR', $texto);
        $this->assertStringContainsString('CANCELADO: 2 × Pique macho', $texto);
        $this->assertStringContainsString('CANCELADO: 1 × Milanesa de pollo', $texto);
        $this->assertSame(0, $pedido->detalle()->paraLaCocina()->whereNull('cancelacion_comandada_en')->count());
    }

    // --------------------------------------------------- dónde están los botones

    /** Tras cobrar en el mostrador, la venta ofrece la comanda; después, reimprimirla. */
    public function test_la_venta_ofrece_la_comanda_y_despues_reimprimirla(): void
    {
        $venta = $this->vender();
        $pedido = $venta->pedido;

        $this->actingAs($this->cajero())->get(route('ventas.show', $venta))->assertOk()
            ->assertSee(route('pedidos.comanda.imprimir', $pedido), false)
            ->assertSee('Imprimir comanda');

        $this->imprimir($pedido);

        $this->actingAs($this->cajero())->get(route('ventas.show', $venta))->assertOk()
            ->assertSee(route('pedidos.comanda.reimprimir', $pedido), false)
            ->assertSee('Reimprimir comanda');
    }

    /** Un pedido solo de bebidas no tiene nada para la cocina: no hay comanda que ofrecer. */
    public function test_un_pedido_sin_nada_para_la_cocina_no_ofrece_comanda(): void
    {
        $venta = $this->vender([['producto_id' => $this->plato('P-0005')->id, 'cantidad' => 2]]);

        $this->actingAs($this->cajero())->get(route('ventas.show', $venta))->assertOk()
            ->assertDontSee('Imprimir comanda')
            ->assertDontSee('Reimprimir comanda');
    }

    /** La cocina también la imprime, desde la tarjeta de cada pedido. */
    public function test_la_cocina_imprime_la_comanda_desde_su_pantalla(): void
    {
        $pedido = $this->vender()->pedido;
        $cocina = $this->usuario('cocina1');

        $this->actingAs($cocina)->get(route('cocina.index'))->assertOk()
            ->assertSee(route('pedidos.comanda.imprimir', $pedido), false)
            ->assertSee('data-comanda-boton="imprimir"', false);

        $this->assertStringContainsString("#{$pedido->numero_dia}", $this->texto($this->imprimir($pedido, $cocina)));

        $this->actingAs($cocina)->get(route('cocina.index'))->assertOk()
            ->assertSee(route('pedidos.comanda.reimprimir', $pedido), false);
        $this->assertSame($cocina->id, (int) DB::table('auditoria')->where('accion', 'COMANDA_IMPRESA')
            ->where('entidad_id', $pedido->id)->value('usuario_id'));
    }
}
