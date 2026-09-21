<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Usuario;
use App\Services\Cajas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Las cajas FÍSICAS del local, no los turnos (eso es `CajaTest`).
 *
 * Hasta que existió esta pantalla, agregar un segundo puesto de cobro exigía
 * entrar a MySQL a mano.
 */
class CajasDelLocalTest extends TestCase
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

    // -------------------------------------------------------------- permisos

    /**
     * Dar de alta un puesto de cobro es administrar el local, no la operación
     * diaria: el cajero abre turno todos los días pero no crea cajas, y la
     * cocina tampoco.
     */
    public function test_solo_el_administrador_entra_a_las_cajas_del_local(): void
    {
        $this->actingAs($this->admin())->get(route('cajas.index'))->assertOk();

        foreach ([$this->cajero(), $this->cocina()] as $usuario) {
            $this->actingAs($usuario)->get(route('cajas.index'))->assertForbidden();
            $this->actingAs($usuario)
                ->post(route('cajas.store'), ['nombre' => 'Caja pirata'])
                ->assertForbidden();
        }

        $this->assertDatabaseMissing('cajas', ['nombre' => 'Caja pirata']);
    }

    // ----------------------------------------------------------------- alta

    public function test_se_crea_una_caja_nueva(): void
    {
        $this->actingAs($this->admin())
            ->post(route('cajas.store'), [
                'nombre' => 'Caja rápida',
                'ubicacion' => 'Junto a la puerta',
                'activo' => 1,
            ])
            ->assertRedirect(route('cajas.index'));

        $this->assertDatabaseHas('cajas', [
            'nombre' => 'Caja rápida',
            'ubicacion' => 'Junto a la puerta',
            'activo' => 1,
        ]);

        $this->assertDatabaseHas('auditoria', [
            'accion' => 'CAJA_FISICA_CREADA',
            'entidad' => 'cajas',
        ]);
    }

    /** Dos cajas con el mismo nombre serían indistinguibles al abrir turno. */
    public function test_no_se_repite_el_nombre_de_una_caja(): void
    {
        $this->actingAs($this->admin())
            ->post(route('cajas.store'), ['nombre' => Caja::firstOrFail()->nombre])
            ->assertSessionHasErrors('nombre');
    }

    public function test_se_edita_el_nombre_y_la_ubicacion(): void
    {
        $caja = Caja::create(['nombre' => 'Caja provisional', 'ubicacion' => 'Depósito']);

        $this->actingAs($this->admin())
            ->put(route('cajas.update', $caja), [
                'nombre' => 'Caja 3',
                'ubicacion' => 'Pasillo',
                'activo' => 1,
            ])
            ->assertRedirect(route('cajas.index'));

        $this->assertSame('Caja 3', $caja->fresh()->nombre);
        $this->assertSame('Pasillo', $caja->fresh()->ubicacion);
    }

    // ------------------------------------------------------------------ baja

    /** Sin turnos detrás no hay historial que cuidar: se borra de verdad. */
    public function test_una_caja_sin_turnos_se_elimina(): void
    {
        $caja = Caja::create(['nombre' => 'Caja que sobra']);

        $this->actingAs($this->admin())
            ->delete(route('cajas.destroy', $caja))
            ->assertRedirect(route('cajas.index'));

        $this->assertDatabaseMissing('cajas', ['id' => $caja->id]);
    }

    /**
     * Con turnos detrás se desactiva: esos turnos son el respaldo de arqueos
     * ya firmados y no se pueden perder por dar de baja un mostrador.
     */
    public function test_una_caja_con_turnos_se_desactiva_en_vez_de_borrarse(): void
    {
        $caja = Caja::create(['nombre' => 'Caja con historia']);
        $sesion = Cajas::abrir($caja, $this->cajero(), 100);
        Cajas::cerrar($sesion->fresh(), $this->admin(), 100);

        $this->actingAs($this->admin())
            ->delete(route('cajas.destroy', $caja))
            ->assertRedirect(route('cajas.index'));

        $this->assertDatabaseHas('cajas', ['id' => $caja->id, 'activo' => 0]);
        $this->assertSame(1, $caja->sesiones()->count());
    }

    /** Desactivarla dejaría al cajero sin poder cerrar lo que ya cobró. */
    public function test_no_se_da_de_baja_una_caja_con_turno_abierto(): void
    {
        $caja = Caja::create(['nombre' => 'Caja ocupada']);
        Cajas::abrir($caja, $this->cajero(), 100);

        $this->actingAs($this->admin())
            ->delete(route('cajas.destroy', $caja))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('cajas', ['id' => $caja->id, 'activo' => 1]);
    }

    public function test_no_se_desactiva_por_edicion_una_caja_con_turno_abierto(): void
    {
        $caja = Caja::create(['nombre' => 'Caja en uso']);
        Cajas::abrir($caja, $this->cajero(), 100);

        $this->actingAs($this->admin())
            ->put(route('cajas.update', $caja), ['nombre' => 'Caja en uso', 'activo' => 0])
            ->assertSessionHas('error');

        $this->assertTrue((bool) $caja->fresh()->activo);
    }

    // ------------------------------------------------------------------ uso

    /** Una caja nueva tiene que quedar disponible para abrir turno en ella. */
    public function test_la_caja_nueva_sirve_para_abrir_un_turno(): void
    {
        $this->actingAs($this->admin())->post(route('cajas.store'), ['nombre' => 'Caja 9', 'activo' => 1]);

        $caja = Caja::where('nombre', 'Caja 9')->firstOrFail();
        $sesion = Cajas::abrir($caja, $this->cajero(), 50);

        $this->assertTrue($sesion->estaAbierta());
        $this->assertSame($caja->id, $sesion->caja_id);
    }
}
