<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\Producto;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\Pedidos;
use App\Services\ReglasEnPhp;
use App\Services\Ventas;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * El pedido y sus reglas: sus líneas, su cancelación y quién puede qué.
 *
 * Los pedidos se toman y se cobran en el mostrador (`VentaDeMostradorTest`);
 * el cobro en sí tiene su suite (`CobroDePedidoTest`). Aquí queda lo propio
 * del pedido, y el camino de corrección: el pedido que se reabre al anular su
 * venta queda con el cobro anulado y se cobra de nuevo o se cancela.
 */
class PedidosTest extends TestCase
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

    private function turnoDelCajero(): void
    {
        Cajas::sesionDe($this->cajero()) ?? Cajas::abrir(Caja::firstOrFail(), $this->cajero(), 100);
    }

    private function plato(array $excluir = []): Producto
    {
        // De una categoría que pasa por la cocina: las reglas de cancelar y
        // de «empezado» son las de la cocina.
        return Producto::activos()->whereNotIn('id', $excluir)
            ->whereHas('categoria', fn ($q) => $q->where('pasa_por_cocina', true))
            ->orderBy('id')->firstOrFail();
    }

    /** Un pedido abierto, como el que queda al anular su venta. */
    private function pedidoAbierto(int $platos = 1): Pedido
    {
        $cajero = $this->cajero();
        $pedido = Pedidos::abrir(Pedido::LOCAL, $cajero);

        for ($i = 0; $i < $platos; $i++) {
            Pedidos::agregarLinea($pedido, $this->plato()->load('categoria'), 1, null, $cajero);
        }

        return $pedido->fresh();
    }

    /** Un pedido cobrado en el mostrador y con su venta anulada: queda para volver a cobrar. */
    private function pedidoPorCobrar(): Pedido
    {
        $cajero = $this->cajero();
        $turno = Cajas::sesionDe($cajero) ?? Cajas::abrir(Caja::firstOrFail(), $cajero, 100);

        $venta = Pedidos::venderEnMostrador($turno->fresh(), $cajero, [
            ['producto_id' => $this->plato()->id, 'cantidad' => 1],
        ], [['metodo_pago_id' => (int) MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]]);
        $pedido = $venta->pedido;

        Ventas::anular($venta->fresh(), $this->usuario('admin'), 'Se cobró con la forma de pago equivocada');

        return $pedido->fresh();
    }

    // -------------------------------------------------------------- permisos

    /** La cocina solo mira lo suyo: ni vende, ni cobra, ni cancela pedidos. */
    public function test_la_cocina_no_vende_ni_cobra_ni_cancela_pedidos(): void
    {
        $cocina = $this->usuario('cocina1');
        $pedido = $this->pedidoAbierto();

        $this->actingAs($cocina)->get(route('pos.index'))->assertForbidden();
        $this->actingAs($cocina)->post(route('pos.store'), [])->assertForbidden();
        $this->actingAs($cocina)->get(route('pedidos.cobrar', $pedido))->assertForbidden();
        $this->actingAs($cocina)->post(route('pedidos.cobrar.store', $pedido), [])->assertForbidden();
        $this->actingAs($cocina)->post(route('pedidos.cancelar', $pedido), ['motivo' => 'Prueba de permiso'])->assertForbidden();

        $this->assertTrue($pedido->fresh()->estaAbierto());
    }

    /** El cajero toma los pedidos (en el mostrador) y es quien los corrige, pero no anula. */
    public function test_el_cajero_toma_y_corrige_los_pedidos(): void
    {
        $cajero = $this->cajero();

        $this->assertTrue($cajero->tienePermiso('pedidos.registrar'));
        $this->assertTrue($cajero->tienePermiso('ventas.registrar'));
        $this->assertFalse($cajero->tienePermiso('ventas.anular'));
    }

    // ------------------------------------------------------------ los tipos

    /** Comer aquí y para llevar comparten el contador y no chocan entre sí. */
    public function test_varios_pedidos_conviven_para_comer_aqui_y_para_llevar(): void
    {
        $cajero = $this->cajero();

        $a = Pedidos::abrir(Pedido::LOCAL, $cajero);
        $b = Pedidos::abrir(Pedido::LOCAL, $cajero);
        $c = Pedidos::abrir(Pedido::LLEVAR, $cajero, nombreCliente: 'Beto');

        $this->assertSame([$a->numero_dia + 1, $a->numero_dia + 2], [$b->numero_dia, $c->numero_dia]);
        $this->assertSame(['Comer aquí', 'Para llevar'], [$a->destino, $c->destino]);
    }

    /** El tipo es comer aquí o para llevar: la mesa se fue con el módulo de mesas. */
    public function test_el_tipo_es_comer_aqui_o_para_llevar(): void
    {
        $this->assertSame([Pedido::LOCAL, Pedido::LLEVAR], Pedido::TIPOS);
        $this->assertThrows(fn () => Pedidos::abrir('MESA', $this->cajero()), RuntimeException::class);
    }

    // ------------------------------------------------------------ las líneas

    /** El precio se copia al pedir: si el menú sube, el pedido conserva el que se cantó. */
    public function test_la_linea_copia_el_nombre_y_el_precio_del_momento(): void
    {
        $cajero = $this->cajero();
        $plato = $this->plato();
        $precio = (float) $plato->precio_venta;

        $pedido = Pedidos::abrir(Pedido::LOCAL, $cajero);
        $linea = Pedidos::agregarLinea($pedido, $plato, 2, 'bien cocido', $cajero);

        $plato->forceFill(['precio_venta' => $precio + 5, 'nombre' => $plato->nombre.' (otro)'])->save();

        $linea = $linea->fresh();
        $this->assertSame(round($precio, 2), round((float) $linea->precio_unitario, 2));
        $this->assertStringNotContainsString('(otro)', $linea->descripcion);
        $this->assertSame('bien cocido', $linea->nota);
        $this->assertSame(PedidoDetalle::PENDIENTE, $linea->estado_cocina);
    }

    /** Todo se sirve por porción: media hamburguesa no existe, tampoco en el mostrador. */
    public function test_la_cantidad_va_por_porcion_entera(): void
    {
        $cajero = $this->cajero();
        $pedido = Pedidos::abrir(Pedido::LOCAL, $cajero);

        $this->assertThrows(
            fn () => Pedidos::agregarLinea($pedido, $this->plato(), 1.5, null, $cajero),
            RuntimeException::class,
        );

        $this->turnoDelCajero();
        $pedidosAntes = Pedido::count();

        $this->actingAs($cajero)->post(route('pos.store'), [
            'lineas' => [['producto_id' => $this->plato()->id, 'cantidad' => '1.5']],
            'pagos' => [['metodo_pago_id' => (int) MetodoPago::where('codigo', 'EFECTIVO')->value('id')]],
        ])->assertSessionHas('error', fn (string $m) => str_contains($m, 'porción entera'));

        $this->assertSame($pedidosAntes, Pedido::count());
    }

    /** Dos porciones del mismo plato con notas distintas son dos líneas. */
    public function test_el_mismo_plato_con_otra_nota_es_otra_linea(): void
    {
        $cajero = $this->cajero();
        $plato = $this->plato();
        $pedido = Pedidos::abrir(Pedido::LOCAL, $cajero);

        Pedidos::agregarLinea($pedido, $plato, 2, null, $cajero);
        Pedidos::agregarLinea($pedido, $plato, 1, 'sin picante', $cajero);

        $this->assertSame(2, $pedido->detalle()->where('producto_id', $plato->id)->count());
    }

    /**
     * Lo que la cocina empezó se cancela, no se borra: no se le cobra al
     * cliente, pero queda escrito que existió. Lo hace un administrador, con
     * su motivo, desde el pedido con el cobro anulado (C1).
     */
    public function test_un_plato_empezado_se_cancela_y_queda_el_rastro(): void
    {
        $pedido = $this->pedidoAbierto();
        $linea = $pedido->detalle()->firstOrFail();
        Pedidos::actualizarEstadoLinea($linea, PedidoDetalle::EN_PREPARACION, $this->usuario('cocina1'));

        $this->actingAs($this->usuario('admin'))->post(route('pedidos.lineas.cancelar', [$pedido, $linea]), ['motivo' => 'El cliente cambió de plato'])
            ->assertSessionHasNoErrors()->assertSessionHas('exito');

        $this->assertSame(PedidoDetalle::CANCELADO, $linea->fresh()->estado_cocina);
        $this->assertNotNull(PedidoDetalle::find($linea->id));
    }

    /** Una línea de otro pedido no se toca desde el pedido equivocado. */
    public function test_una_linea_de_otro_pedido_no_se_cancela_desde_aqui(): void
    {
        $uno = $this->pedidoAbierto();
        $otro = $this->pedidoAbierto();
        $linea = $otro->detalle()->firstOrFail();

        $this->actingAs($this->cajero())->post(route('pedidos.lineas.cancelar', [$uno, $linea]))->assertNotFound();
        $this->assertSame(PedidoDetalle::PENDIENTE, $linea->fresh()->estado_cocina);
    }

    // ------------------------------------------------------------ cancelación

    /** Cancelar un pedido con el cobro anulado lo cierra sin cobrar, con su motivo y su rastro. */
    public function test_cancelar_un_pedido_por_cobrar(): void
    {
        $pedido = $this->pedidoAbierto();

        $this->actingAs($this->cajero())->post(route('pedidos.cancelar', $pedido), [
            'motivo' => 'El cliente se fue sin esperar',
        ])->assertRedirect(route('pos.index'))->assertSessionHasNoErrors();

        $pedido = $pedido->fresh();
        $this->assertSame(Pedido::CANCELADO, $pedido->estado);
        $this->assertNotNull($pedido->fecha_cierre);
        $this->assertDatabaseHas('auditoria', ['accion' => 'PEDIDO_CANCELADO', 'entidad_id' => $pedido->id]);
    }

    public function test_cancelar_exige_motivo(): void
    {
        $pedido = $this->pedidoAbierto();

        $this->actingAs($this->cajero())->post(route('pedidos.cancelar', $pedido), ['motivo' => ''])
            ->assertSessionHasErrors('motivo');
        $this->assertTrue($pedido->fresh()->estaAbierto());
    }

    /**
     * Un pedido con algo que la cocina ya empezó, terminó o sirvió no lo
     * cancela el cajero: sería hacer desaparecer lo consumido sin cobrarlo,
     * que es la misma pérdida que anular una venta. Pide el permiso de anular
     * y un motivo (C1). El cajero tiene `pedidos.registrar` pero no
     * `ventas.anular`: es justo el caso que la regla cubre.
     */
    public function test_un_pedido_con_platos_empezados_solo_lo_cancela_quien_puede_anular(): void
    {
        $cajero = $this->cajero();
        $cocina = $this->usuario('cocina1');
        $admin = $this->usuario('admin');
        $this->turnoDelCajero();
        Cajas::sesionDe($admin) ?? Cajas::abrir(Caja::create(['nombre' => 'Caja de la prueba', 'activo' => 1]), $admin, 50);

        foreach ([PedidoDetalle::EN_PREPARACION, PedidoDetalle::LISTO, PedidoDetalle::ENTREGADO] as $paso) {
            $pedido = $this->pedidoAbierto(2);
            $linea = $pedido->detalle()->orderBy('id')->firstOrFail();

            foreach ([PedidoDetalle::EN_PREPARACION, PedidoDetalle::LISTO, PedidoDetalle::ENTREGADO] as $avance) {
                Pedidos::actualizarEstadoLinea($linea->fresh(), $avance, $cocina);

                if ($avance === $paso) {
                    break;
                }
            }

            // El servicio lo rechaza por sí mismo, no solo la pantalla.
            $this->assertThrows(
                fn () => Pedidos::cancelar($pedido->fresh(), $cajero, 'El cliente se fue'),
                RuntimeException::class,
                'administrador',
            );

            $this->actingAs($cajero)
                ->post(route('pedidos.cancelar', $pedido), ['motivo' => 'El cliente se fue'])
                ->assertSessionHas('error');

            // Y la pantalla para volver a cobrar el pedido no le ofrece el botón.
            $this->actingAs($cajero)->get(route('pedidos.cobrar', $pedido))->assertOk()
                ->assertDontSee(route('pedidos.cancelar', $pedido))
                ->assertSee('solo lo cancela un administrador');

            $this->assertTrue($pedido->fresh()->estaAbierto(), "con un plato {$paso} el pedido no debía cancelarse");

            // El administrador sí, y el motivo sigue siendo obligatorio.
            $this->actingAs($admin)->get(route('pedidos.cobrar', $pedido))->assertOk()
                ->assertSee(route('pedidos.cancelar', $pedido));
            $this->actingAs($admin)->post(route('pedidos.cancelar', $pedido), ['motivo' => ''])
                ->assertSessionHasErrors('motivo');
            $this->assertThrows(fn () => Pedidos::cancelar($pedido->fresh(), $admin, '  '), RuntimeException::class);

            $this->actingAs($admin)->post(route('pedidos.cancelar', $pedido), [
                'motivo' => 'Plato mal preparado, la casa invita',
            ])->assertRedirect(route('pos.index'));

            $this->assertSame(Pedido::CANCELADO, $pedido->fresh()->estado);

            $rastro = json_decode((string) DB::table('auditoria')
                ->where('accion', 'PEDIDO_CANCELADO')->where('entidad_id', $pedido->id)->value('detalle'), true);
            $this->assertSame(1, $rastro['platos_empezados']);
            $this->assertSame('Plato mal preparado, la casa invita', $rastro['motivo']);
        }
    }

    /** Lo que la cocina no tocó —pendiente o ya cancelado— no se perdió: el cajero cancela como siempre. */
    public function test_un_pedido_con_todo_pendiente_lo_cancela_el_cajero(): void
    {
        $cajero = $this->cajero();
        $this->turnoDelCajero();
        $pedido = $this->pedidoAbierto(2);
        $cancelada = $pedido->detalle()->orderByDesc('id')->firstOrFail();
        Pedidos::actualizarEstadoLinea($cancelada, PedidoDetalle::CANCELADO, $cajero);

        $this->actingAs($cajero)->get(route('pedidos.cobrar', $pedido))->assertOk()
            ->assertSee(route('pedidos.cancelar', $pedido));

        $this->actingAs($cajero)->post(route('pedidos.cancelar', $pedido), ['motivo' => 'El cliente se fue'])
            ->assertRedirect(route('pos.index'));

        $this->assertSame(Pedido::CANCELADO, $pedido->fresh()->estado);
    }

    /**
     * El atajo que dejaba abierto C1: el cajero cancelaba primero el plato que
     * la cocina ya estaba preparando —que así dejaba de contar como empezado—
     * y después el pedido entero, sin administrador. Un plato en preparación
     * lo cancela solo quien puede anular, y con su motivo; el servicio lo
     * exige por sí mismo, no solo la pantalla.
     */
    public function test_un_plato_en_preparacion_solo_lo_cancela_quien_puede_anular_y_con_motivo(): void
    {
        $cajero = $this->cajero();
        $admin = $this->usuario('admin');
        $this->turnoDelCajero();
        Cajas::sesionDe($admin) ?? Cajas::abrir(Caja::create(['nombre' => 'Caja de la prueba', 'activo' => 1]), $admin, 50);
        $pedido = $this->pedidoAbierto(2);
        $empezada = $pedido->detalle()->orderByDesc('id')->firstOrFail();
        Pedidos::actualizarEstadoLinea($empezada, PedidoDetalle::EN_PREPARACION, $this->usuario('cocina1'));

        $this->assertThrows(
            fn () => Pedidos::actualizarEstadoLinea($empezada->fresh(), PedidoDetalle::CANCELADO, $cajero, 'El cliente ya no quiere'),
            RuntimeException::class,
            'administrador',
        );
        $this->actingAs($cajero)->post(route('pedidos.lineas.cancelar', [$pedido, $empezada]), ['motivo' => 'Ya no lo quiere'])
            ->assertSessionHas('error');
        $this->assertSame(PedidoDetalle::EN_PREPARACION, $empezada->fresh()->estado_cocina);

        // Y el pedido entero sigue sin poder cancelarlo él.
        $this->assertThrows(fn () => Pedidos::cancelar($pedido->fresh(), $cajero, 'El cliente se fue'), RuntimeException::class);

        // La pantalla no le ofrece el botón; al administrador, sí, con motivo.
        $this->actingAs($cajero)->get(route('pedidos.cobrar', $pedido))->assertOk()
            ->assertSee('Ya se prepara: solo lo cancela un administrador.')
            ->assertDontSee('data-cancelar-empezado', false);
        $this->actingAs($admin)->get(route('pedidos.cobrar', $pedido))->assertOk()
            ->assertSee('data-cancelar-empezado', false);

        // El administrador, sin motivo, tampoco.
        $this->assertThrows(
            fn () => Pedidos::actualizarEstadoLinea($empezada->fresh(), PedidoDetalle::CANCELADO, $admin, '  '),
            RuntimeException::class,
            'por qué',
        );

        $this->actingAs($admin)->post(route('pedidos.lineas.cancelar', [$pedido, $empezada]), ['motivo' => 'Se quemó, la casa lo repone'])
            ->assertSessionHas('exito');
        $this->assertSame(PedidoDetalle::CANCELADO, $empezada->fresh()->estado_cocina);

        $rastro = json_decode((string) DB::table('auditoria')
            ->where('accion', 'PEDIDO_LINEA_ESTADO')->where('entidad_id', $empezada->id)
            ->orderByDesc('id')->value('detalle'), true);
        $this->assertSame('Se quemó, la casa lo repone', $rastro['motivo']);
        $this->assertSame(PedidoDetalle::EN_PREPARACION, $rastro['de']);
    }

    /** Un plato que nadie empezó lo cancela la caja; la cocina, en cambio, no cancela nada. */
    public function test_la_cocina_no_cancela_platos_ni_por_el_servicio(): void
    {
        $this->turnoDelCajero();
        $pedido = $this->pedidoAbierto(1);
        $linea = $pedido->detalle()->firstOrFail();

        $this->assertThrows(
            fn () => Pedidos::actualizarEstadoLinea($linea, PedidoDetalle::CANCELADO, $this->usuario('cocina1')),
            RuntimeException::class,
            'caja',
        );
        $this->assertSame(PedidoDetalle::PENDIENTE, $linea->fresh()->estado_cocina);

        Pedidos::actualizarEstadoLinea($linea->fresh(), PedidoDetalle::CANCELADO, $this->cajero());
        $this->assertSame(PedidoDetalle::CANCELADO, $linea->fresh()->estado_cocina);
    }

    /** Un pedido cerrado no admite más platos ni se cancela dos veces. */
    public function test_un_pedido_cerrado_ya_no_se_toca(): void
    {
        $cajero = $this->cajero();
        $pedido = Pedidos::abrir(Pedido::LOCAL, $cajero);
        Pedidos::cancelar($pedido, $cajero, 'Prueba');

        $this->assertThrows(
            fn () => Pedidos::agregarLinea($pedido->fresh(), $this->plato(), 1, null, $cajero),
            RuntimeException::class,
        );
        $this->assertThrows(
            fn () => Pedidos::cancelar($pedido->fresh(), $cajero, 'Otra vez'),
            RuntimeException::class,
        );
    }

    /**
     * Los pedidos no se borran: se cancelan. Lo impide el trigger
     * `trg_pedidos_before_delete`, y se prueba borrando de verdad —por la base,
     * que es por donde un script o una consola se lo saltaría.
     */
    public function test_un_pedido_no_se_elimina(): void
    {
        if (ReglasEnPhp::activa()) {
            $this->markTestSkipped('Sin triggers no hay quién lo impida en la base: la aplicación no ofrece borrar un pedido.');
        }

        $pedido = $this->pedidoAbierto();

        $this->assertThrows(
            fn () => DB::table('pedidos')->where('id', $pedido->id)->delete(),
            QueryException::class,
            'Los pedidos no se eliminan',
        );
        $this->assertThrows(fn () => $pedido->fresh()->delete(), QueryException::class, 'Los pedidos no se eliminan');

        $this->assertSame(Pedido::ABIERTO, $pedido->fresh()->estado);
        $this->assertSame(1, $pedido->detalle()->count());
    }

    // ---------------------------------------------------- volver a cobrar

    /**
     * El punto de venta lista los pedidos para volver a cobrar —los de venta anulada—
     * con el camino para cobrarlos o cancelarlos. Sin ninguno, no aparece nada.
     */
    public function test_el_punto_de_venta_lista_los_pedidos_por_cobrar(): void
    {
        $cajero = $this->cajero();
        $this->turnoDelCajero();

        if (Pedido::abiertos()->doesntExist()) {
            $this->actingAs($cajero)->get(route('pos.index'))->assertOk()
                ->assertDontSee('data-pedidos-por-cobrar', false);
        }

        $pedido = $this->pedidoPorCobrar();

        $this->assertTrue($pedido->estaAbierto());
        $this->actingAs($cajero)->get(route('pos.index'))->assertOk()
            ->assertSee('data-pedidos-por-cobrar', false)
            ->assertSee('Volver a cobrar')
            ->assertDontSee('Pedidos por cobrar')
            ->assertSee($pedido->etiqueta)
            ->assertSee(route('pedidos.cobrar', $pedido), false);

        $this->actingAs($cajero)->get(route('pedidos.cobrar', $pedido))->assertOk()
            ->assertSee('data-platos-del-pedido', false)
            ->assertSee(route('pos.index'), false);
    }

    /** Un pedido ya cobrado o cancelado no tiene pantalla de cobro: vuelve al mostrador. */
    public function test_un_pedido_cerrado_no_se_cobra_desde_su_pantalla(): void
    {
        $cajero = $this->cajero();
        $this->turnoDelCajero();
        $pedido = Pedidos::abrir(Pedido::LOCAL, $cajero);
        Pedidos::cancelar($pedido, $cajero, 'Prueba');

        $this->actingAs($cajero)->get(route('pedidos.cobrar', $pedido))
            ->assertRedirect(route('pos.index'))->assertSessionHas('error');
    }
}
