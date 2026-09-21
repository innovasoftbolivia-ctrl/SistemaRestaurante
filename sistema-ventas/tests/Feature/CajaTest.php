<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\Pedidos;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class CajaTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    private function cajero(): Usuario
    {
        return Usuario::where('usuario', 'cajero1')->firstOrFail();
    }

    private function cocina(): Usuario
    {
        return Usuario::where('usuario', 'cocina1')->firstOrFail();
    }

    private function turno(?Usuario $usuario = null, float $inicial = 100): SesionCaja
    {
        return Cajas::abrir(Caja::firstOrFail(), $usuario ?? $this->cajero(), $inicial);
    }

    private function vender(SesionCaja $sesion, float $cantidad = 2, string $codigo = 'P-0004'): void
    {
        $producto = Producto::where('codigo', $codigo)->firstOrFail();

        Ventas::registrar(
            sesion: $sesion,
            usuario: $sesion->usuarioApertura,
            lineas: [['producto_id' => $producto->id, 'cantidad' => $cantidad, 'precio_unitario' => (float) $producto->precio_venta]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
    }

    // -------------------------------------------------------------- permisos

    /**
     * Solo el administrador cierra la caja (O4: el arqueo lo hace quien no
     * tuvo la mano en el cajón durante el turno). El cajero abre y vende,
     * pero perdió `caja.cerrar` — probarlo por HTTP y no llamando al
     * servicio directo, porque el permiso se aplica en el middleware de la
     * ruta, no dentro de `Cajas::cerrar()`.
     */
    public function test_el_cajero_ya_no_puede_cerrar_su_propia_caja(): void
    {
        $sesion = $this->turno($this->cajero());

        $this->actingAs($this->cajero())
            ->post(route('caja.cerrar', $sesion), ['huella' => $sesion->fresh()->huella(), 'fondo_dejado' => 0, 'monto_declarado' => 100])
            ->assertForbidden();

        $this->assertTrue($sesion->fresh()->estaAbierta());
    }

    public function test_el_administrador_si_puede_cerrar_la_caja_del_cajero(): void
    {
        $sesion = $this->turno($this->cajero());

        $this->actingAs($this->admin())
            ->post(route('caja.cerrar', $sesion), ['huella' => $sesion->fresh()->huella(), 'fondo_dejado' => 0, 'monto_declarado' => 100])
            ->assertRedirect(route('caja.imprimir', $sesion));

        $this->assertFalse($sesion->fresh()->estaAbierta());
    }

    /**
     * El mismo cajero no puede terminar con dos turnos abiertos en dos cajas
     * distintas: sin esto, `Cajas::sesionDe()` (que usa `->first()`, sin
     * criterio de desempate) le atribuiría todas sus ventas siempre a una
     * sola de las dos sesiones, de forma no determinista, mientras el
     * efectivo real queda repartido entre dos cajones. La única defensa
     * anterior era un SELECT-antes-de-INSERT en PHP —una condición de carrera
     * real bajo dos peticiones simultáneas—, así que la garantía de verdad
     * tiene que estar en la base: el índice único `uq_sesion_usuario_abierta`.
     */
    public function test_la_base_impide_dos_sesiones_abiertas_para_el_mismo_usuario(): void
    {
        $otraCaja = Caja::create(['nombre' => 'Caja 2', 'ubicacion' => 'Depósito']);
        $usuario = $this->cajero();

        $this->turno($usuario);

        $this->expectException(QueryException::class);

        // Directo al modelo, saltándose el chequeo de `Cajas::abrir()`: así se
        // prueba el candado real (el de la base), no el atajo de PHP que solo
        // cierra la ventana de carrera a medias.
        SesionCaja::create([
            'caja_id' => $otraCaja->id,
            'usuario_apertura_id' => $usuario->id,
            'fecha_apertura' => now(),
            'monto_inicial' => 50,
            'estado' => 'ABIERTA',
        ]);
    }

    /** Mismo grupo de permisos que ya protege la ficha del turno (`caja.show`), pero solo si ya está cerrada. */
    public function test_quien_ve_el_turno_tambien_ve_el_resumen_imprimible_una_vez_cerrado(): void
    {
        $sesion = $this->turno();
        Cajas::cerrar($sesion->fresh(), $this->admin(), 100);

        foreach ([$this->cajero(), $this->admin()] as $usuario) {
            $this->actingAs($usuario)->get(route('caja.imprimir', $sesion))->assertOk();
        }

        // La cocina no ve las cajas de nadie: eso es del administrador.
        $this->actingAs($this->cocina())->get(route('caja.imprimir', $sesion))->assertForbidden();
    }

    // -------------------------------------------------------------- contenido

    /**
     * El resumen es la constancia del cierre, no un borrador para revisar
     * antes: con la sesión todavía abierta no hay documento que mostrar —
     * de haberlo, el arqueo saldría en blanco, que es justo lo que no se
     * quiere.
     */
    public function test_no_se_puede_imprimir_el_resumen_con_el_turno_abierto(): void
    {
        $sesion = $this->turno();

        $this->actingAs($this->admin())
            ->get(route('caja.imprimir', $sesion))
            ->assertRedirect(route('caja.show', $sesion))
            ->assertSessionHas('error');
    }

    public function test_el_resumen_cerrado_muestra_lo_declarado_y_la_diferencia(): void
    {
        $sesion = $this->turno(inicial: 100);
        $this->vender($sesion, 2);

        $esperado = $sesion->fresh()->efectivoEsperado();
        Cajas::cerrar($sesion->fresh(), $this->cajero(), $esperado - 5, 'Faltó vuelto de una venta');

        $respuesta = $this->actingAs($this->admin())->get(route('caja.imprimir', $sesion))->assertOk();

        $respuesta->assertSee('Turno cerrado');
        $respuesta->assertSee('Faltó vuelto de una venta');
    }

    public function test_el_desglose_por_metodo_de_pago_cuadra_con_lo_vendido(): void
    {
        $sesion = $this->turno(inicial: 100);
        $tarjeta = MetodoPago::where('afecta_caja', 0)->firstOrFail();
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();

        // Una venta en efectivo y otra con tarjeta: el desglose debe separarlas.
        Ventas::registrar(
            sesion: $sesion,
            usuario: $sesion->usuarioApertura,
            lineas: [['producto_id' => $producto->id, 'cantidad' => 1, 'precio_unitario' => (float) $producto->precio_venta]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
        Ventas::registrar(
            sesion: $sesion,
            usuario: $sesion->usuarioApertura,
            lineas: [['producto_id' => $producto->id, 'cantidad' => 1, 'precio_unitario' => (float) $producto->precio_venta]],
            pagos: [['metodo_pago_id' => $tarjeta->id, 'monto' => null, 'referencia' => 'VOUCHER-001']],
        );
        Cajas::cerrar($sesion->fresh(), $this->admin(), $sesion->fresh()->efectivoEsperado());

        $respuesta = $this->actingAs($this->admin())->get(route('caja.imprimir', $sesion))->assertOk();

        $respuesta->assertSee('Efectivo');
        $respuesta->assertSee($tarjeta->nombre);
    }

    public function test_el_movimiento_de_egreso_aparece_en_el_resumen(): void
    {
        $sesion = $this->turno(inicial: 100);
        Cajas::movimiento($sesion, $this->cajero(), 'EGRESO', 'Pago a proveedor de bolsas', 15);
        Cajas::cerrar($sesion->fresh(), $this->admin(), $sesion->fresh()->efectivoEsperado());

        $this->actingAs($this->admin())
            ->get(route('caja.imprimir', $sesion))
            ->assertOk()
            ->assertSee('Pago a proveedor de bolsas');
    }
    // ============================================================ arqueo a ciegas

    /**
     * Si el cajero viera «lo que debería haber», sabría cuánto sobra antes del
     * conteo. El esperado es de quien arquea.
     */
    public function test_el_cajero_no_ve_el_efectivo_esperado_y_el_administrador_si(): void
    {
        $sesion = $this->turno();
        $this->vender($sesion);

        $this->actingAs($this->cajero())->get(route('caja.show', $sesion))
            ->assertOk()->assertDontSee('Efectivo esperado')->assertSee('lo cuenta el administrador contigo');
        $this->actingAs($this->cajero())->get(route('inicio'))
            ->assertOk()->assertDontSee('Efectivo esperado');

        $this->actingAs($this->admin())->get(route('caja.show', $sesion))
            ->assertOk()->assertSee('Efectivo esperado');
    }

    public function test_el_cajero_solo_ve_sus_propios_turnos(): void
    {
        $admin = $this->turno($this->admin());
        Cajas::cerrar($admin->fresh(), $this->admin(), 100);
        $propio = $this->turno();

        $this->actingAs($this->cajero())->get(route('caja.show', $admin))->assertForbidden();
        $this->actingAs($this->cajero())->get(route('caja.imprimir', $admin))->assertForbidden();
        $this->actingAs($this->cajero())->get(route('caja.show', $propio))->assertOk();

        $historial = $this->actingAs($this->cajero())->get(route('caja.index'))->viewData('historial');
        $this->assertSame([$this->cajero()->id], $historial->pluck('usuario_apertura_id')->unique()->values()->all());
    }

    // ============================================================ egresos

    public function test_ningun_egreso_supera_el_efectivo_del_cajon(): void
    {
        $sesion = $this->turno(inicial: 100);

        $this->actingAs($this->admin())->post(route('caja.movimiento', $sesion), [
            'tipo' => 'EGRESO', 'concepto' => 'Pago de luz', 'monto' => 150,
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'mayor que el efectivo'));

        $this->assertSame(100.0, $sesion->fresh()->efectivoEsperado());
    }

    /** Por encima del tope, el egreso lo registra el administrador en el turno del cajero. */
    public function test_el_cajero_tiene_tope_de_egreso_y_el_administrador_lo_registra_por_el(): void
    {
        DB::table('configuracion')->where('clave', 'egreso_max_cajero')->update(['valor' => '50.00']);
        Config::olvidar();
        $sesion = $this->turno(inicial: 300);

        $this->actingAs($this->cajero())->post(route('caja.movimiento', $sesion), [
            'tipo' => 'EGRESO', 'concepto' => 'Compra de bolsas', 'monto' => 80,
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'hasta Bs 50.00'));
        $this->assertSame(300.0, $sesion->fresh()->efectivoEsperado());

        $this->actingAs($this->cajero())->post(route('caja.movimiento', $sesion), [
            'tipo' => 'EGRESO', 'concepto' => 'Agua', 'monto' => 50,
        ])->assertSessionHas('exito');

        $this->actingAs($this->admin())->post(route('caja.movimiento', $sesion), [
            'tipo' => 'EGRESO', 'concepto' => 'Compra de bolsas', 'monto' => 80,
        ])->assertSessionHas('exito');

        $this->assertSame(170.0, $sesion->fresh()->efectivoEsperado());
        $this->assertSame($this->admin()->id, $sesion->movimientos()->where('monto', 80)->value('usuario_id'));
    }

    // ============================================================ cierre

    public function test_con_diferencia_la_observacion_es_obligatoria(): void
    {
        $sesion = $this->turno(inicial: 100);

        $this->actingAs($this->admin())->post(route('caja.cerrar', $sesion), ['huella' => $sesion->fresh()->huella(), 'fondo_dejado' => 0, 'monto_declarado' => 60])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'escribe en la observación'));
        $this->assertTrue($sesion->fresh()->estaAbierta());

        $this->actingAs($this->admin())->post(route('caja.cerrar', $sesion), [
            'monto_declarado' => 60, 'observacion' => 'Se pagó el gas sin registrarlo', 'fondo_dejado' => 0,
            'huella' => $sesion->fresh()->huella(),
        ])->assertRedirect(route('caja.imprimir', $sesion));

        $this->assertSame(-40.0, (float) $sesion->fresh()->diferencia);
    }

    public function test_sin_diferencia_se_cierra_sin_observacion(): void
    {
        $sesion = $this->turno(inicial: 100);

        $this->actingAs($this->admin())->post(route('caja.cerrar', $sesion), ['huella' => $sesion->fresh()->huella(), 'fondo_dejado' => 0, 'monto_declarado' => 100])
            ->assertRedirect(route('caja.imprimir', $sesion));

        $this->assertFalse($sesion->fresh()->estaAbierta());
    }

    // ============================================================ cierre y siguiente turno

    public function test_la_nota_de_cierre_no_pisa_la_de_apertura(): void
    {
        $sesion = Cajas::abrir(Caja::firstOrFail(), $this->cajero(), 100, 'Billetes de 10 cambiados en el banco');
        Cajas::cerrar($sesion->fresh(), $this->admin(), 95, 'Faltó vuelto de una venta');

        $sesion->refresh();
        $this->assertSame('Billetes de 10 cambiados en el banco', $sesion->observacion);
        $this->assertSame('Faltó vuelto de una venta', $sesion->observacion_cierre);

        $this->actingAs($this->admin())->get(route('caja.imprimir', $sesion))->assertOk()
            ->assertSee('Billetes de 10 cambiados en el banco')
            ->assertSee('Faltó vuelto de una venta');
    }

    /** Lo que se vende mientras se cuenta no puede quedar como diferencia sin que nadie lo vea. */
    public function test_si_se_vende_mientras_se_cuenta_el_cierre_pide_revisar(): void
    {
        $sesion = $this->turno(inicial: 100);
        $huella = $sesion->fresh()->huella();
        $esperadoVisto = $sesion->fresh()->efectivoEsperado();

        $this->vender($sesion);

        $this->actingAs($this->admin())->post(route('caja.cerrar', $sesion), [
            'monto_declarado' => $esperadoVisto, 'huella' => $huella, 'fondo_dejado' => 0,
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'Mientras contabas'));
        $this->assertTrue($sesion->fresh()->estaAbierta());

        $this->actingAs($this->admin())->post(route('caja.cerrar', $sesion), [
            'monto_declarado' => $sesion->fresh()->efectivoEsperado(), 'huella' => $sesion->fresh()->huella(), 'fondo_dejado' => 0,
        ])->assertRedirect(route('caja.imprimir', $sesion));
        $this->assertSame(0.0, (float) $sesion->fresh()->diferencia);
    }

    public function test_no_se_vende_en_un_turno_que_se_cerro_entretanto(): void
    {
        $sesion = $this->turno(inicial: 100);
        $pantallaVieja = $sesion->fresh();
        Cajas::cerrar($sesion->fresh(), $this->admin(), 100);

        try {
            $this->vender($pantallaVieja);
            $this->fail('La venta entró en un turno ya cerrado.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('se cerró', $e->getMessage());
        }

        $this->assertSame(0, $sesion->ventas()->count());
    }

    public function test_lo_que_queda_en_el_cajon_es_el_monto_del_siguiente_turno(): void
    {
        $caja = Caja::firstOrFail();
        $sesion = $this->turno(inicial: 100);

        $this->actingAs($this->admin())->post(route('caja.cerrar', $sesion), [
            'monto_declarado' => 100, 'fondo_dejado' => 150, 'huella' => $sesion->fresh()->huella(),
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'más de lo contado'));

        $this->actingAs($this->admin())->post(route('caja.cerrar', $sesion), [
            'monto_declarado' => 100, 'fondo_dejado' => 60, 'huella' => $sesion->fresh()->huella(),
        ])->assertRedirect(route('caja.imprimir', $sesion));

        $this->actingAs($this->admin())->get(route('caja.imprimir', $sesion))->assertOk()
            ->assertSeeInOrder(['Queda en el cajón para el siguiente turno', Config::importe(60), 'Se retira del cajón', Config::importe(40)]);
        $this->assertSame(60.0, Cajas::fondoDejadoEn($caja));

        try {
            Cajas::abrir($caja, $this->cajero(), 20);
            $this->fail('Abrió con menos fondo del que quedó, sin explicar nada.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('dejó', $e->getMessage());
        }

        $this->assertTrue(Cajas::abrir($caja, $this->cajero(), 60)->estaAbierta());
    }

    public function test_abrir_con_otro_fondo_se_acepta_si_se_explica(): void
    {
        $caja = Caja::firstOrFail();
        Cajas::cerrar($this->turno(inicial: 100)->fresh(), $this->admin(), 100, null, 60);

        $sesion = Cajas::abrir($caja, $this->cajero(), 20, 'El dueño retiró 40 para el proveedor');

        $this->assertSame('20.00', $sesion->fresh()->monto_inicial);
    }

    public function test_el_impreso_muestra_la_cuenta_completa_del_esperado(): void
    {
        $sesion = $this->turno(inicial: 100);
        $this->vender($sesion, 2);
        Cajas::movimiento($sesion->fresh(), $this->admin(), 'INGRESO', 'Cambio del banco', 50);
        Cajas::movimiento($sesion->fresh(), $this->admin(), 'EGRESO', 'Bolsas', 15);
        $cuenta = $sesion->fresh()->desgloseDelEfectivo();
        Cajas::cerrar($sesion->fresh(), $this->admin(), $cuenta['esperado']);

        $this->assertSame($cuenta['esperado'], (float) $sesion->fresh()->monto_esperado);

        $this->actingAs($this->admin())->get(route('caja.imprimir', $sesion))->assertOk()
            ->assertSeeInOrder([
                'Arqueo',
                'Monto inicial', Config::importe(100),
                'Ventas en efectivo', Config::importe($cuenta['ventas']),
                'Ingresos de caja', Config::importe(50),
                'Egresos de caja', Config::importe(15),
                'Efectivo esperado', Config::importe($cuenta['esperado']),
            ]);
    }

    // ================================================================ cierre y egresos

    /** Sin el sello del turno, el cierre se saltaba el aviso de «se vendió mientras contabas». */
    public function test_el_cierre_exige_el_sello_del_turno(): void
    {
        $sesion = $this->turno(inicial: 100);
        $esperado = $sesion->fresh()->efectivoEsperado();

        $this->actingAs($this->admin())->post(route('caja.cerrar', $sesion), [
            'monto_declarado' => $esperado, 'fondo_dejado' => 0,
        ])->assertSessionHasErrors('huella');

        $this->assertTrue($sesion->fresh()->estaAbierta());

        $this->actingAs($this->admin())->post(route('caja.cerrar', $sesion), [
            'monto_declarado' => $esperado, 'huella' => $sesion->fresh()->huella(), 'fondo_dejado' => 0,
        ])->assertRedirect(route('caja.imprimir', $sesion));
        $this->assertFalse($sesion->fresh()->estaAbierta());
    }

    /** El tope del cajero es por turno: partir el retiro en varios no lo evade. */
    public function test_el_tope_de_egresos_es_del_turno_entero(): void
    {
        $sesion = $this->turno(inicial: 1000);
        $tope = (float) Config::get('egreso_max_cajero', '0');
        $mitad = round($tope / 2, 2);

        Cajas::movimiento($sesion->fresh(), $this->cajero(), 'EGRESO', 'Primera parte', $mitad);

        $rechazo = null;
        try {
            Cajas::movimiento($sesion->fresh(), $this->cajero(), 'EGRESO', 'Segunda parte', $tope);
        } catch (RuntimeException $e) {
            $rechazo = $e->getMessage();
        }

        $this->assertNotNull($rechazo, 'El cajero sacó del cajón más que su tope, en dos veces.');
        $this->assertStringContainsString('por turno', $rechazo);

        // Lo que falta para llegar al tope sí entra, y el administrador no tiene tope.
        Cajas::movimiento($sesion->fresh(), $this->cajero(), 'EGRESO', 'El resto', $mitad);
        Cajas::movimiento($sesion->fresh(), $this->admin(), 'EGRESO', 'Pago a proveedor', $tope * 2);

        $this->assertSame(round($tope * 3, 2), round((float) $sesion->fresh()->movimientos()
            ->where('tipo', 'EGRESO')->sum('monto'), 2));
    }

    /** Cerrar el turno de otro es del arqueo: lo habilita `caja.cerrar`, no `reportes.ver`. */
    public function test_cerrar_el_turno_ajeno_pide_el_permiso_de_caja(): void
    {
        $sesion = $this->turno($this->cajero(), 100);
        $arqueador = $this->admin();
        DB::table('rol_permiso')
            ->where('rol_id', $arqueador->rol_id)
            ->whereIn('permiso_id', DB::table('permisos')->where('codigo', 'reportes.ver')->pluck('id'))
            ->delete();
        $arqueador->refresh();

        $this->assertFalse($arqueador->fresh()->tienePermiso('reportes.ver'));

        $this->actingAs($arqueador->fresh())->post(route('caja.cerrar', $sesion), [
            'monto_declarado' => $sesion->fresh()->efectivoEsperado(),
            'huella' => $sesion->fresh()->huella(), 'fondo_dejado' => 0,
        ])->assertRedirect(route('caja.imprimir', $sesion));

        $this->assertFalse($sesion->fresh()->estaAbierta());
    }

    // ================================================================ fondo obligatorio

    /** Sin anotar cuánto queda en el cajón, el turno siguiente abre con lo que sea. */
    public function test_el_cierre_exige_decir_cuanto_queda_en_el_cajon(): void
    {
        $sesion = $this->turno(inicial: 100);

        $this->actingAs($this->admin())->post(route('caja.cerrar', $sesion), [
            'monto_declarado' => 100, 'huella' => $sesion->fresh()->huella(),
        ])->assertSessionHasErrors('fondo_dejado');

        $this->assertTrue($sesion->fresh()->estaAbierta());
    }

    /**
     * El fondo que propone la apertura sale del último cierre que lo anotó: si
     * el más reciente lo dejó vacío, el control no se ejecutaba nunca.
     */
    public function test_el_fondo_propuesto_sale_del_ultimo_cierre_que_lo_anoto(): void
    {
        $caja = Caja::firstOrFail();

        Cajas::cerrar($this->turno(inicial: 100)->fresh(), $this->admin(), 100, null, 60);
        // Un cierre viejo, de los que no lo anotaban.
        $sinFondo = $this->turno(inicial: 60);
        Cajas::cerrar($sinFondo->fresh(), $this->admin(), 60);

        $this->assertSame(60.0, Cajas::fondoDejadoEn($caja));
    }

    // ================================================================ cuentas abiertas

    /**
     * Cerrar la caja con pedidos de cobro anulado (su venta se anuló y no se
     * volvió a cobrar) se puede —el turno siguiente los cobra—, pero no sin enterarse:
     * la pantalla los lista, el cierre pide confirmarlo y la bitácora guarda
     * cuáles quedaron.
     */
    public function test_el_cierre_lista_las_cuentas_abiertas_y_pide_confirmarlo(): void
    {
        $sesion = $this->turno(inicial: 100);
        $cajero = $this->cajero();
        $plato = Producto::where('codigo', 'P-0004')->firstOrFail();

        $local = Pedidos::abrir(Pedido::LOCAL, $cajero);
        Pedidos::agregarLinea($local, $plato, 2, null, $cajero);
        $llevar = Pedidos::abrir(Pedido::LLEVAR, $cajero, nombreCliente: 'Beto');
        Pedidos::agregarLinea($llevar, $plato, 1, null, $cajero);

        $this->actingAs($this->admin())->get(route('caja.show', $sesion))->assertOk()
            ->assertSee('data-cuentas-abiertas', false)
            ->assertSee($local->fresh()->etiqueta)
            ->assertSee($llevar->fresh()->etiqueta)
            ->assertSee(Config::importe(Pedidos::totalDe($local->fresh())))
            ->assertSee('pedido(s) con el cobro anulado, sin volver a cobrar')
            ->assertSee('Cierro sin volver a cobrarlos');

        $cerrar = fn (array $extra = []) => $this->actingAs($this->admin())->post(route('caja.cerrar', $sesion), [
            'monto_declarado' => 100, 'fondo_dejado' => 0, 'huella' => $sesion->fresh()->huella(),
        ] + $extra);

        // Sin confirmar, no se cierra. Tampoco llamando al servicio directo.
        $cerrar()->assertSessionHas('error', fn (string $m) => str_contains($m, '2 pedido(s) con el cobro anulado'));
        $this->assertTrue($sesion->fresh()->estaAbierta());
        $this->assertThrows(
            fn () => Cajas::cerrar($sesion->fresh(), $this->admin(), 100, null, 0, $sesion->fresh()->huella()),
            RuntimeException::class,
            'pedido(s) con el cobro anulado',
        );

        $cerrar(['con_cuentas_abiertas' => '1'])->assertRedirect(route('caja.imprimir', $sesion));
        $this->assertFalse($sesion->fresh()->estaAbierta());

        $detalle = json_decode((string) DB::table('auditoria')
            ->where('accion', 'CAJA_CERRADA')->where('entidad_id', $sesion->id)->value('detalle'), true);
        $this->assertEqualsCanonicalizing([$local->id, $llevar->id], array_column($detalle['cuentas_abiertas'], 'pedido_id'));

        // Los pedidos siguen sin volver a cobrarse: el turno siguiente los cobra.
        $this->assertTrue($local->fresh()->estaAbierto());
        $this->assertTrue($llevar->fresh()->estaAbierto());
    }

    /** Sin cuentas abiertas, el cierre no pide nada de más ni muestra el aviso. */
    public function test_sin_cuentas_abiertas_el_cierre_no_pide_confirmacion(): void
    {
        $sesion = $this->turno(inicial: 100);

        $this->actingAs($this->admin())->get(route('caja.show', $sesion))->assertOk()
            ->assertDontSee('data-cuentas-abiertas', false);

        $this->actingAs($this->admin())->post(route('caja.cerrar', $sesion), [
            'monto_declarado' => 100, 'fondo_dejado' => 0, 'huella' => $sesion->fresh()->huella(),
        ])->assertRedirect(route('caja.imprimir', $sesion));

        $detalle = json_decode((string) DB::table('auditoria')
            ->where('accion', 'CAJA_CERRADA')->where('entidad_id', $sesion->id)->value('detalle'), true);
        $this->assertArrayNotHasKey('cuentas_abiertas', $detalle);
    }
}
