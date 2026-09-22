<?php

namespace Tests\Feature;

use App\Models\ArqueoCaja;
use App\Models\Caja;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Services\Cajas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

/**
 * El arqueo por billetes y monedas: el cajero cuenta cuántos hay de cada uno,
 * el sistema suma, y el detalle queda con el turno y sale en el resumen.
 */
class ArqueoPorBilletesTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    /** Un turno con Bs 287.50 de fondo: lo esperado al cerrar. */
    private function turno(): SesionCaja
    {
        return Cajas::abrir(Caja::create(['nombre' => 'Caja arqueo', 'activo' => 1]), $this->admin(), 287.50);
    }

    public function test_el_formulario_de_cierre_trae_el_conteo_por_billetes(): void
    {
        $sesion = $this->turno();

        $this->actingAs($this->admin())->get(route('caja.show', $sesion))
            ->assertOk()
            ->assertSee('data-arqueo-billetes', false)
            ->assertSee('name="arqueo[200]"', false)
            ->assertSee('name="arqueo[0.5]"', false)
            ->assertSee('name="arqueo[0.1]"', false);
    }

    /** 1×200 + 1×50 + 1×20 + 1×10 + 3×2 + 1×1 + 1×0.50 = 287.50: cuadra. */
    public function test_el_efectivo_contado_es_la_suma_del_arqueo_y_queda_guardado(): void
    {
        $sesion = $this->turno();

        $this->actingAs($this->admin())->post(route('caja.cerrar', $sesion), [
            'huella' => $sesion->fresh()->huella(),
            'fondo_dejado' => 0,
            // Lo que haya escrito la pantalla no manda: manda la suma.
            'monto_declarado' => 999,
            'arqueo' => ['200' => 1, '100' => '', '50' => 1, '20' => 1, '10' => 1, '2' => 3, '1' => 1, '0.5' => 1, '0.1' => 0],
        ])->assertRedirect(route('caja.imprimir', $sesion));

        $sesion = $sesion->fresh();
        $this->assertSame('CERRADA', $sesion->estado);
        $this->assertSame(287.50, (float) $sesion->monto_declarado);
        $this->assertSame(0.0, (float) $sesion->diferencia);

        // Solo lo que se contó, de mayor a menor.
        $this->assertSame(
            [[200.0, 1], [50.0, 1], [20.0, 1], [10.0, 1], [2.0, 3], [1.0, 1], [0.5, 1]],
            $sesion->arqueo->map(fn (ArqueoCaja $l) => [$l->denominacion, $l->cantidad])->all(),
        );

        $this->actingAs($this->admin())->get(route('caja.imprimir', $sesion))
            ->assertOk()
            ->assertSee('data-arqueo-impreso', false)
            ->assertSee('× 3')
            ->assertSee('Bs 287.50');
    }

    public function test_escribir_el_total_sigue_funcionando_sin_arqueo(): void
    {
        $sesion = $this->turno();

        $this->actingAs($this->admin())->post(route('caja.cerrar', $sesion), [
            'huella' => $sesion->fresh()->huella(), 'fondo_dejado' => 0, 'monto_declarado' => 287.50,
        ])->assertRedirect(route('caja.imprimir', $sesion));

        $this->assertSame(0, $sesion->fresh()->arqueo()->count());
        $this->actingAs($this->admin())->get(route('caja.imprimir', $sesion))
            ->assertOk()->assertDontSee('data-arqueo-impreso', false);
    }

    public function test_un_billete_que_no_existe_no_cierra(): void
    {
        $sesion = $this->turno();

        $this->actingAs($this->admin())->post(route('caja.cerrar', $sesion), [
            'huella' => $sesion->fresh()->huella(), 'fondo_dejado' => 0, 'monto_declarado' => 3,
            'arqueo' => ['3' => 1],
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'No existe el billete o la moneda de 3'));

        $this->assertTrue($sesion->fresh()->estaAbierta());
        $this->assertSame(0, $sesion->fresh()->arqueo()->count());
    }

    /** Llamado desde otro lado, el servicio exige que el arqueo cuadre con lo declarado. */
    public function test_el_servicio_rechaza_un_arqueo_que_no_suma_lo_declarado(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('El arqueo suma Bs 200.00 y el efectivo contado dice Bs 287.50');

        Cajas::arqueoValido(['200' => 1], 287.50);
    }
}
