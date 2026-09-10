<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProveedoresTest extends TestCase
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

    /** @return array<string, mixed> */
    private function datos(array $sobrescribir = []): array
    {
        return [
            'razon_social' => 'Distribuidora Andina S.R.L.',
            'documento' => '20778899001',
            'telefono' => '78912345',
            'email' => 'ventas@andina.test',
            'direccion' => 'Zona Industrial, Calle 4',
            'activo' => 1,
            ...$sobrescribir,
        ];
    }

    public function test_el_cajero_no_entra_a_proveedores(): void
    {
        $this->actingAs($this->cajero())->get('/proveedores')->assertForbidden();
    }

    public function test_el_almacenero_entra_a_proveedores(): void
    {
        $this->actingAs($this->almacenero())->get('/proveedores')->assertOk();
    }

    public function test_se_registra_un_proveedor(): void
    {
        $this->actingAs($this->admin())->post('/proveedores', $this->datos())
            ->assertRedirect('/proveedores');

        $proveedor = Proveedor::where('documento', '20778899001')->firstOrFail();

        $this->assertSame('Distribuidora Andina S.R.L.', $proveedor->razon_social);
        $this->assertTrue($proveedor->activo);
    }

    public function test_la_razon_social_es_obligatoria(): void
    {
        $this->actingAs($this->admin())
            ->post('/proveedores', $this->datos(['razon_social' => '']))
            ->assertSessionHasErrors(['razon_social']);
    }

    public function test_el_documento_es_opcional(): void
    {
        $this->actingAs($this->admin())
            ->post('/proveedores', $this->datos(['documento' => '']))
            ->assertRedirect('/proveedores');

        $this->assertDatabaseHas('proveedores', ['razon_social' => 'Distribuidora Andina S.R.L.', 'documento' => null]);
    }

    public function test_no_se_repite_el_documento(): void
    {
        $existente = Proveedor::first();

        $this->actingAs($this->admin())
            ->post('/proveedores', $this->datos(['documento' => $existente->documento]))
            ->assertSessionHasErrors(['documento']);
    }

    public function test_el_documento_rechaza_caracteres_raros(): void
    {
        $this->actingAs($this->admin())
            ->post('/proveedores', $this->datos(['documento' => 'NIT #20/778']))
            ->assertSessionHasErrors(['documento']);
    }

    public function test_se_actualiza_un_proveedor(): void
    {
        $proveedor = Proveedor::first();

        $this->actingAs($this->admin())
            ->put("/proveedores/{$proveedor->id}", $this->datos([
                'razon_social' => 'Distribuidora Andina Actualizada S.R.L.',
                'documento' => $proveedor->documento,
            ]))
            ->assertRedirect('/proveedores');

        $this->assertSame('Distribuidora Andina Actualizada S.R.L.', $proveedor->fresh()->razon_social);
    }

    /** Con productos a su nombre, el proveedor se desactiva: el kardex lo referencia. */
    public function test_un_proveedor_con_productos_se_desactiva_en_vez_de_borrarse(): void
    {
        $proveedor = Proveedor::create($this->datos(['documento' => '20111222333']));

        Producto::create([
            'categoria_id' => Categoria::first()->id,
            'unidad_medida_id' => UnidadMedida::where('codigo', 'UND')->first()->id,
            'proveedor_id' => $proveedor->id,
            'codigo' => 'P-9911',
            'codigo_barras' => '7750009991199',
            'nombre' => 'Producto de prueba con proveedor',
            'precio_compra' => '5.00',
            'precio_venta' => '8.00',
            'afecto_impuesto' => 1,
            'stock_minimo' => '10',
            'activo' => 1,
        ]);

        $this->actingAs($this->admin())->delete("/proveedores/{$proveedor->id}")
            ->assertRedirect('/proveedores');

        $proveedor->refresh();
        $this->assertFalse($proveedor->activo);
        $this->assertNotNull($proveedor->fresh());
    }

    public function test_un_proveedor_sin_productos_se_elimina(): void
    {
        $proveedor = Proveedor::create($this->datos(['documento' => '20444555666']));

        $this->actingAs($this->admin())->delete("/proveedores/{$proveedor->id}")
            ->assertRedirect('/proveedores');

        $this->assertDatabaseMissing('proveedores', ['id' => $proveedor->id]);
    }

    public function test_la_busqueda_encuentra_por_razon_social_o_documento(): void
    {
        $response = $this->actingAs($this->almacenero())->get('/proveedores?buscar='.urlencode(Proveedor::first()->documento));

        $response->assertOk();
        $response->assertSee(Proveedor::first()->razon_social);
    }
}
