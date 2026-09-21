<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Cliente;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Services\Cajas;
use App\Services\Comprobantes;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Auditoría de normalización, bloque 4: el comprobante congela los datos del
 * negocio, la línea de venta ya no tiene un descuento que siempre valía cero,
 * y los documentos de identidad son una tabla y no tres ENUM repetidos.
 */
class EmisorYDocumentosTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    private function turno(): SesionCaja
    {
        $admin = $this->admin();

        return (Cajas::sesionDe($admin) ?? Cajas::abrir(Caja::firstOrFail(), $admin, 100))->fresh();
    }

    private function vender(?Cliente $cliente = null): Venta
    {
        return Ventas::registrar(
            sesion: $this->turno(),
            usuario: $this->admin(),
            lineas: [['producto_id' => Producto::where('codigo', 'P-0004')->value('id'), 'cantidad' => 3]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
            cliente: $cliente,
        );
    }

    private function cambiarNegocio(string $nombre, string $nit): void
    {
        DB::table('configuracion')->where('clave', 'negocio_nombre')->update(['valor' => $nombre]);
        DB::table('configuracion')->where('clave', 'negocio_documento')->update(['valor' => $nit]);
        Config::olvidar();
    }

    // ====================================================== B1 · el emisor

    /**
     * Un comprobante se reimprime como se entregó: con el nombre y el NIT del
     * negocio de cuando se emitió. Antes se imprimían los de HOY.
     */
    public function test_el_comprobante_se_reimprime_con_los_datos_del_negocio_de_cuando_se_emitio(): void
    {
        $this->cambiarNegocio('Pollería La Antigua', '1111111');
        $viejo = $this->vender()->comprobante;
        $this->assertSame('Pollería La Antigua', $viejo->emisor_nombre);
        $this->assertSame('1111111', $viejo->emisor_documento);
        $this->assertSame(Config::get('negocio_direccion'), $viejo->emisor_direccion);

        $this->cambiarNegocio('Restaurante La Nueva', '2222222');

        $this->actingAs($this->admin())->get(route('comprobantes.imprimir', $viejo))->assertOk()
            ->assertSee('Pollería La Antigua')
            ->assertSee('NIT 1111111')
            ->assertDontSee('Restaurante La Nueva')
            ->assertDontSee('2222222');

        // Lo que se emite ahora sale con los de ahora.
        $nuevo = $this->vender()->comprobante;
        $this->actingAs($this->admin())->get(route('comprobantes.imprimir', $nuevo))->assertOk()
            ->assertSee('Restaurante La Nueva')
            ->assertSee('NIT 2222222');
    }

    /** La sustitución emite un documento nuevo: con los datos del negocio de ese momento. */
    public function test_la_sustitucion_congela_los_datos_de_su_momento(): void
    {
        $venta = $this->vender();
        $this->cambiarNegocio('Restaurante La Nueva', '2222222');
        $empresa = Cliente::where('tipo_persona', 'JURIDICA')->where('activo', 1)->firstOrFail();

        $factura = Comprobantes::sustituir($venta->comprobante, $this->admin(), $empresa, 'El cliente pide factura');

        $this->assertSame('Restaurante La Nueva', $factura->emisor_nombre);
        $this->assertSame('2222222', $factura->emisor_documento);
        $this->assertNotSame('Restaurante La Nueva', $venta->comprobantes()->orderBy('id')->first()->emisor_nombre);
    }

    // ============================================ sin descuento por línea

    /** Valía siempre cero: el descuento es de la venta entera, nunca por plato. */
    public function test_la_linea_de_venta_no_tiene_descuento_propio(): void
    {
        $this->assertFalse(Schema::hasColumn('venta_detalle', 'descuento'));
        $this->assertNotContains('descuento', (new VentaDetalle)->getFillable());

        $linea = $this->vender()->detalle->first();
        $this->assertSame(round(3 * (float) $linea->precio_unitario, 2), round((float) $linea->importe + ($linea->impuesto_incluido ? (float) $linea->impuesto_linea : 0), 2));
    }

    // ============================================ A8 · los documentos, en una tabla

    public function test_los_documentos_son_una_tabla_con_para_quien_vale_cada_uno(): void
    {
        $tabla = TipoDocumento::orderBy('orden')->get()->keyBy('codigo');

        $this->assertSame(['CI', 'NIT', 'CE', 'PAS', 'SIN'], $tabla->keys()->all());
        $this->assertSame(['CI', 'NIT', 'CE', 'PAS', 'SIN'], array_keys(TipoDocumento::opciones('NATURAL')));
        $this->assertSame(['NIT'], array_keys(TipoDocumento::opciones('JURIDICA')));
        $this->assertSame(['CI', 'CE', 'PAS'], array_keys(TipoDocumento::opciones('EMPLEADO')));

        foreach (['clientes', 'empleados'] as $tabla) {
            $this->assertSame('varchar', DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $tabla)
                ->where('COLUMN_NAME', 'tipo_documento')->value('DATA_TYPE'), "{$tabla} sigue con ENUM");
        }
    }

    /** La base exige que el documento valga para quien lo lleva, también sin la aplicación. */
    public function test_la_base_rechaza_un_documento_que_no_corresponde(): void
    {
        $empleado = DB::table('empleados')->orderBy('id')->first();
        $this->assertThrows(fn () => DB::table('empleados')->where('id', $empleado->id)->update(['tipo_documento' => 'NIT']),
            QueryException::class, 'fk_empleados_tipodoc');

        $empresa = Cliente::where('tipo_persona', 'JURIDICA')->firstOrFail();
        $this->assertThrows(fn () => DB::table('clientes')->where('id', $empresa->id)->update(['tipo_documento' => 'CI']),
            QueryException::class, 'fk_clientes_tipodoc_juridica');

        $persona = Cliente::where('tipo_persona', 'NATURAL')->firstOrFail();
        $this->assertThrows(fn () => DB::table('clientes')->where('id', $persona->id)->update(['tipo_documento' => 'XX']),
            QueryException::class, 'fk_clientes_tipodoc_natural');
    }

    /**
     * Un documento nuevo es una fila, no un ALTER ni tres listas que cambiar:
     * la validación y el formulario lo toman de la tabla.
     */
    public function test_un_documento_nuevo_es_una_fila(): void
    {
        TipoDocumento::create(['codigo' => 'RUN', 'nombre' => 'RUN (Chile)', 'aplica_natural' => 1, 'orden' => 9]);

        $this->actingAs($this->admin())->get(route('clientes.index'))->assertOk()->assertSee('RUN (Chile)');

        $this->actingAs($this->admin())->postJson(route('clientes.store'), [
            'tipo_persona' => 'NATURAL', 'tipo_documento' => 'RUN', 'documento' => '12345678-9',
            'nombres' => 'Rosa', 'apellidos' => 'Muñoz',
        ])->assertCreated();

        $this->assertSame('RUN', Cliente::where('documento', '12345678-9')->value('tipo_documento'));

        // Pero una empresa sigue identificándose solo con lo que vale para ella.
        $this->actingAs($this->admin())->postJson(route('clientes.store'), [
            'tipo_persona' => 'JURIDICA', 'tipo_documento' => 'RUN', 'documento' => '99999999-9',
            'razon_social' => 'Otra S.A.', 'direccion' => 'Calle 1',
        ])->assertUnprocessable()->assertJsonValidationErrors('tipo_documento');
    }

    /**
     * El comprobante guarda el código como era al emitir, como texto y sin FK:
     * es una foto, no una referencia.
     */
    public function test_el_comprobante_guarda_una_foto_del_documento(): void
    {
        $columna = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', 'comprobantes')
            ->where('COLUMN_NAME', 'cliente_tipo_documento')->first(['DATA_TYPE']);
        $this->assertSame('varchar', $columna->DATA_TYPE);
        $this->assertFalse(DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', 'comprobantes')
            ->where('COLUMN_NAME', 'cliente_tipo_documento')->whereNotNull('REFERENCED_TABLE_NAME')->exists());

        $persona = Cliente::where('tipo_persona', 'NATURAL')->where('tipo_documento', 'CI')->firstOrFail();
        $this->assertSame('CI', $this->vender($persona)->comprobante->cliente_tipo_documento);
    }
}
