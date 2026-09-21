<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Support\CajasOlvidadas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El aviso de turno de caja olvidado.
 *
 * En la base de desarrollo apareció un turno abierto desde hacía dos días, sin
 * que ninguna pantalla lo advirtiera: nadie entra a la pantalla de caja a
 * buscar lo que no sabe que existe. El aviso sale en todas las pantallas.
 */
class CajaOlvidadaTest extends TestCase
{
    use DatabaseTransactions;

    private const MARCA = 'data-aviso="caja-olvidada"';

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

    /** Un turno abierto hace $horas horas. */
    private function turnoDeHace(Usuario $usuario, float $horas): SesionCaja
    {
        $sesion = Cajas::abrir(Caja::firstOrFail(), $usuario, 100);

        DB::table('sesiones_caja')->where('id', $sesion->id)
            ->update(['fecha_apertura' => now()->subMinutes((int) round($horas * 60))]);

        return $sesion->fresh();
    }

    public function test_el_administrador_ve_el_turno_olvidado_en_cualquier_pantalla(): void
    {
        $sesion = $this->turnoDeHace($this->cajero(), CajasOlvidadas::HORAS + 1);

        foreach (['/inicio', '/menu', '/ventas'] as $pantalla) {
            $this->actingAs($this->admin())
                ->get($pantalla)
                ->assertOk()
                ->assertSee(self::MARCA, false)
                ->assertSee('Hay un turno de caja abierto hace más de '.CajasOlvidadas::HORAS.' horas')
                ->assertSee('cajero1')
                ->assertSee(route('caja.show', $sesion), false);
        }
    }

    /** El cajero no puede cerrarlo, pero sí avisar: ve el suyo. */
    public function test_el_cajero_ve_su_propio_turno_olvidado(): void
    {
        $sesion = $this->turnoDeHace($this->cajero(), CajasOlvidadas::HORAS + 5);

        $this->actingAs($this->cajero())
            ->get('/pos')
            ->assertOk()
            ->assertSee(self::MARCA, false)
            ->assertSee('Tu turno en')
            ->assertSee($sesion->fecha_apertura->format('d/m/Y'))
            ->assertSee('Pide a un administrador');
    }

    /** Un turno de otro no es asunto del cajero: no puede hacer nada con él. */
    public function test_el_cajero_no_ve_el_turno_olvidado_de_otro(): void
    {
        $this->turnoDeHace($this->admin(), CajasOlvidadas::HORAS + 1);

        $this->actingAs($this->cajero())
            ->get('/pos')
            ->assertOk()
            ->assertDontSee(self::MARCA, false);
    }

    public function test_quien_no_maneja_caja_no_ve_nada(): void
    {
        $this->turnoDeHace($this->cajero(), CajasOlvidadas::HORAS + 1);

        // La cocina no maneja caja: su pantalla no lleva el aviso.
        $this->actingAs($this->cocina())
            ->get('/perfil')
            ->assertOk()
            ->assertDontSee(self::MARCA, false);
    }

    /** Un turno largo no es un turno olvidado. */
    public function test_un_turno_reciente_no_avisa(): void
    {
        $this->turnoDeHace($this->cajero(), CajasOlvidadas::HORAS - 1);

        $this->actingAs($this->admin())
            ->get('/inicio')
            ->assertOk()
            ->assertDontSee(self::MARCA, false);
    }

    public function test_al_cerrarlo_el_aviso_desaparece(): void
    {
        $sesion = $this->turnoDeHace($this->cajero(), CajasOlvidadas::HORAS + 1);

        $this->actingAs($this->admin())->get('/inicio')->assertSee(self::MARCA, false);

        $sesion = $sesion->fresh();
        Cajas::cerrar($sesion, $this->admin(), (float) $sesion->efectivoEsperado(), 'Cierre del turno olvidado');

        $this->actingAs($this->admin())->get('/inicio')->assertDontSee(self::MARCA, false);
    }
}
