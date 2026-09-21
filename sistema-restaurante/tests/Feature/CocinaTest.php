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
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

/**
 * La pantalla de la cocina: qué ve, qué puede mover y qué no le toca.
 */
class CocinaTest extends TestCase
{
    use DatabaseTransactions;

    private function usuario(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    private function plato(): Producto
    {
        return Producto::activos()->orderBy('id')->firstOrFail();
    }

    private function lineaPendiente(?string $nota = null): PedidoDetalle
    {
        $cajero = $this->usuario('cajero1');
        $pedido = Pedidos::abrir(Pedido::LOCAL, $cajero);

        return Pedidos::agregarLinea($pedido, $this->plato(), 2, $nota, $cajero);
    }

    // -------------------------------------------------------------- permisos

    public function test_la_cocina_entra_a_su_pantalla_y_a_nada_de_dinero(): void
    {
        $cocina = $this->usuario('cocina1');

        $this->actingAs($cocina)->get(route('cocina.index'))->assertOk();
        $this->actingAs($cocina)->getJson(route('cocina.pendientes'))->assertOk();

        foreach ([
            route('pos.index'), route('caja.index'), route('ventas.index'),
            route('comprobantes.index'), route('reportes.ventas'),
        ] as $url) {
            $this->actingAs($cocina)->get($url)->assertForbidden();
        }
    }

    /** Quien vende y toma pedidos no necesita la pantalla de la cocina. */
    /** El cajero entra a la cocina para entregar (ver EntregaDesdeElMostradorTest). */
    public function test_el_cajero_entra_a_la_cocina(): void
    {
        $this->actingAs($this->usuario('cajero1'))->get(route('cocina.index'))->assertOk();
        $this->actingAs($this->usuario('cajero1'))->getJson(route('cocina.pendientes'))->assertOk();
    }

    // ---------------------------------------------------------- lo que se ve

    /** La hora y la nota son lo que la cocina mira: sin ellas se equivoca el plato. */
    public function test_la_pantalla_muestra_la_hora_y_la_nota_de_cada_plato(): void
    {
        $linea = $this->lineaPendiente('sin cebolla, por favor');

        $this->actingAs($this->usuario('cocina1'))->get(route('cocina.index'))->assertOk()
            ->assertSee($linea->descripcion)
            ->assertSee('sin cebolla, por favor')
            ->assertSee($linea->fresh()->creado_en->format('H:i'));
    }

    /** El sondeo de la pantalla: el mismo listado, en JSON. */
    public function test_el_json_del_sondeo_trae_las_lineas_con_su_estado(): void
    {
        $linea = $this->lineaPendiente('término medio');

        $this->actingAs($this->usuario('cocina1'))->getJson(route('cocina.pendientes'))
            ->assertOk()
            ->assertJsonStructure(['actualizado', 'tandas' => [['pedido_id', 'etiqueta', 'lineas' => [['id', 'descripcion', 'cantidad', 'nota', 'estado', 'siguiente', 'hora', 'minutos']]]]])
            ->assertJsonFragment([
                'id' => $linea->id,
                'nota' => 'término medio',
                'estado' => PedidoDetalle::PENDIENTE,
                'siguiente' => PedidoDetalle::EN_PREPARACION,
            ]);
    }

    /** Lo entregado y lo cancelado ya no son trabajo de la cocina. */
    public function test_la_pantalla_no_muestra_lo_ya_entregado_ni_lo_cancelado(): void
    {
        $cocina = $this->usuario('cocina1');
        $entregada = $this->lineaPendiente();

        foreach ([PedidoDetalle::EN_PREPARACION, PedidoDetalle::LISTO, PedidoDetalle::ENTREGADO] as $paso) {
            Pedidos::actualizarEstadoLinea($entregada->fresh(), $paso, $cocina);
        }

        // Cancelar lo decide la caja, no la cocina (C1).
        $cancelada = $this->lineaPendiente();
        Pedidos::actualizarEstadoLinea($cancelada, PedidoDetalle::CANCELADO, $this->usuario('cajero1'));

        $respuesta = $this->actingAs($cocina)->getJson(route('cocina.pendientes'))->assertOk();
        $ids = collect($respuesta->json('tandas'))->flatMap(fn ($t) => collect($t['lineas'])->pluck('id'))->all();

        $this->assertNotContains($entregada->id, $ids);
        $this->assertNotContains($cancelada->id, $ids);
    }

    /** Un pedido cancelado no aparece, aunque le queden líneas a medias: nadie las va a comer. */
    public function test_la_pantalla_no_muestra_los_pedidos_cancelados(): void
    {
        $cajero = $this->usuario('cajero1');
        $pedido = Pedidos::abrir(Pedido::LOCAL, $cajero);
        $linea = Pedidos::agregarLinea($pedido, $this->plato(), 1, null, $cajero);
        Pedidos::cancelar($pedido, $cajero, 'El cliente se fue');

        $respuesta = $this->actingAs($this->usuario('cocina1'))->getJson(route('cocina.pendientes'))->assertOk();
        $ids = collect($respuesta->json('tandas'))->flatMap(fn ($t) => collect($t['lineas'])->pluck('id'))->all();

        $this->assertNotContains($linea->id, $ids);
    }

    /**
     * Cobrar no es servir. El pedido del mostrador se cobra al pedirlo: sus
     * platos siguen siendo trabajo de la cocina hasta que se entregan. Antes
     * desaparecían de la pantalla en el instante del cobro.
     */
    public function test_cobrar_el_pedido_no_saca_de_la_cocina_lo_que_falta_preparar(): void
    {
        $admin = $this->usuario('admin');
        $cocina = $this->usuario('cocina1');
        $turno = Cajas::sesionDe($admin) ?? Cajas::abrir(Caja::firstOrFail(), $admin, 100);

        $llevar = Pedidos::abrir(Pedido::LLEVAR, $admin, nombreCliente: 'Ana');
        $pendiente = Pedidos::agregarLinea($llevar, $this->plato(), 1, 'sin ají', $admin);

        $local = Pedidos::abrir(Pedido::LOCAL, $this->usuario('cajero1'));
        $servido = Pedidos::agregarLinea($local, $this->plato(), 1, null, $this->usuario('cajero1'));
        $postre = Pedidos::agregarLinea($local, $this->plato(), 1, 'el postre', $this->usuario('cajero1'));
        foreach ([PedidoDetalle::EN_PREPARACION, PedidoDetalle::LISTO, PedidoDetalle::ENTREGADO] as $paso) {
            Pedidos::actualizarEstadoLinea($servido->fresh(), $paso, $cocina);
        }

        $efectivo = [['metodo_pago_id' => (int) MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]];
        Pedidos::cobrar($llevar, $turno, $admin, $efectivo);
        Pedidos::cobrar($local, $turno->fresh(), $admin, $efectivo);

        $ids = fn () => collect($this->actingAs($cocina)->getJson(route('cocina.pendientes'))->assertOk()->json('tandas'))
            ->flatMap(fn ($t) => collect($t['lineas'])->pluck('id'))->all();

        $this->assertContains($pendiente->id, $ids());
        $this->assertContains($postre->id, $ids());
        $this->assertNotContains($servido->id, $ids());
        $this->actingAs($cocina)->get(route('cocina.index'))->assertOk()->assertSee('sin ají');

        // Y la cocina lo sigue avanzando hasta entregarlo.
        foreach ([PedidoDetalle::EN_PREPARACION, PedidoDetalle::LISTO, PedidoDetalle::ENTREGADO] as $paso) {
            $this->actingAs($cocina)->post(route('cocina.estado', $pendiente), ['estado' => $paso])
                ->assertSessionHas('exito');
            $this->assertSame($paso, $pendiente->fresh()->estado_cocina);
        }
        $this->assertNotContains($pendiente->id, $ids());

        // Lo que no se puede es cancelarlo: ya se cobró, y cancelar no devuelve
        // el dinero. Si no se va a servir, se anula la venta. Ni la cocina
        // —su ruta ya no admite cancelar— ni la caja.
        $this->actingAs($cocina)->post(route('cocina.estado', $postre), ['estado' => PedidoDetalle::CANCELADO])
            ->assertSessionHasErrors('estado');
        $this->assertThrows(
            fn () => Pedidos::actualizarEstadoLinea($postre->fresh(), PedidoDetalle::CANCELADO, $admin),
            RuntimeException::class,
        );
        $this->assertSame(PedidoDetalle::PENDIENTE, $postre->fresh()->estado_cocina);
    }

    /**
     * La cocina ve la jornada en curso y nada más. Un plato que nadie marcó
     * como entregado —el de un pedido de ayer, ya cobrado— se quedaba en la
     * pantalla para siempre, tapando lo de hoy.
     */
    public function test_la_pantalla_no_muestra_platos_de_jornadas_anteriores(): void
    {
        $cajero = $this->usuario('cajero1');

        // Ayer por la noche: queda pendiente, nadie lo tocó.
        $this->travelTo(now()->subDay());
        $ayer = Pedidos::agregarLinea(
            Pedidos::abrir(Pedido::LLEVAR, $cajero, nombreCliente: 'Anoche'), $this->plato(), 1, 'de ayer', $cajero);
        $this->travelBack();

        $hoy = Pedidos::agregarLinea(
            Pedidos::abrir(Pedido::LLEVAR, $cajero, nombreCliente: 'Hoy'), $this->plato(), 1, 'de hoy', $cajero);

        $this->assertNotSame($ayer->pedido->jornada->toDateString(), $hoy->pedido->jornada->toDateString());

        $ids = collect($this->actingAs($this->usuario('cocina1'))->getJson(route('cocina.pendientes'))->assertOk()->json('tandas'))
            ->flatMap(fn ($t) => collect($t['lineas'])->pluck('id'))->all();

        $this->assertContains($hoy->id, $ids);
        $this->assertNotContains($ayer->id, $ids, 'la cocina sigue mostrando un plato de la jornada anterior');

        $this->actingAs($this->usuario('cocina1'))->get(route('cocina.index'))->assertOk()
            ->assertSee('de hoy')
            ->assertDontSee('de ayer');
    }

    // ------------------------------------------------- máquina de estados

    public function test_el_camino_normal_va_de_pendiente_a_entregado(): void
    {
        $cocina = $this->usuario('cocina1');
        $linea = $this->lineaPendiente();

        foreach ([PedidoDetalle::EN_PREPARACION, PedidoDetalle::LISTO, PedidoDetalle::ENTREGADO] as $paso) {
            $this->actingAs($cocina)
                ->post(route('cocina.estado', $linea), ['estado' => $paso])
                ->assertSessionHasNoErrors();

            $this->assertSame($paso, $linea->fresh()->estado_cocina);
            $this->assertSame($cocina->id, $linea->fresh()->actualizado_por);
        }

        $this->assertDatabaseHas('auditoria', ['accion' => 'PEDIDO_LINEA_ESTADO', 'entidad_id' => $linea->id]);
    }

    /**
     * No se vuelve atrás: si la cocina se adelantó, se cancela el plato y se
     * pide de nuevo, y así queda el rastro de lo que pasó.
     */
    public function test_no_se_vuelve_atras_en_la_preparacion(): void
    {
        $cocina = $this->usuario('cocina1');
        $linea = $this->lineaPendiente();

        Pedidos::actualizarEstadoLinea($linea, PedidoDetalle::LISTO, $cocina);

        $this->assertThrows(
            fn () => Pedidos::actualizarEstadoLinea($linea->fresh(), PedidoDetalle::EN_PREPARACION, $cocina),
            RuntimeException::class,
        );
        $this->assertSame(PedidoDetalle::LISTO, $linea->fresh()->estado_cocina);

        // Y el mensaje llega a la pantalla, no un error de servidor: volver a
        // «pendiente» ni siquiera es un paso que la ruta de la cocina acepte.
        $this->actingAs($cocina)
            ->post(route('cocina.estado', $linea), ['estado' => PedidoDetalle::PENDIENTE])
            ->assertSessionHasErrors('estado');
        $this->actingAs($cocina)
            ->post(route('cocina.estado', $linea), ['estado' => PedidoDetalle::EN_PREPARACION])
            ->assertSessionHas('error');
    }

    /** Lo que ya salió de la cocina se sirvió: eso se cobra, no se cancela. */
    public function test_un_plato_listo_ya_no_se_cancela(): void
    {
        $cocina = $this->usuario('cocina1');
        $linea = $this->lineaPendiente();

        Pedidos::actualizarEstadoLinea($linea, PedidoDetalle::LISTO, $cocina);

        $this->assertThrows(
            fn () => Pedidos::actualizarEstadoLinea($linea->fresh(), PedidoDetalle::CANCELADO, $cocina),
            RuntimeException::class,
        );
        $this->assertSame(PedidoDetalle::LISTO, $linea->fresh()->estado_cocina);
    }

    /**
     * La cocina solo avanza: su ruta no acepta cancelar y su pantalla no lo
     * ofrece. Cancelar un plato es dejarlo sin cobrar, y eso es de la caja
     * (C1). Antes la ruta validaba contra todas las transiciones y la cocina
     * cancelaba platos de un pedido por volver a cobrar: se servían y no se cobraban.
     */
    public function test_la_cocina_no_cancela_platos(): void
    {
        $cocina = $this->usuario('cocina1');
        $linea = $this->lineaPendiente();

        $this->actingAs($cocina)->get(route('cocina.index'))->assertOk()
            ->assertSee($linea->descripcion)
            ->assertDontSee('value="'.PedidoDetalle::CANCELADO.'"', false);

        foreach ([PedidoDetalle::PENDIENTE, PedidoDetalle::EN_PREPARACION] as $paso) {
            if ($paso === PedidoDetalle::EN_PREPARACION) {
                Pedidos::actualizarEstadoLinea($linea->fresh(), $paso, $cocina);
            }

            $this->actingAs($cocina)
                ->post(route('cocina.estado', $linea), ['estado' => PedidoDetalle::CANCELADO])
                ->assertSessionHasErrors('estado');

            $this->assertSame($paso, $linea->fresh()->estado_cocina);
        }

        // Ni siquiera el administrador cancela por la ruta de la cocina.
        $this->actingAs($this->usuario('admin'))
            ->post(route('cocina.estado', $linea), ['estado' => PedidoDetalle::CANCELADO])
            ->assertSessionHasErrors('estado');
        $this->assertSame(PedidoDetalle::EN_PREPARACION, $linea->fresh()->estado_cocina);
    }

    /** Un estado que la máquina no conoce se rechaza en la validación. */
    public function test_un_estado_inventado_se_rechaza(): void
    {
        $linea = $this->lineaPendiente();

        $this->actingAs($this->usuario('cocina1'))
            ->post(route('cocina.estado', $linea), ['estado' => 'QUEMANDOSE'])
            ->assertSessionHasErrors('estado');

        $this->assertSame(PedidoDetalle::PENDIENTE, $linea->fresh()->estado_cocina);
    }

    /** Un estado terminal no va a ninguna parte. */
    public function test_lo_entregado_y_lo_cancelado_son_el_final(): void
    {
        $cocina = $this->usuario('cocina1');

        $cancelada = $this->lineaPendiente();
        Pedidos::actualizarEstadoLinea($cancelada, PedidoDetalle::CANCELADO, $this->usuario('cajero1'));

        foreach (PedidoDetalle::TRANSICIONES[PedidoDetalle::PENDIENTE] as $destino) {
            if ($destino === PedidoDetalle::CANCELADO) {
                // Pedir el estado que ya tiene no es un error: no se hace nada.
                Pedidos::actualizarEstadoLinea($cancelada->fresh(), $destino, $cocina);

                continue;
            }

            $this->assertThrows(
                fn () => Pedidos::actualizarEstadoLinea($cancelada->fresh(), $destino, $cocina),
                RuntimeException::class,
            );
        }

        $this->assertSame(PedidoDetalle::CANCELADO, $cancelada->fresh()->estado_cocina);

        $this->assertSame([], PedidoDetalle::TRANSICIONES[PedidoDetalle::ENTREGADO]);
    }

    /** Con el pedido cancelado, la preparación ya no se mueve. */
    public function test_con_el_pedido_cancelado_la_preparacion_no_se_mueve(): void
    {
        $cajero = $this->usuario('cajero1');
        $pedido = Pedidos::abrir(Pedido::LOCAL, $cajero);
        $linea = Pedidos::agregarLinea($pedido, $this->plato(), 1, null, $cajero);
        Pedidos::cancelar($pedido, $cajero, 'El cliente se fue');

        $this->assertThrows(
            fn () => Pedidos::actualizarEstadoLinea($linea->fresh(), PedidoDetalle::EN_PREPARACION, $this->usuario('cocina1')),
            RuntimeException::class,
        );
    }

    // ------------------------------------------------------------ el tablero

    /** Dos platos de la cocina en un pedido nuevo, sin empezar. */
    private function pedidoDeDos(): Pedido
    {
        $cajero = $this->usuario('cajero1');
        $pedido = Pedidos::abrir(Pedido::LLEVAR, $cajero);
        $platos = Producto::activos()
            ->whereHas('categoria', fn ($q) => $q->where('pasa_por_cocina', 1))
            ->orderBy('id')->take(2)->get();
        $this->assertCount(2, $platos, 'hacen falta dos platos que pasen por la cocina');

        foreach ($platos as $plato) {
            Pedidos::agregarLinea($pedido, $plato, 1, null, $cajero);
        }

        return $pedido;
    }

    private function columnaDe(Pedido $pedido): ?string
    {
        return collect($this->actingAs($this->usuario('cocina1'))->getJson(route('cocina.pendientes'))->json('tandas'))
            ->firstWhere('pedido_id', $pedido->id)['columna'] ?? null;
    }

    /** @return array<int, string> */
    private function estados(Pedido $pedido): array
    {
        return $pedido->detalle()->orderBy('id')->pluck('estado_cocina')->all();
    }

    /**
     * Un toque por columna, no uno por plato: «Empezar» y «Listo» mueven el
     * pedido entero, y «Entregado» lo saca del tablero.
     */
    public function test_el_boton_del_pedido_lo_lleva_entero_de_columna_en_columna(): void
    {
        $cocina = $this->usuario('cocina1');
        $pedido = $this->pedidoDeDos();
        $this->assertSame('hacer', $this->columnaDe($pedido));

        $this->actingAs($cocina)->get(route('cocina.index'))->assertOk()
            ->assertSee('data-avanzar-pedido="hacer"', false)
            ->assertSee(route('cocina.avanzar', $pedido), false);

        $this->actingAs($cocina)->post(route('cocina.avanzar', $pedido), ['estado' => PedidoDetalle::EN_PREPARACION])
            ->assertSessionHas('exito');
        $this->assertSame([PedidoDetalle::EN_PREPARACION, PedidoDetalle::EN_PREPARACION], $this->estados($pedido));
        $this->assertSame('cocinando', $this->columnaDe($pedido));

        $this->actingAs($cocina)->post(route('cocina.avanzar', $pedido), ['estado' => PedidoDetalle::LISTO])
            ->assertSessionHas('exito');
        $this->assertSame([PedidoDetalle::LISTO, PedidoDetalle::LISTO], $this->estados($pedido));
        $this->assertSame('entregar', $this->columnaDe($pedido));

        $this->actingAs($cocina)->post(route('cocina.entregar', $pedido))->assertSessionHas('exito');
        $this->assertSame([PedidoDetalle::ENTREGADO, PedidoDetalle::ENTREGADO], $this->estados($pedido));
        $this->assertNull($this->columnaDe($pedido), 'lo entregado sale del tablero');
    }

    /**
     * Un plato que salió antes que el otro deja el pedido en «cocinando», y
     * «Listo» termina también lo que ni se había empezado: un plato rápido
     * pasa de pendiente a listo sin más.
     */
    public function test_listo_termina_tambien_lo_que_no_se_habia_empezado(): void
    {
        $cocina = $this->usuario('cocina1');
        $pedido = $this->pedidoDeDos();
        $primero = $pedido->detalle()->orderBy('id')->first();

        Pedidos::actualizarEstadoLinea($primero, PedidoDetalle::LISTO, $cocina);
        $this->assertSame('cocinando', $this->columnaDe($pedido));

        $this->actingAs($cocina)->post(route('cocina.avanzar', $pedido), ['estado' => PedidoDetalle::LISTO])
            ->assertSessionHas('exito');
        $this->assertSame([PedidoDetalle::LISTO, PedidoDetalle::LISTO], $this->estados($pedido));
    }

    /** El botón del pedido solo avanza: ni cancela ni se salta la entrega. */
    public function test_el_boton_del_pedido_no_cancela_ni_entrega(): void
    {
        $cocina = $this->usuario('cocina1');
        $pedido = $this->pedidoDeDos();

        foreach ([PedidoDetalle::CANCELADO, PedidoDetalle::ENTREGADO, 'VOLANDO'] as $estado) {
            $this->actingAs($cocina)->post(route('cocina.avanzar', $pedido), ['estado' => $estado])
                ->assertSessionHasErrors('estado');
        }

        $this->assertSame([PedidoDetalle::PENDIENTE, PedidoDetalle::PENDIENTE], $this->estados($pedido));
        $this->actingAs($this->usuario('cajero1'))
            ->post(route('cocina.avanzar', $pedido), ['estado' => PedidoDetalle::EN_PREPARACION])
            ->assertForbidden();
    }

    /** La pantalla completa se guarda en la tablet y se aplica antes de pintar. */
    public function test_la_pantalla_trae_el_modo_de_pantalla_completa(): void
    {
        $this->actingAs($this->usuario('cocina1'))->get(route('cocina.index'))->assertOk()
            ->assertSee('data-pantalla-completa', false)
            ->assertSee("localStorage.getItem('cocina.pantallaCompleta')", false)
            ->assertSee('data-columna="cocinando"', false)
            ->assertSee('data-columna="entregar"', false);
    }
}
