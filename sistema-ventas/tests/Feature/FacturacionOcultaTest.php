<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Cliente;
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
 * Mientras el negocio no factura, las pantallas no hablan de impuesto, IVA ni
 * facturas. El código sigue entero —las demás pruebas lo ejercitan con
 * `MOSTRAR_FACTURACION=true`—; aquí se prueba lo que ve el cliente con el
 * interruptor apagado, que es como se instala.
 */
class FacturacionOcultaTest extends TestCase
{
    use DatabaseTransactions;

    /** Frases que no pueden aparecer en ninguna pantalla con la facturación oculta. */
    private const FRASES = [
        'Recibe factura',
        'recibe factura',
        '— factura',
        'IVA incluido',
        'Régimen tributario',
        'Impuesto y precios',
        'Serie de facturas',
        'base imponible',
        'Impuesto reintegrado',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['ventas.mostrar_facturacion' => false]);
        DB::table('configuracion')->where('clave', 'tasa_impuesto')->update(['valor' => '0.0000']);
        Config::olvidar();
    }

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    private function venderA(?Cliente $cliente): Venta
    {
        $admin = $this->admin();

        return Ventas::registrar(
            sesion: (Cajas::sesionDe($admin) ?? Cajas::abrir(Caja::firstOrFail(), $admin, 100))->fresh(),
            usuario: $admin,
            lineas: [['producto_id' => Producto::where('codigo', 'P-0004')->value('id'), 'cantidad' => 1]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
            cliente: $cliente,
        );
    }

    private function empresa(): Cliente
    {
        return Cliente::where('tipo_persona', 'JURIDICA')->where('activo', 1)->firstOrFail();
    }

    public function test_las_pantallas_no_hablan_de_impuesto_ni_de_facturas(): void
    {
        $venta = $this->venderA($this->empresa());

        $pantallas = [
            'mostrador' => route('pos.index'),
            'venta' => route('ventas.show', $venta),
            'ventas' => route('ventas.index'),
            'clientes' => route('clientes.index'),
            'configuración' => route('configuracion.edit'),
            'comprobantes' => route('comprobantes.index'),
            'comprobante impreso' => route('comprobantes.imprimir', $venta->comprobante),
            'reporte de ventas' => route('reportes.ventas'),
            'reporte de productos' => route('reportes.productos'),
        ];

        foreach ($pantallas as $nombre => $url) {
            $html = $this->actingAs($this->admin())->get($url)->assertOk()->getContent();

            foreach (self::FRASES as $frase) {
                $this->assertStringNotContainsString($frase, $html, "«{$frase}» aparece en {$nombre}");
            }
        }
    }

    /**
     * Sin facturación nadie recibe factura. La persona con NIT recibe recibo;
     * la empresa, nota de venta: el recibo es solo para personas naturales y
     * la base lo rechaza para una jurídica.
     */
    public function test_sin_facturacion_nadie_recibe_factura(): void
    {
        $this->assertSame('NV', $this->venderA($this->empresa())->comprobante->serie->tipo->codigo);
        $this->assertSame('REC', $this->venderA(null)->comprobante->serie->tipo->codigo);

        $unipersonal = Cliente::where('tipo_persona', 'NATURAL')->where('activo', 1)->firstOrFail();
        $unipersonal->forceFill(['tipo_documento' => 'NIT', 'documento' => '1234567019'])->save();
        $this->assertSame('REC', $this->venderA($unipersonal->fresh())->comprobante->serie->tipo->codigo);
    }

    /** Lo oculto viaja en campos escondidos: guardar la configuración no toca el impuesto. */
    public function test_la_configuracion_se_guarda_con_el_apartado_oculto(): void
    {
        $valor = fn (string $clave) => (string) DB::table('configuracion')->where('clave', $clave)->value('valor');
        $antes = ['tasa_impuesto' => $valor('tasa_impuesto'), 'precios_incluyen_impuesto' => $valor('precios_incluyen_impuesto')];

        $html = $this->actingAs($this->admin())->get(route('configuracion.edit'))->assertOk()->getContent();

        // Lo que manda el navegador: los campos escondidos tal como vienen.
        preg_match_all('/<input type="hidden" name="([a-z_]+)" value="([^"]*)"/', $html, $escondidos, PREG_SET_ORDER);
        $formulario = collect($escondidos)->mapWithKeys(fn ($m) => [$m[1] => html_entity_decode($m[2])])->except('_token', '_method')->all();

        foreach (['cobra_impuesto', 'tasa_impuesto', 'precios_incluyen_impuesto', 'serie_factura', 'serie_recibo', 'dias_max_sustitucion'] as $campo) {
            $this->assertArrayHasKey($campo, $formulario, "falta el campo escondido {$campo}");
        }

        $this->actingAs($this->admin())->put(route('configuracion.update'), $formulario + [
            'negocio_nombre' => 'Minimarket El Ahorro',
            'negocio_documento' => $valor('negocio_documento'),
            'negocio_direccion' => $valor('negocio_direccion'),
            'negocio_telefono' => $valor('negocio_telefono'),
            'moneda_codigo' => $valor('moneda_codigo'),
            'descuento_max_cajero' => $valor('descuento_max_cajero'),
            'egreso_max_cajero' => $valor('egreso_max_cajero'),
            'cliente_generico_nombre' => $valor('cliente_generico_nombre'),
            'exigir_referencia_pago' => $valor('exigir_referencia_pago'),
        ])->assertSessionHasNoErrors();

        $this->assertSame('Minimarket El Ahorro', $valor('negocio_nombre'));
        $this->assertSame($antes, ['tasa_impuesto' => $valor('tasa_impuesto'), 'precios_incluyen_impuesto' => $valor('precios_incluyen_impuesto')]);
    }

    /** Con el interruptor encendido todo vuelve: el código nunca se fue. */
    public function test_al_encender_el_interruptor_vuelve_la_facturacion(): void
    {
        config(['ventas.mostrar_facturacion' => true]);

        $this->assertSame('FAC', $this->venderA($this->empresa())->comprobante->serie->tipo->codigo);
        $this->actingAs($this->admin())->get(route('configuracion.edit'))->assertOk()->assertSee('Impuesto y precios');
    }
}
