<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
    public function test_el_almacenero_entra_a_clientes(): void
    {
        $this->actingAs($this->almacenero())->get('/clientes')->assertOk();
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
}
