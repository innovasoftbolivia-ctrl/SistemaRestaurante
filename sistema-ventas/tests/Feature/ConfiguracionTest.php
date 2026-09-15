<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La pantalla de configuración del negocio.
 *
 * Hasta que existió, los doce valores de `configuracion` solo se cambiaban por
 * SQL, y el nombre, el NIT, la dirección y el teléfono salen en cada
 * comprobante: no se le podía instalar el sistema a otro negocio sin entrar a
 * la base.
 */
class ConfiguracionTest extends TestCase
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

    private function almacenero(): Usuario
    {
        return Usuario::where('usuario', 'almacen')->firstOrFail();
    }

    private function serie(string $tipo): int
    {
        return (int) DB::table('series_comprobante as s')
            ->join('tipos_comprobante as t', 't.id', '=', 's.tipo_comprobante_id')
            ->where('t.codigo', $tipo)
            ->where('s.activo', 1)
            ->value('s.id');
    }

    /**
     * Lo que muestra hoy la pantalla, tal como se enviaría sin tocar nada.
     *
     * @return array<string, string>
     */
    private function actuales(): array
    {
        $valor = fn (string $clave) => (string) DB::table('configuracion')->where('clave', $clave)->value('valor');

        return [
            'negocio_nombre' => $valor('negocio_nombre'),
            'negocio_documento' => $valor('negocio_documento'),
            'negocio_direccion' => $valor('negocio_direccion'),
            'negocio_telefono' => $valor('negocio_telefono'),
            'moneda_codigo' => $valor('moneda_codigo'),
            'tasa_impuesto' => rtrim(rtrim(number_format((float) $valor('tasa_impuesto') * 100, 2, '.', ''), '0'), '.'),
            'descuento_max_cajero' => $valor('descuento_max_cajero'),
            'egreso_max_cajero' => $valor('egreso_max_cajero'),
            'cliente_generico_nombre' => $valor('cliente_generico_nombre'),
            'dias_max_sustitucion' => $valor('dias_max_sustitucion'),
            'dias_max_devolucion' => $valor('dias_max_devolucion'),
            'exigir_referencia_pago' => $valor('exigir_referencia_pago'),
            'serie_factura' => $valor('serie_factura'),
            'serie_recibo' => $valor('serie_recibo'),
        ];
    }

    /** @param  array<string, string>  $cambios */
    private function guardar(array $cambios, ?Usuario $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->admin())
            ->put(route('configuracion.update'), array_merge($this->actuales(), $cambios));
    }

    private function venta(): Venta
    {
        $cajero = $this->cajero();
        $sesion = Cajas::sesionDe($cajero) ?? Cajas::abrir(Caja::firstOrFail(), $cajero, 100);
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();

        return Ventas::registrar(
            sesion: $sesion->fresh(),
            usuario: $cajero,
            lineas: [['producto_id' => $producto->id, 'cantidad' => 1]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
    }

    // -------------------------------------------------------------- permisos

    public function test_solo_quien_configura_el_sistema_entra(): void
    {
        $this->actingAs($this->admin())->get(route('configuracion.edit'))->assertOk();

        $nombre = $this->actuales()['negocio_nombre'];

        foreach ([$this->cajero(), $this->almacenero()] as $usuario) {
            $this->actingAs($usuario)->get(route('configuracion.edit'))->assertForbidden();
            $this->guardar(['negocio_nombre' => 'Negocio pirata'], $usuario)->assertForbidden();
        }

        $this->assertSame($nombre, $this->actuales()['negocio_nombre']);
    }

    public function test_la_pantalla_muestra_los_valores_de_hoy(): void
    {
        $actual = $this->actuales();

        $this->actingAs($this->admin())
            ->get(route('configuracion.edit'))
            ->assertOk()
            ->assertSee($actual['negocio_nombre'])
            ->assertSee($actual['negocio_documento'])
            ->assertSee('value="'.$actual['tasa_impuesto'].'"', false);
    }

    // --------------------------------------------------------------- guardar

    public function test_se_guardan_los_datos_del_negocio(): void
    {
        $this->guardar([
            'negocio_nombre' => 'Ferretería La Llave',
            'negocio_documento' => '4567891012',
            'negocio_direccion' => 'Calle Warnes 55, Santa Cruz',
            'negocio_telefono' => '3 344 5566',
            'moneda_codigo' => 'USD',
            'tasa_impuesto' => '13',
            'descuento_max_cajero' => '5',
            'dias_max_sustitucion' => '3',
            'dias_max_devolucion' => '15',
            'exigir_referencia_pago' => '0',
        ])->assertRedirect(route('configuracion.edit'))->assertSessionHas('exito');

        $guardado = DB::table('configuracion')->pluck('valor', 'clave');

        $this->assertSame('Ferretería La Llave', $guardado['negocio_nombre']);
        $this->assertSame('4567891012', $guardado['negocio_documento']);
        $this->assertSame('Calle Warnes 55, Santa Cruz', $guardado['negocio_direccion']);
        $this->assertSame('3 344 5566', $guardado['negocio_telefono']);
        $this->assertSame('5', $guardado['descuento_max_cajero']);
        $this->assertSame('3', $guardado['dias_max_sustitucion']);
        $this->assertSame('15', $guardado['dias_max_devolucion']);
        $this->assertSame('0', $guardado['exigir_referencia_pago']);

        // La tasa se escribe como porcentaje y se guarda como la fracción que
        // ya leía el resto del sistema.
        $this->assertSame('0.1300', $guardado['tasa_impuesto']);
        $this->assertSame(0.13, Config::tasaImpuesto());

        // El símbolo sale del código: no pueden quedar desparejos.
        $this->assertSame('USD', $guardado['moneda_codigo']);
        $this->assertSame('$', $guardado['moneda_simbolo']);
    }

    /** Lo que importa de la pantalla: que el papel cambie. */
    public function test_el_comprobante_sale_con_los_datos_nuevos(): void
    {
        $this->guardar([
            'negocio_nombre' => 'Ferretería La Llave',
            'negocio_documento' => '4567891012',
        ])->assertRedirect();

        $venta = $this->venta();

        $this->actingAs($this->admin())
            ->get(route('comprobantes.imprimir', $venta->comprobante))
            ->assertOk()
            ->assertSee('Ferretería La Llave')
            ->assertSee('4567891012');
    }

    /**
     * Cambiar la tasa hoy no reescribe lo vendido ayer: cada línea congela la
     * tasa con la que se vendió.
     */
    public function test_la_tasa_nueva_no_cambia_lo_ya_vendido(): void
    {
        $antes = Config::tasaImpuesto();
        $vieja = $this->venta();
        $tasaVieja = (float) $vieja->detalle()->value('tasa_impuesto');

        $nuevaPorcentaje = abs($antes - 0.15) < 0.0001 ? '10' : '15';
        $this->guardar(['tasa_impuesto' => $nuevaPorcentaje])->assertRedirect();

        $nueva = $this->venta();

        $this->assertEqualsWithDelta($antes, $tasaVieja, 0.00005, 'la venta de antes no tomó la tasa de antes');
        $this->assertEqualsWithDelta($tasaVieja, (float) $vieja->detalle()->value('tasa_impuesto'), 0.00005,
            'cambiar la configuración reescribió una venta ya hecha');
        $this->assertEqualsWithDelta((float) $nuevaPorcentaje / 100, (float) $nueva->detalle()->value('tasa_impuesto'), 0.00005,
            'la venta nueva no tomó la tasa nueva');
    }

    // ----------------------------------------------------------- validación

    public function test_el_nit_lleva_solo_digitos(): void
    {
        $this->guardar(['negocio_documento' => '1023-456'])->assertSessionHasErrors('negocio_documento');
        $this->guardar(['negocio_documento' => ''])->assertSessionHasErrors('negocio_documento');
    }

    public function test_la_tasa_es_un_porcentaje_entre_cero_y_cien(): void
    {
        $this->guardar(['tasa_impuesto' => '130'])->assertSessionHasErrors('tasa_impuesto');
        $this->guardar(['tasa_impuesto' => '-1'])->assertSessionHasErrors('tasa_impuesto');
        $this->guardar(['tasa_impuesto' => '13.125'])->assertSessionHasErrors('tasa_impuesto');
    }

    /** Sin esto las facturas saldrían numeradas con la serie de los recibos. */
    public function test_no_se_elige_una_serie_de_otro_tipo(): void
    {
        $this->guardar(['serie_factura' => (string) $this->serie('REC')])->assertSessionHasErrors('serie_factura');
        $this->guardar(['serie_recibo' => (string) $this->serie('FAC')])->assertSessionHasErrors('serie_recibo');
    }

    public function test_no_se_elige_una_moneda_que_el_sistema_no_sabe_mostrar(): void
    {
        $this->guardar(['moneda_codigo' => 'XYZ'])->assertSessionHasErrors('moneda_codigo');
    }

    // ------------------------------------------------------------- bitácora

    public function test_la_bitacora_guarda_solo_lo_que_cambio_con_su_valor_anterior(): void
    {
        $anterior = $this->actuales()['negocio_nombre'];

        $this->guardar(['negocio_nombre' => 'Otro nombre'])->assertRedirect();

        $registro = Auditoria::where('accion', 'CONFIGURACION_ACTUALIZADA')->latest('id')->firstOrFail();

        $this->assertSame(['negocio_nombre'], array_keys($registro->detalle));
        $this->assertSame($anterior, $registro->detalle['negocio_nombre']['antes']);
        $this->assertSame('Otro nombre', $registro->detalle['negocio_nombre']['despues']);
        $this->assertSame($this->admin()->id, $registro->usuario_id);
    }

    /** Apretar Guardar por las dudas no debe llenar la bitácora de entradas vacías. */
    public function test_guardar_sin_cambios_no_deja_rastro(): void
    {
        $antes = Auditoria::where('accion', 'CONFIGURACION_ACTUALIZADA')->count();

        $this->guardar([])->assertRedirect()->assertSessionHas('aviso');

        $this->assertSame($antes, Auditoria::where('accion', 'CONFIGURACION_ACTUALIZADA')->count());
    }
}
