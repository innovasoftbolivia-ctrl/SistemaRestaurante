<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Ventas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `PuntoDeVentaTest` ya cubre las reglas de persona natural/jurídica (NIT,
 * dirección) al registrar un cliente. Esto cubre lo que falta: permisos,
 * actualización, baja, unicidad de documento, y el alta rápida en JSON que
 * usa el mostrador para no perder el carrito en curso.
 */
class ClientesTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    private function almacenero(): Usuario
    {
        return Usuario::where('usuario', 'almacen')->firstOrFail();
    }

    private function cajero(): Usuario
    {
        return Usuario::where('usuario', 'cajero1')->firstOrFail();
    }

    // ------------------------------------------------------------- permisos

    /** Ve a los clientes por su `reportes.ver`, aunque no venda desde el mostrador. */
    /** Los clientes son del mostrador y de la administración, no del almacén. */
    public function test_el_almacenero_no_entra_a_clientes(): void
    {
        $this->actingAs($this->almacenero())->get('/clientes')->assertForbidden();
    }

    public function test_el_cajero_entra_a_clientes(): void
    {
        $this->actingAs($this->cajero())->get('/clientes')->assertOk();
    }

    /**
     * Ver clientes y crear/editar/borrar clientes son cosas distintas: el
     * almacenero entra a la pantalla por `reportes.ver` (arriba), pero eso no
     * debería alcanzar para mutar clientes — esa es una acción de venta
     * (`ventas.registrar`), no de reportes.
     */
    public function test_el_almacenero_no_puede_mutar_clientes(): void
    {
        $cliente = Cliente::where('tipo_persona', 'NATURAL')->firstOrFail();

        $this->actingAs($this->almacenero())
            ->post('/clientes', [
                'tipo_persona' => 'NATURAL',
                'tipo_documento' => 'CI',
                'documento' => '99999999',
                'nombres' => 'Alguien',
                'apellidos' => 'Nuevo',
            ])
            ->assertForbidden();

        $this->actingAs($this->almacenero())
            ->put("/clientes/{$cliente->id}", [
                'tipo_persona' => 'NATURAL',
                'tipo_documento' => $cliente->tipo_documento,
                'documento' => $cliente->documento,
                'nombres' => 'Intento de edición',
                'apellidos' => $cliente->apellidos,
            ])
            ->assertForbidden();

        $this->actingAs($this->almacenero())
            ->delete("/clientes/{$cliente->id}")
            ->assertForbidden();
    }

    // ---------------------------------------------------------------- CRUD

    public function test_se_actualiza_un_cliente(): void
    {
        $cliente = Cliente::where('tipo_persona', 'NATURAL')->firstOrFail();

        $this->actingAs($this->admin())
            ->put("/clientes/{$cliente->id}", [
                'tipo_persona' => 'NATURAL',
                'tipo_documento' => $cliente->tipo_documento,
                'documento' => $cliente->documento,
                'nombres' => 'Nombre Actualizado',
                'apellidos' => $cliente->apellidos,
            ])
            ->assertRedirect('/clientes');

        $this->assertSame('Nombre Actualizado', $cliente->fresh()->nombres);
    }

    public function test_un_cliente_sin_compras_se_elimina(): void
    {
        $cliente = Cliente::create([
            'tipo_persona' => 'NATURAL',
            'tipo_documento' => 'CI',
            'documento' => '99887766',
            'nombres' => 'Cliente',
            'apellidos' => 'Sin Compras',
            'activo' => 1,
        ]);

        $this->actingAs($this->admin())->delete("/clientes/{$cliente->id}")
            ->assertRedirect('/clientes');

        $this->assertDatabaseMissing('clientes', ['id' => $cliente->id]);
    }

    public function test_no_se_repite_el_documento_del_mismo_tipo(): void
    {
        $existente = Cliente::where('tipo_persona', 'NATURAL')->whereNotNull('documento')->firstOrFail();

        $this->actingAs($this->admin())->post('/clientes', [
            'tipo_persona' => 'NATURAL',
            'tipo_documento' => $existente->tipo_documento,
            'documento' => $existente->documento,
            'nombres' => 'Otro',
            'apellidos' => 'Cliente',
        ])->assertSessionHasErrors(['documento']);
    }

    // ------------------------------------------------- alta rápida (mostrador)

    public function test_el_alta_rapida_responde_json_y_no_redirige(): void
    {
        $respuesta = $this->actingAs($this->cajero())
            ->postJson('/clientes', [
                'tipo_persona' => 'NATURAL',
                'tipo_documento' => 'CI',
                'documento' => '55667788',
                'nombres' => 'Pedro',
                'apellidos' => 'Quiroga',
            ]);

        $respuesta->assertCreated();
        $respuesta->assertJson([
            'nombre' => 'Pedro Quiroga',
            'etiqueta' => 'Pedro Quiroga · CI 55667788',
            'juridica' => false,
        ]);
        $this->assertIsInt($respuesta->json('id'));
    }

    /** El nombre lo arma una columna generada por MySQL: sin refrescar el modelo, sale vacío. */
    public function test_el_alta_rapida_trae_el_nombre_ya_armado(): void
    {
        $respuesta = $this->actingAs($this->cajero())->postJson('/clientes', [
            'tipo_persona' => 'JURIDICA',
            'tipo_documento' => 'NIT',
            'documento' => '20333444555',
            'razon_social' => 'Ferretería Central S.A.C.',
            'direccion' => 'Av. Ferretera 200',
        ]);

        $respuesta->assertCreated();
        $this->assertNotEmpty($respuesta->json('nombre'));
        $this->assertTrue($respuesta->json('juridica'));
    }

    /** Venta al paso: el cliente solo quiere que el recibo diga su nombre. */
    public function test_el_alta_rapida_admite_persona_natural_sin_documento(): void
    {
        $respuesta = $this->actingAs($this->cajero())->postJson('/clientes', [
            'tipo_persona' => 'NATURAL',
            'tipo_documento' => 'CI',
            'documento' => '',
            'nombres' => 'Cliente',
            'apellidos' => 'Sin Documento',
        ]);

        $respuesta->assertCreated();
        $respuesta->assertJson(['etiqueta' => 'Cliente Sin Documento']);
    }

    public function test_el_alta_rapida_devuelve_422_con_los_errores(): void
    {
        $respuesta = $this->actingAs($this->cajero())->postJson('/clientes', [
            'tipo_persona' => 'JURIDICA',
            'tipo_documento' => 'NIT',
            'documento' => '',
            'razon_social' => 'Sin NIT S.R.L.',
        ]);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonValidationErrors(['documento', 'direccion']);
    }
    // ================================================================ documentos bolivianos

    /** @return TestResponse<JsonResponse> */
    private function alta(string $tipo, string $documento)
    {
        return $this->actingAs($this->cajero())->postJson('/clientes', $tipo === 'NIT'
            ? ['tipo_persona' => 'JURIDICA', 'tipo_documento' => 'NIT', 'documento' => $documento, 'razon_social' => 'Prueba S.R.L.', 'direccion' => 'Av. Busch 100']
            : ['tipo_persona' => 'NATURAL', 'tipo_documento' => $tipo, 'documento' => $documento, 'nombres' => 'Ana', 'apellidos' => 'Rojas']);
    }

    public function test_el_nit_lleva_solo_numeros(): void
    {
        $this->alta('NIT', 'ABC123')->assertJsonValidationErrors(['documento' => 'solo números']);
        $this->alta('NIT', '1023456027')->assertCreated();
    }

    public function test_el_ci_admite_complemento_y_extension_pero_no_letras_sueltas(): void
    {
        $this->alta('CI', 'ABC')->assertJsonValidationErrors('documento');
        $this->alta('CI', '1234567 LP')->assertCreated();
        $this->alta('CI', '7654321-1A')->assertCreated();
    }

    /**
     * Un cliente que solo quedó en un comprobante sustituido (la venta pasó a
     * otro cliente) daba error 500 al borrarlo. Se desactiva, como el que tiene compras.
     */
    public function test_un_cliente_con_comprobantes_se_desactiva_en_lugar_de_romper(): void
    {
        $cliente = Cliente::where('tipo_persona', 'JURIDICA')->firstOrFail();
        $cajero = $this->cajero();
        $sesion = Cajas::sesionDe($cajero) ?? Cajas::abrir(Caja::firstOrFail(), $cajero, 100);
        $venta = Ventas::registrar(
            sesion: $sesion, usuario: $cajero,
            lineas: [['producto_id' => Producto::where('codigo', 'P-0004')->value('id'), 'cantidad' => 1]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
            cliente: $cliente,
        );

        // Lo que deja una sustitución a nombre de otro: la venta ya no apunta
        // al cliente, pero su comprobante viejo sí.
        Venta::whereKey($venta->id)->update(['cliente_id' => null]);
        $this->assertTrue(DB::table('comprobantes')->where('cliente_id', $cliente->id)->exists());

        $this->actingAs($this->admin())->delete(route('clientes.destroy', $cliente))
            ->assertRedirect()->assertSessionHas('exito', fn ($m) => str_contains($m, 'se desactivó'));

        $this->assertFalse((bool) $cliente->fresh()->activo);
    }

    // ================================================================ unipersonal con NIT

    /**
     * Un unipersonal pide factura con su NIT y su nombre: no hace falta
     * registrarlo como empresa, con razón social y dirección.
     */
    public function test_una_persona_natural_con_nit_recibe_factura(): void
    {
        $this->actingAs($this->cajero())->postJson('/clientes', [
            'tipo_persona' => 'NATURAL', 'tipo_documento' => 'NIT', 'documento' => '',
            'nombres' => 'Rosa', 'apellidos' => 'Vaca Suárez',
        ])->assertJsonValidationErrors(['documento' => 'NIT']);

        $this->actingAs($this->cajero())->postJson('/clientes', [
            'tipo_persona' => 'NATURAL', 'tipo_documento' => 'NIT', 'documento' => '4455667011',
            'nombres' => 'Rosa', 'apellidos' => 'Vaca Suárez',
        ])->assertCreated()->assertJson(['juridica' => false, 'factura' => true]);

        $cliente = Cliente::where('documento', '4455667011')->firstOrFail();
        $cajero = $this->cajero();
        $venta = Ventas::registrar(
            sesion: Cajas::sesionDe($cajero) ?? Cajas::abrir(Caja::firstOrFail(), $cajero, 100),
            usuario: $cajero,
            lineas: [['producto_id' => Producto::where('codigo', 'P-0004')->value('id'), 'cantidad' => 1]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
            cliente: $cliente,
        );

        $comprobante = $venta->comprobante;
        $this->assertSame('FAC', $comprobante->serie->tipo->codigo);
        $this->assertSame('Rosa Vaca Suárez', $comprobante->cliente_nombre);
        $this->assertSame('NIT', $comprobante->cliente_tipo_documento);
        $this->assertSame('4455667011', $comprobante->cliente_documento);

        // Con CI sigue siendo recibo.
        $conCi = Cliente::where('tipo_persona', 'NATURAL')->where('tipo_documento', 'CI')->firstOrFail();
        $this->assertSame('REC', Ventas::seriePara($conCi)->tipo->codigo);
    }
}
