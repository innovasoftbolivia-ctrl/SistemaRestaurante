<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «El cajero cierra su propia caja», la opción de Configuración para cuando no
 * hay un administrador en el local a la hora de cerrar. Apagada, el cierre es
 * del administrador (HU-27). Encendida, el cajero cierra el suyo a ciegas: sin
 * ver el esperado ni que el sistema le diga si hay diferencia.
 */
class CierreDelCajeroTest extends TestCase
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

    private function permitir(bool $si = true): void
    {
        DB::table('configuracion')->updateOrInsert(['clave' => 'cajero_cierra_su_caja'], ['valor' => $si ? '1' : '0']);
        Config::olvidar();
    }

    /** Un turno del cajero con una venta en efectivo: el esperado es inicial + venta. */
    private function turnoConVenta(Usuario $usuario, float $inicial = 100): SesionCaja
    {
        $sesion = Cajas::abrir(Caja::firstOrFail(), $usuario, $inicial);

        Ventas::registrar(
            sesion: $sesion->fresh(),
            usuario: $usuario,
            lineas: [['producto_id' => Producto::where('codigo', 'P-0004')->value('id'), 'cantidad' => 3]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );

        return $sesion->fresh();
    }

    private function cerrar(Usuario $quien, SesionCaja $sesion, float $contado, array $extra = [])
    {
        return $this->actingAs($quien)->post(route('caja.cerrar', $sesion), [
            'huella' => $sesion->fresh()->huella(),
            'monto_declarado' => $contado,
            'fondo_dejado' => 50,
        ] + $extra);
    }

    public function test_por_omision_el_cajero_no_cierra_su_caja(): void
    {
        $sesion = $this->turnoConVenta($this->cajero());

        $this->cerrar($this->cajero(), $sesion, 100)->assertForbidden();
        $this->assertTrue($sesion->fresh()->estaAbierta());

        $this->actingAs($this->cajero())->get(route('caja.show', $sesion))
            ->assertOk()
            ->assertDontSee('Cerrar caja');
    }

    public function test_con_la_opcion_el_cajero_cierra_la_suya_a_ciegas(): void
    {
        $this->permitir();
        $sesion = $this->turnoConVenta($this->cajero());
        $esperado = $sesion->efectivoEsperado();

        // La pantalla ofrece cerrar, pero el esperado no está ni en el HTML.
        $this->actingAs($this->cajero())->get(route('caja.show', $sesion))
            ->assertOk()
            ->assertSee('Cerrar caja')
            ->assertDontSee(Config::importe($esperado));

        // Cuenta de menos y sin observación: no se le avisa de la diferencia
        // (sería decirle cuánto «debería» haber). El cierre queda registrado.
        $this->cerrar($this->cajero(), $sesion, $esperado - 10)
            ->assertRedirect(route('caja.imprimir', $sesion));

        $cerrada = $sesion->fresh();
        $this->assertFalse($cerrada->estaAbierta());
        $this->assertSame(-10.0, (float) $cerrada->diferencia);
        $this->assertSame($this->cajero()->id, $cerrada->usuario_cierre_id);
    }

    /** El administrador sí ve la diferencia, y sin explicarla no cierra. */
    public function test_el_administrador_sigue_teniendo_que_explicar_la_diferencia(): void
    {
        $this->permitir();
        $sesion = $this->turnoConVenta($this->cajero());

        $this->cerrar($this->admin(), $sesion, $sesion->efectivoEsperado() - 10)
            ->assertSessionHas('error');

        $this->assertTrue($sesion->fresh()->estaAbierta());
    }

    public function test_el_cajero_no_cierra_la_caja_de_otro(): void
    {
        $this->permitir();
        $sesion = $this->turnoConVenta($this->admin());

        $this->cerrar($this->cajero(), $sesion, 100)->assertForbidden();
        $this->assertTrue($sesion->fresh()->estaAbierta());
    }

    public function test_la_opcion_se_guarda_desde_configuracion(): void
    {
        $valor = fn (string $clave) => (string) DB::table('configuracion')->where('clave', $clave)->value('valor');

        $this->actingAs($this->admin())
            ->put(route('configuracion.update'), [
                'negocio_nombre' => $valor('negocio_nombre'),
                'negocio_documento' => $valor('negocio_documento'),
                'negocio_direccion' => $valor('negocio_direccion'),
                'negocio_telefono' => $valor('negocio_telefono'),
                'moneda_codigo' => $valor('moneda_codigo'),
                'cobra_impuesto' => (float) $valor('tasa_impuesto') > 0 ? '1' : '0',
                'tasa_impuesto' => (float) $valor('tasa_impuesto') > 0 ? (string) round((float) $valor('tasa_impuesto') * 100, 2) : '13',
                'precios_incluyen_impuesto' => $valor('precios_incluyen_impuesto') === '1' ? '1' : '0',
                'descuento_max_cajero' => $valor('descuento_max_cajero'),
                'egreso_max_cajero' => $valor('egreso_max_cajero'),
                'cliente_generico_nombre' => $valor('cliente_generico_nombre'),
                'dias_max_sustitucion' => $valor('dias_max_sustitucion'),
                'exigir_referencia_pago' => $valor('exigir_referencia_pago'),
                'hora_corte_jornada' => $valor('hora_corte_jornada'),
                'cajero_cierra_su_caja' => '1',
                'serie_factura' => (string) DB::table('tipos_comprobante')->where('codigo', 'FAC')->value('serie_por_omision_id'),
                'serie_recibo' => (string) DB::table('tipos_comprobante')->where('codigo', 'REC')->value('serie_por_omision_id'),
            ])
            ->assertSessionHas('exito');

        Config::olvidar();
        $this->assertTrue(Config::cajeroCierraSuCaja());
    }

    /** Lo que se lleva el dueño en cada cierre: lo contado menos el fondo que queda. */
    public function test_el_cuadre_dice_cuanto_se_retiro(): void
    {
        $sesion = $this->turnoConVenta($this->cajero());
        $esperado = $sesion->efectivoEsperado();

        Cajas::cerrar($sesion, $this->admin(), $esperado, fondo: 50);

        $fila = $this->actingAs($this->admin())
            ->get(route('reportes.ventas', ['desde' => Config::jornadaActual(), 'hasta' => Config::jornadaActual()]))
            ->assertOk()
            ->viewData('cuadres')
            ->firstWhere('id', $sesion->id);

        $this->assertSame(round($esperado - 50, 2), (float) $fila->retirado);
        $this->assertSame('admin', $fila->cerro);
    }
}
