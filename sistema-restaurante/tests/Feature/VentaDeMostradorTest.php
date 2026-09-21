<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Categoria;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\Pedidos;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La venta del mostrador, que es por donde entra todo lo que se pide en el
 * local: el cliente pide y paga en la caja, se lleva un ticket con el número
 * del pedido y espera su plato.
 *
 * Lo que se cuida aquí es que la venta de mostrador cree un pedido COBRADO que
 * la cocina ve —con su número de la jornada, «comer aquí» o «para llevar» y
 * las notas—, sin perder nada de lo que el mostrador ya hacía con el dinero
 * (descuento y su tope, pagos mixtos, QR, el total esperado, el cliente, el
 * comprobante), y que todo pase en un solo acto: o queda el pedido cobrado con
 * su venta, o no queda nada.
 *
 * Las pruebas corren sin impuesto, para que el total sea la suma de los
 * precios del menú y se pueda escribir a mano. El impuesto ya lo cubren
 * `PuntoDeVentaTest`, `ImpuestoIncluidoTest` y `CircuitoCompletoTest`.
 */
class VentaDeMostradorTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('configuracion')->where('clave', 'tasa_impuesto')->update(['valor' => '0.0000']);
        Config::olvidar();
        Pedidos::olvidar();
    }

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

    private function plato(string $codigo = 'P-0004'): Producto
    {
        return Producto::where('codigo', $codigo)->firstOrFail();
    }

    /** Una bebida: la categoría «Bebidas» no pasa por la cocina. */
    private function bebida(): Producto
    {
        return $this->plato('P-0005');
    }

    private function metodo(string $codigo): int
    {
        return (int) MetodoPago::where('codigo', $codigo)->value('id');
    }

    /**
     * Vende por HTTP, tal como lo manda la pantalla del mostrador.
     *
     * @param  array<string, mixed>  $extra
     */
    private function vender(array $lineas, array $pagos, array $extra = [], ?Usuario $quien = null)
    {
        return $this->actingAs($quien ?? $this->cajero())->post(route('pos.store'), [
            'lineas' => $lineas,
            'pagos' => $pagos,
        ] + $extra);
    }

    private function ultimaVenta(SesionCaja $turno): Venta
    {
        return Venta::where('sesion_caja_id', $turno->id)->latest('id')->firstOrFail();
    }

    /** @return array<int, int> las líneas que la pantalla de la cocina tiene delante */
    private function enLaCocina(): array
    {
        return collect($this->actingAs($this->usuario('cocina1'))->getJson(route('cocina.pendientes'))->assertOk()->json('tandas'))
            ->flatMap(fn ($t) => collect($t['lineas'])->pluck('id'))
            ->all();
    }

    private function texto(string $html): string
    {
        return preg_replace('/\s+/u', ' ', strip_tags($html));
    }

    // ------------------------------------------------ el pedido que se crea

    /**
     * Un plato con su nota y una gaseosa, en efectivo. Queda un pedido para
     * comer aquí, cobrado, con su número de la jornada; el plato va a la
     * cocina con la nota, la gaseosa no, y el ticket lleva el número.
     */
    public function test_la_venta_de_mostrador_crea_un_pedido_cobrado_que_la_cocina_ve(): void
    {
        $turno = $this->turno();
        $plato = $this->plato();
        $bebida = $this->bebida();
        $total = round(2 * (float) $plato->precio_venta + (float) $bebida->precio_venta, 2);

        $respuesta = $this->vender([
            ['producto_id' => $plato->id, 'cantidad' => 2, 'nota' => 'una sin ensalada'],
            ['producto_id' => $bebida->id, 'cantidad' => 1],
        ], [['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto_recibido' => '50.00']], [
            'total_esperado' => number_format($total, 2, '.', ''),
        ])->assertSessionHasNoErrors();

        $venta = $this->ultimaVenta($turno);
        $pedido = $venta->pedido;

        $respuesta->assertRedirect(route('ventas.show', $venta))
            ->assertSessionHas('exito', fn (string $m) => str_contains($m, $pedido->numero_visible));

        $this->assertNotNull($pedido, 'la venta de mostrador no dejó pedido');
        $this->assertSame(Pedido::CERRADO, $pedido->estado);
        $this->assertSame(Pedido::LOCAL, $pedido->tipo, 'por omisión, el pedido es para comer aquí');
        $this->assertSame(Config::jornadaActual(), $pedido->jornada->toDateString());
        $this->assertGreaterThan(0, $pedido->numero_dia);
        $this->assertSame($this->cajero()->id, (int) $pedido->usuario_id);
        $this->assertSame($total, (float) $venta->total);
        $this->assertNotNull($venta->comprobante);

        // Las líneas: el plato con su nota, por hacer; la gaseosa, entregada con el ticket.
        $delPlato = $pedido->detalle()->where('producto_id', $plato->id)->firstOrFail();
        $deLaBebida = $pedido->detalle()->where('producto_id', $bebida->id)->firstOrFail();
        $this->assertSame('una sin ensalada', $delPlato->nota);
        $this->assertTrue($delPlato->pasa_por_cocina);
        $this->assertSame(PedidoDetalle::PENDIENTE, $delPlato->estado_cocina);
        $this->assertFalse($deLaBebida->pasa_por_cocina);
        $this->assertSame(PedidoDetalle::ENTREGADO, $deLaBebida->estado_cocina);
        $this->assertSame('Entregado', $deLaBebida->estado_visible);

        // La cocina ve el plato con su número y su nota; la gaseosa, no.
        $cocina = $this->enLaCocina();
        $this->assertContains($delPlato->id, $cocina);
        $this->assertNotContains($deLaBebida->id, $cocina);

        $this->actingAs($this->usuario('cocina1'))->get(route('cocina.index'))->assertOk()
            ->assertSee('data-pedido-cocina="'.$pedido->id.'"', false)
            ->assertSee('una sin ensalada')
            ->assertSee('Comer aquí')
            ->assertDontSee($bebida->nombre);

        // El ticket: el número del pedido, lo más grande del papel.
        $ticket = $this->actingAs($this->cajero())->get(route('comprobantes.imprimir', $venta->comprobante))->assertOk()->getContent();
        $this->assertStringContainsString("PEDIDO #{$pedido->numero_dia} COMER AQUÍ", $this->texto($ticket));
        $this->assertStringContainsString('data-numero-pedido="'.$pedido->numero_dia.'"', $ticket);

        // Y la pantalla de la venta lo muestra en grande, con la vuelta al mostrador.
        $this->actingAs($this->cajero())->get(route('ventas.show', $venta))->assertOk()
            ->assertSee('data-pedido-de-la-venta="'.$pedido->id.'"', false)
            ->assertSee(route('pos.index'), false);

        // En la bitácora: el pedido abierto, la venta y el cobro. No una fila por plato.
        $this->assertDatabaseHas('auditoria', ['accion' => 'PEDIDO_ABIERTO', 'entidad_id' => $pedido->id]);
        $this->assertDatabaseHas('auditoria', ['accion' => 'PEDIDO_COBRADO', 'entidad_id' => $pedido->id]);
        $this->assertDatabaseHas('auditoria', ['accion' => 'VENTA_REGISTRADA', 'entidad_id' => $venta->id]);
        $this->assertSame(0, DB::table('auditoria')->where('accion', 'PEDIDO_LINEA_AGREGADA')
            ->whereIn('entidad_id', $pedido->detalle()->pluck('id'))->count());
    }

    /** Para llevar y a nombre de alguien: lo dicen el ticket y la cocina. */
    public function test_un_pedido_para_llevar_lleva_el_nombre_para_llamarlo(): void
    {
        $turno = $this->turno();

        $this->vender([['producto_id' => $this->plato('P-0010')->id, 'cantidad' => 1, 'nota' => 'sin picante']],
            [['metodo_pago_id' => $this->metodo('EFECTIVO')]],
            ['tipo' => Pedido::LLEVAR, 'nombre_cliente' => 'Rosaura'],
        )->assertSessionHasNoErrors();

        $pedido = $this->ultimaVenta($turno)->pedido;

        $this->assertSame(Pedido::LLEVAR, $pedido->tipo);
        $this->assertSame('Rosaura', $pedido->nombre_cliente);
        $this->assertSame("{$pedido->numero_visible} · Para llevar · Rosaura", $pedido->etiqueta);

        $ticket = $this->actingAs($this->cajero())->get(route('comprobantes.imprimir', $pedido->venta->comprobante))->getContent();
        $this->assertStringContainsString("PEDIDO #{$pedido->numero_dia} PARA LLEVAR", $this->texto($ticket));
        $this->assertStringContainsString('Rosaura', $ticket);

        $this->actingAs($this->usuario('cocina1'))->get(route('cocina.index'))->assertOk()
            ->assertSee('Para llevar')
            ->assertSee('Rosaura')
            ->assertSee('sin picante');
    }

    /** Un tipo que no existe no se cuela: la validación lo para y no queda nada. */
    public function test_el_tipo_de_pedido_es_comer_aqui_o_para_llevar(): void
    {
        $turno = $this->turno();

        $this->vender([['producto_id' => $this->plato()->id, 'cantidad' => 1]],
            [['metodo_pago_id' => $this->metodo('EFECTIVO')]], ['tipo' => 'DELIVERY'])
            ->assertSessionHasErrors('tipo');

        $this->assertSame(0, Venta::where('sesion_caja_id', $turno->id)->count());
    }

    /** Dos ventas seguidas: el número sigue, y es uno solo para comer aquí y para llevar. */
    public function test_cada_venta_toma_el_numero_siguiente_de_la_jornada(): void
    {
        $turno = $this->turno();
        $efectivo = [['metodo_pago_id' => $this->metodo('EFECTIVO')]];

        $this->vender([['producto_id' => $this->plato()->id, 'cantidad' => 1]], $efectivo);
        $primero = $this->ultimaVenta($turno)->pedido;

        $this->vender([['producto_id' => $this->plato()->id, 'cantidad' => 1]], $efectivo, ['tipo' => Pedido::LLEVAR]);
        $segundo = $this->ultimaVenta($turno)->pedido;

        $this->assertSame($primero->numero_dia + 1, $segundo->numero_dia);
        $this->assertSame($primero->jornada->toDateString(), $segundo->jornada->toDateString());
    }

    // ------------------------------------------ lo que el mostrador ya hacía

    /** Descuento dentro del tope y dos formas de pago: todo llega a la venta del pedido. */
    public function test_descuento_y_pago_mixto(): void
    {
        $turno = $this->turno();
        $plato = $this->plato('P-0010');   // 5.75
        $bebida = $this->bebida();         // 5.75
        $bruto = round(2 * (float) $plato->precio_venta + (float) $bebida->precio_venta, 2);
        $descuento = 1.00;                 // < 10 % de 17.25
        $total = round($bruto - $descuento, 2);
        $conTarjeta = 10.00;

        $this->vender([
            ['producto_id' => $plato->id, 'cantidad' => 2],
            ['producto_id' => $bebida->id, 'cantidad' => 1],
        ], [
            ['metodo_pago_id' => $this->metodo('TARJETA'), 'monto' => number_format($conTarjeta, 2, '.', ''), 'referencia' => 'VOUCHER-81'],
            ['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto_recibido' => '20.00'],
        ], [
            'descuento' => number_format($descuento, 2, '.', ''),
            'total_esperado' => number_format($total, 2, '.', ''),
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $venta = $this->ultimaVenta($turno);

        $this->assertSame($total, (float) $venta->total);
        $this->assertSame('1.00', $venta->descuento);
        $this->assertSame(2, $venta->pagos()->count());
        $this->assertSame($conTarjeta, (float) $venta->pagos()->where('metodo_pago_id', $this->metodo('TARJETA'))->value('monto'));
        $this->assertSame(round($total - $conTarjeta, 2), (float) $venta->pagos()->where('metodo_pago_id', $this->metodo('EFECTIVO'))->value('monto'));
        $this->assertSame(Pedido::CERRADO, $venta->pedido->estado);
    }

    /** El tope de descuento del cajero sigue valiendo: por encima, no queda ni la venta ni el pedido. */
    public function test_el_tope_de_descuento_frena_la_venta_y_el_pedido(): void
    {
        $turno = $this->turno();
        $pedidosAntes = Pedido::count();

        $this->vender([['producto_id' => $this->plato('P-0010')->id, 'cantidad' => 2]],
            [['metodo_pago_id' => $this->metodo('EFECTIVO')]], ['descuento' => '5.00'])
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'supera el máximo'));

        $this->assertSame(0, Venta::where('sesion_caja_id', $turno->id)->count());
        $this->assertSame($pedidosAntes, Pedido::count());
    }

    /** El cobro por QR: el pedido se cobra con el QR pagado, atado a su venta. */
    public function test_se_cobra_con_qr(): void
    {
        $turno = $this->turno();
        $plato = $this->plato();
        $total = round(3 * (float) $plato->precio_venta, 2);
        $cobro = CobrosQr::confirmarAMano(CobrosQr::generar($turno, $this->cajero(), $total), $this->cajero());

        $this->vender([['producto_id' => $plato->id, 'cantidad' => 3]], [
            ['metodo_pago_id' => $this->metodo('QR'), 'cobro_qr_id' => $cobro->id],
        ], ['total_esperado' => number_format($total, 2, '.', '')])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $venta = $this->ultimaVenta($turno);

        $this->assertSame($venta->id, (int) $cobro->fresh()->venta_id);
        $this->assertSame(Pedido::CERRADO, $venta->pedido->estado);
        $this->assertContains($venta->pedido->detalle()->value('id'), $this->enLaCocina());
    }

    /**
     * Si el cobro falla —aquí, el total cambió mientras se cobraba—, no queda
     * nada: ni la venta, ni un pedido abierto huérfano, ni un número gastado.
     * El siguiente pedido toma el número que le tocaba a este.
     */
    public function test_si_el_cobro_falla_no_queda_ni_el_pedido_ni_el_numero(): void
    {
        $turno = $this->turno();
        $efectivo = [['metodo_pago_id' => $this->metodo('EFECTIVO')]];

        $this->vender([['producto_id' => $this->plato()->id, 'cantidad' => 1]], $efectivo);
        $anterior = $this->ultimaVenta($turno)->pedido;
        $pedidosAntes = Pedido::count();
        $lineasAntes = PedidoDetalle::count();

        $this->vender([['producto_id' => $this->plato()->id, 'cantidad' => 1, 'nota' => 'no debe llegar']],
            $efectivo, ['total_esperado' => '0.01'])
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'El total cambió'));

        $this->assertSame($pedidosAntes, Pedido::count(), 'quedó un pedido de una venta que no se registró');
        $this->assertSame($lineasAntes, PedidoDetalle::count());
        $this->assertNotContains('no debe llegar', PedidoDetalle::pluck('nota')->all());

        $this->vender([['producto_id' => $this->plato()->id, 'cantidad' => 1]], $efectivo);
        $this->assertSame($anterior->numero_dia + 1, $this->ultimaVenta($turno)->pedido->numero_dia);
    }

    /** La nota es para la cocina, no un campo libre sin límite. */
    public function test_la_nota_de_cada_plato_tiene_un_largo_maximo(): void
    {
        $this->turno();

        $this->vender([['producto_id' => $this->plato()->id, 'cantidad' => 1, 'nota' => str_repeat('x', 256)]],
            [['metodo_pago_id' => $this->metodo('EFECTIVO')]])
            ->assertSessionHasErrors('lineas.0.nota');
    }

    // ---------------------------------------------------- lo que no se cocina

    /**
     * Una bebida sola se cobra igual, pero no llega a la cocina: ni a su
     * pantalla ni —como nada que no pase por ella— a la comanda.
     */
    public function test_una_bebida_se_cobra_pero_no_va_a_la_cocina(): void
    {
        $turno = $this->turno();
        $bebida = $this->bebida();

        $this->vender([['producto_id' => $bebida->id, 'cantidad' => 2]], [['metodo_pago_id' => $this->metodo('EFECTIVO')]])
            ->assertSessionHasNoErrors();

        $venta = $this->ultimaVenta($turno);
        $linea = $venta->pedido->detalle()->firstOrFail();

        $this->assertSame(round(2 * (float) $bebida->precio_venta, 2), (float) $venta->total);
        $this->assertNotContains($linea->id, $this->enLaCocina());
        $this->assertSame(PedidoDetalle::ENTREGADO, $linea->estado_cocina);
    }

    /**
     * En un pedido abierto, lo que no pasa por la cocina se lee «Sin cocina»
     * y no «Pendiente»: no está esperando a nadie. Sigue PENDIENTE por dentro
     * a propósito, así que no cuenta como empezado (C1) y se puede cancelar.
     */
    public function test_en_un_pedido_abierto_la_bebida_dice_sin_cocina(): void
    {
        $cajero = $this->cajero();
        $pedido = Pedidos::abrir(Pedido::LOCAL, $cajero);
        $linea = Pedidos::agregarLinea($pedido, $this->bebida()->load('categoria'), 1, null, $cajero);

        $this->assertSame(PedidoDetalle::PENDIENTE, $linea->estado_cocina);
        $this->assertSame('Sin cocina', $linea->estado_visible);
        $this->assertSame(0, $pedido->fresh()->platosEmpezados());

        Pedidos::actualizarEstadoLinea($linea, PedidoDetalle::CANCELADO, $cajero);
        $this->assertSame(PedidoDetalle::CANCELADO, $linea->fresh()->estado_cocina);
    }

    /**
     * Lo que ya se pidió no cambia de destino porque alguien cambie la
     * categoría después: la línea copió si pasaba por la cocina al pedirse.
     */
    public function test_cambiar_la_categoria_no_mueve_lo_ya_pedido(): void
    {
        $turno = $this->turno();
        $plato = $this->plato();

        $this->vender([['producto_id' => $plato->id, 'cantidad' => 1]], [['metodo_pago_id' => $this->metodo('EFECTIVO')]]);
        $linea = $this->ultimaVenta($turno)->pedido->detalle()->firstOrFail();

        $plato->categoria->update(['pasa_por_cocina' => false]);

        $this->assertTrue($linea->fresh()->pasa_por_cocina);
        $this->assertContains($linea->id, $this->enLaCocina());
    }

    /** La marca se edita desde la pantalla de categorías; al crear, por omisión, sí pasa. */
    public function test_la_categoria_dice_si_pasa_por_la_cocina(): void
    {
        $admin = $this->usuario('admin');

        $this->assertFalse((bool) Categoria::where('nombre', 'Bebidas')->value('pasa_por_cocina'), 'las bebidas vienen sin cocina de fábrica');
        $this->assertTrue((bool) Categoria::where('nombre', 'Platos de fondo')->value('pasa_por_cocina'));

        $this->actingAs($admin)->get(route('categorias.index'))->assertOk()
            ->assertSee('Sus platos pasan por la cocina')
            ->assertSee('data-pasa-por-cocina="0"', false);

        $this->actingAs($admin)->post(route('categorias.store'), ['nombre' => 'Sopas'])->assertSessionHasNoErrors();
        $sopas = Categoria::where('nombre', 'Sopas')->firstOrFail();
        $this->assertTrue($sopas->pasa_por_cocina);

        $this->actingAs($admin)->put(route('categorias.update', $sopas), [
            'nombre' => 'Sopas', 'pasa_por_cocina' => 0, 'activo' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertFalse($sopas->fresh()->pasa_por_cocina);
        $this->assertDatabaseHas('auditoria', ['accion' => 'CATEGORIA_ACTUALIZADA', 'entidad_id' => $sopas->id]);

        // Sin el campo, se queda como estaba.
        $this->actingAs($admin)->put(route('categorias.update', $sopas), ['nombre' => 'Sopas del día'])->assertSessionHasNoErrors();
        $this->assertFalse($sopas->fresh()->pasa_por_cocina);
    }

    // ------------------------------------------------------------ la cocina

    /**
     * Cuando todo el pedido está listo se nota —es cuando se canta el número—,
     * y quien lo lleva lo entrega entero de un toque.
     */
    public function test_el_pedido_listo_se_destaca_y_se_entrega_de_un_toque(): void
    {
        $turno = $this->turno();
        $cocina = $this->usuario('cocina1');

        $this->vender([
            ['producto_id' => $this->plato()->id, 'cantidad' => 1],
            ['producto_id' => $this->plato('P-0010')->id, 'cantidad' => 1],
            ['producto_id' => $this->bebida()->id, 'cantidad' => 1],
        ], [['metodo_pago_id' => $this->metodo('EFECTIVO')]]);
        $pedido = $this->ultimaVenta($turno)->pedido;
        [$uno, $otro] = $pedido->detalle()->paraLaCocina()->orderBy('id')->get()->all();

        Pedidos::actualizarEstadoLinea($uno, PedidoDetalle::LISTO, $cocina);
        $tanda = fn () => collect($this->actingAs($cocina)->getJson(route('cocina.pendientes'))->json('tandas'))
            ->firstWhere('pedido_id', $pedido->id);

        $this->assertFalse($tanda()['listo'], 'con un plato por hacer, el pedido no está listo');
        $this->assertSame($pedido->numero_dia, $tanda()['numero']);

        Pedidos::actualizarEstadoLinea($otro, PedidoDetalle::LISTO, $cocina);
        $this->assertTrue($tanda()['listo']);

        $this->actingAs($cocina)->get(route('cocina.index'))->assertOk()
            ->assertSee('data-listo', false)
            ->assertSee("¡Listo! Canta el {$pedido->numero_dia}");

        $this->actingAs($cocina)->post(route('cocina.entregar', $pedido))->assertSessionHas('exito');

        $this->assertSame([PedidoDetalle::ENTREGADO, PedidoDetalle::ENTREGADO], [$uno->fresh()->estado_cocina, $otro->fresh()->estado_cocina]);
        $this->assertNull($tanda(), 'el pedido entregado sigue en la pantalla');

        // El cajero también entrega (`cocina.entregar`); aquí ya no queda nada.
        $this->actingAs($this->cajero())->post(route('cocina.entregar', $pedido))
            ->assertSessionHas('exito', "{$pedido->numero_visible} no tenía nada listo para entregar.");
    }

    // ------------------------------------------------------- la corrección

    /**
     * Se cobró el pedido con la forma de pago equivocada y se anula para
     * rehacer. El cliente ya tiene su ticket con el número y la cocina ya lo
     * está cocinando: el pedido queda para volver a cobrar CON SU NÚMERO y sus
     * platos siguen en la cocina. Se cobra de nuevo y el ticket nuevo lleva el
     * mismo número. Nada de un «pedido 8» con la comanda repetida.
     */
    public function test_anular_una_venta_de_mostrador_deja_el_pedido_por_cobrar_con_su_numero(): void
    {
        $turno = $this->turno();
        $admin = $this->usuario('admin');
        $plato = $this->plato();

        $this->vender([['producto_id' => $plato->id, 'cantidad' => 2, 'nota' => 'bien cocido']],
            [['metodo_pago_id' => $this->metodo('EFECTIVO')]]);
        $venta = $this->ultimaVenta($turno);
        $pedido = $venta->pedido;
        $linea = $pedido->detalle()->firstOrFail();
        Pedidos::actualizarEstadoLinea($linea, PedidoDetalle::EN_PREPARACION, $this->usuario('cocina1'));

        $this->actingAs($admin)->post(route('ventas.anular', $venta), [
            'motivo_anulacion' => 'Pagó con tarjeta, se cobró en efectivo',
        ])->assertSessionHas('exito');

        $pedido = $pedido->fresh();
        $this->assertSame(Pedido::ABIERTO, $pedido->estado);
        $this->assertNull($pedido->venta);
        $this->assertSame($pedido->id, (int) $venta->fresh()->pedido_id, 'la venta anulada sigue diciendo de qué pedido era');
        $this->assertContains($linea->id, $this->enLaCocina(), 'el plato salió de la cocina al anular');

        // Se vuelve a cobrar, ahora bien, y el ticket nuevo lleva el mismo número.
        $total = Pedidos::totalesDe($pedido)['total'];
        $this->actingAs($this->cajero())->post(route('pedidos.cobrar.store', $pedido), [
            'total_esperado' => number_format($total, 2, '.', ''),
            'pagos' => [['metodo_pago_id' => $this->metodo('TARJETA'), 'referencia' => 'VOUCHER-9']],
        ])->assertSessionHasNoErrors()->assertRedirectContains('/ventas/');

        $nueva = $pedido->fresh()->venta;
        $this->assertNotSame($venta->id, $nueva->id);
        $this->assertSame(Pedido::CERRADO, $pedido->fresh()->estado);

        $ticket = $this->actingAs($this->cajero())->get(route('comprobantes.imprimir', $nueva->comprobante))->getContent();
        $this->assertStringContainsString("PEDIDO #{$pedido->numero_dia} COMER AQUÍ", $this->texto($ticket));
        $this->assertSame(1, Pedido::where('jornada', $pedido->jornada)->where('numero_dia', $pedido->numero_dia)->count());
        $this->assertContains($linea->id, $this->enLaCocina(), 'cobrar de nuevo no saca el plato de la cocina');
    }

    /**
     * El pedido reabierto también se cancela, con las reglas de siempre (C1):
     * con platos empezados, solo quien puede anular, y con motivo.
     */
    public function test_el_pedido_reabierto_se_cancela_con_las_reglas_de_c1(): void
    {
        $turno = $this->turno();
        $admin = $this->usuario('admin');

        $this->vender([['producto_id' => $this->plato()->id, 'cantidad' => 1]], [['metodo_pago_id' => $this->metodo('EFECTIVO')]]);
        $venta = $this->ultimaVenta($turno);
        $pedido = $venta->pedido;
        Pedidos::actualizarEstadoLinea($pedido->detalle()->firstOrFail(), PedidoDetalle::EN_PREPARACION, $this->usuario('cocina1'));
        Ventas::anular($venta, $admin, 'Se cobró dos veces');

        // El cajero toma pedidos pero no anula: con un plato empezado, no la cancela.
        $this->actingAs($this->cajero())->post(route('pedidos.cancelar', $pedido), ['motivo' => 'El cliente se fue'])
            ->assertSessionHas('error');
        $this->assertTrue($pedido->fresh()->estaAbierto());

        $this->actingAs($admin)->post(route('pedidos.cancelar', $pedido), ['motivo' => ''])->assertSessionHasErrors('motivo');
        $this->actingAs($admin)->post(route('pedidos.cancelar', $pedido), ['motivo' => 'El cliente se fue sin esperar'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Pedido::CANCELADO, $pedido->fresh()->estado);
        $this->assertNotContains($pedido->detalle()->value('id'), $this->enLaCocina());
    }

    /**
     * Todo el dinero entra por aquí. Si la red va lenta y el navegador reenvía
     * el cobro, o el cajero vuelve atrás y lo manda otra vez, tiene que quedar
     * un solo pedido: si no, salen dos comandas y la cocina cocina dos veces.
     * La bandera `enviando` del navegador no alcanza para eso; el número de
     * envío lo frena en el servidor.
     */
    public function test_un_doble_envio_del_mostrador_cobra_una_sola_vez(): void
    {
        $turno = $this->turno();
        $antes = (int) Pedido::max('id');
        $linea = [['producto_id' => $this->plato()->id, 'cantidad' => 1]];
        $pago = [['metodo_pago_id' => $this->metodo('EFECTIVO')]];
        $envio = ['_envio' => (string) Str::uuid()];

        $this->vender($linea, $pago, $envio)->assertSessionHasNoErrors();
        $this->vender($linea, $pago, $envio)->assertSessionHas('aviso');

        $this->assertSame(1, Venta::where('sesion_caja_id', $turno->id)->count());
        $this->assertSame(1, Pedido::where('id', '>', $antes)->count());

        // Y no frena de más: la venta siguiente, con su propio número, entra.
        $this->vender($linea, $pago, ['_envio' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame(2, Venta::where('sesion_caja_id', $turno->id)->count());
    }
}
