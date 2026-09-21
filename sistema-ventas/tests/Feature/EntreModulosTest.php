<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\CobroQr;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\Ventas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Fallos que aparecían solo al combinar módulos (revisión del 16/09/2026).
 * Cada prueba reproduce la secuencia que rompía la cuenta.
 */
class EntreModulosTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuario(string $nombre): Usuario
    {
        return Usuario::where('usuario', $nombre)->firstOrFail();
    }

    private function comun(): Producto
    {
        return Producto::activos()->orderBy('id')->firstOrFail();
    }

    // ================================================================ dinero y pantallas

    private function turnoDe(Usuario $usuario, float $inicial = 100): SesionCaja
    {
        return (Cajas::sesionDe($usuario) ?? Cajas::abrir(Caja::firstOrFail(), $usuario, $inicial))->fresh();
    }

    private function metodo(string $codigo): int
    {
        return (int) MetodoPago::where('codigo', $codigo)->value('id');
    }

    private function venta(SesionCaja $turno, Usuario $usuario, float $cantidad = 1, ?array $pagos = null): Venta
    {
        return Ventas::registrar(
            sesion: $turno->fresh(),
            usuario: $usuario,
            lineas: [['producto_id' => $this->comun()->id, 'cantidad' => $cantidad]],
            pagos: $pagos ?? [['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null]],
        );
    }

    /** Los totales del listado de ventas siguen al filtro de estado y a la búsqueda, como la tabla. */
    public function test_los_totales_del_listado_de_ventas_siguen_los_filtros(): void
    {
        $admin = $this->usuario('admin');
        $turno = $this->turnoDe($admin);
        $buena = $this->venta($turno, $admin, 2);
        $anulada = $this->venta($turno, $admin, 1);
        Ventas::anular($anulada->fresh(), $admin, 'Prueba de filtros');

        $resumen = fn (array $q) => $this->actingAs($admin)->get(route('ventas.index', $q))->assertOk()->viewData('resumen');

        $soloAnuladas = $resumen(['estado' => 'ANULADA', 'buscar' => '#'.$anulada->id]);
        $this->assertSame(0, $soloAnuladas['operaciones']);
        $this->assertSame(0.0, $soloAnuladas['vendido']);
        $this->assertSame(1, $soloAnuladas['anuladas']);

        $unaSola = $resumen(['buscar' => '#'.$buena->id]);
        $this->assertSame(1, $unaSola['operaciones']);
        $this->assertSame((float) $buena->total, $unaSola['vendido']);
    }

    /** El administrador registra en el turno del cajero el egreso que supera su tope: el botón tiene que estar. */
    public function test_el_administrador_ve_como_registrar_un_movimiento_en_el_turno_del_cajero(): void
    {
        $cajero = $this->usuario('cajero1');
        $turno = $this->turnoDe($cajero);

        $this->actingAs($this->usuario('admin'))->get(route('caja.show', $turno))->assertOk()->assertSee('Registrar movimiento');
        $this->actingAs($cajero)->get(route('caja.show', $turno))->assertOk()->assertSee('Registrar movimiento');
        $this->actingAs($this->usuario('admin'))->post(route('caja.movimiento', $turno), [
            'tipo' => 'EGRESO', 'concepto' => 'Pago al proveedor de hielo', 'monto' => 20,
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, $turno->movimientos()->where('concepto', 'Pago al proveedor de hielo')->count());
    }

    /** El cajero no ve un enlace al turno de otro que le daría 403. */
    public function test_el_cajero_no_ve_el_enlace_al_turno_de_otro(): void
    {
        $turnoAdmin = $this->turnoDe($this->usuario('admin'));

        $this->actingAs($this->usuario('cajero1'))->get(route('caja.index'))->assertOk()
            ->assertDontSee(route('caja.show', $turnoAdmin), false);
        $this->actingAs($this->usuario('admin'))->get(route('caja.index'))->assertOk()
            ->assertSee(route('caja.show', $turnoAdmin), false);
    }

    /** Quien no puede editar clientes no ve los botones que le darían 403. */
    public function test_no_se_ven_acciones_de_clientes_que_no_se_pueden_hacer(): void
    {
        // La cocina no entra a clientes; el cajero entra y registra, pero
        // no ve cómo editar ni eliminar.
        $this->actingAs($this->usuario('cocina1'))->get(route('clientes.index'))->assertForbidden();
        $this->actingAs($this->usuario('cajero1'))->get(route('clientes.index'))->assertOk()
            ->assertSee('@click="nuevo()"', false)->assertDontSee('title="Eliminar"', false);
        $this->actingAs($this->usuario('admin'))->get(route('clientes.index'))->assertOk()
            ->assertSee('@click="nuevo()"', false);
    }

    /** Anular una venta cobrada por QR deja a la vista lo que hay que devolver por el banco. */
    public function test_anular_una_venta_cobrada_por_qr_muestra_el_reintegro(): void
    {
        $cajero = $this->usuario('cajero1');
        $turno = $this->turnoDe($cajero);
        $total = (float) Ventas::registrar(
            sesion: $turno->fresh(), usuario: $cajero,
            lineas: [['producto_id' => $this->comun(80)->id, 'cantidad' => 1]],
            pagos: [['metodo_pago_id' => $this->metodo('EFECTIVO'), 'monto' => null]],
        )->total;
        $cobro = CobrosQr::confirmarAMano(CobrosQr::generar($turno, $cajero, $total), $cajero);
        $venta = $this->venta($turno, $cajero, 1, [['metodo_pago_id' => $this->metodo('QR'), 'cobro_qr_id' => $cobro->id]]);

        $this->actingAs($this->usuario('admin'))->get(route('ventas.show', $venta))->assertOk()
            ->assertSee('hay que devolverlos por el banco');

        Ventas::anular($venta->fresh(), $this->usuario('admin'), 'Se equivocó de producto');

        $this->assertSame($total, $venta->fresh()->reintegro_por_anulacion);
        $this->actingAs($this->usuario('admin'))->get(route('ventas.show', $venta))->assertOk()
            ->assertSee('data-reintegro-anulacion', false);
    }

    /** Un QR pagado sin venta se ve en la caja, y al cerrar los que esperaban pago se cancelan. */
    public function test_los_qr_pagados_sin_venta_se_ven_y_los_pendientes_se_cancelan_al_cerrar(): void
    {
        $cajero = $this->usuario('cajero1');
        $admin = $this->usuario('admin');
        $turno = $this->turnoDe($cajero);

        $pagado = CobrosQr::confirmarAMano(CobrosQr::generar($turno, $cajero, 12.5), $cajero);
        $pendiente = CobrosQr::generar($turno->fresh(), $cajero, 7);

        $this->actingAs($admin)->get(route('caja.show', $turno))->assertOk()
            ->assertSee('data-qr-sin-venta', false)->assertSee('1 cobro(s) por QR pagados sin venta');

        $turno = $turno->fresh();
        Cajas::cerrar($turno, $admin, $turno->efectivoEsperado(), null, 0, $turno->huella());

        $this->assertNotSame(CobroQr::PENDIENTE, $pendiente->fresh()->estado, 'un QR de un turno cerrado sigue cobrable');
        $this->assertSame(CobroQr::PAGADO, $pagado->fresh()->estado);
        $this->actingAs($admin)->get(route('caja.imprimir', $turno))->assertOk()->assertSee('data-qr-sin-venta', false);
    }

    /** Con la facturación oculta, sustituir un comprobante no existe, tampoco por un envío directo. */
    public function test_con_la_facturacion_oculta_no_se_sustituye_un_comprobante(): void
    {
        $admin = $this->usuario('admin');
        $venta = $this->venta($this->turnoDe($admin), $admin);

        config(['ventas.mostrar_facturacion' => false]);
        $this->actingAs($admin)->post(route('comprobantes.sustituir', $venta->comprobante), ['motivo' => 'Corregir el nombre'])
            ->assertNotFound();
        $this->assertSame('EMITIDO', $venta->comprobante->fresh()->estado);
    }

    /** La ficha del producto la abre quien gestiona el catÃ¡logo o quien ve reportes, que es quien la enlaza. */
    public function test_la_ficha_del_producto_abre_para_quien_la_enlaza(): void
    {
        $permisos = Route::getRoutes()->getByName('productos.show')->middleware();
        $permiso = collect($permisos)->first(fn ($m) => str_starts_with($m, 'permiso:'));

        foreach (['productos.gestionar', 'reportes.ver'] as $codigo) {
            $this->assertStringContainsString($codigo, (string) $permiso);
        }

        $this->actingAs($this->usuario('admin'))->get(route('productos.show', $this->comun()))->assertOk();
        $this->actingAs($this->usuario('cajero1'))->get(route('productos.show', $this->comun()))->assertForbidden();
        $this->actingAs($this->usuario('cocina1'))->get(route('productos.show', $this->comun()))->assertForbidden();
        $this->actingAs($this->usuario('admin'))->get(route('productos.create'))->assertOk();
    }

    /** Si la copia a la carpeta externa falla, el respaldo nocturno no termina «bien» en silencio. */
    public function test_el_respaldo_nocturno_avisa_si_no_pudo_copiar_afuera(): void
    {
        $archivo = tempnam(sys_get_temp_dir(), 'nodir');
        config(['ventas.respaldos.copia' => $archivo.DIRECTORY_SEPARATOR.'adentro']);

        try {
            $this->artisan('respaldo:crear')->assertFailed();
            $this->assertTrue(Auditoria::where('accion', 'RESPALDO_COPIA_FALLIDA')->exists());
        } finally {
            @unlink($archivo);
        }
    }
}
