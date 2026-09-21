<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La marca de quien desarrolló el sistema: una línea discreta, que se apaga
 * para el cliente que no la quiera.
 */
class MarcaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_el_inicio_de_sesion_y_el_menu_dicen_quien_lo_desarrollo(): void
    {
        config(['ventas.desarrollado_por' => 'InnovaDevs']);

        $this->get(route('login'))->assertOk()
            ->assertSee('data-desarrollado-por', false)
            ->assertSeeText('Desarrollado por InnovaDevs');

        $this->actingAs(Usuario::where('usuario', 'cajero1')->firstOrFail())
            ->get(route('perfil.edit'))->assertOk()
            ->assertSeeText('Desarrollado por InnovaDevs');
    }

    public function test_sin_nombre_no_se_muestra(): void
    {
        config(['ventas.desarrollado_por' => '']);

        $this->get(route('login'))->assertOk()->assertDontSee('data-desarrollado-por', false);
        $this->actingAs(Usuario::where('usuario', 'cajero1')->firstOrFail())
            ->get(route('perfil.edit'))->assertOk()
            ->assertDontSee('data-desarrollado-por', false);
    }

    /** El inicio de sesión lleva el nombre del negocio, el que el personal reconoce. */
    public function test_el_inicio_de_sesion_muestra_el_nombre_del_negocio(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('data-nombre-negocio', false)
            ->assertSeeText(Config::negocio());
    }

    /** Un ingreso fallido se explica en la misma tarjeta, y el usuario escrito se conserva. */
    public function test_un_ingreso_fallido_se_explica_y_conserva_el_usuario(): void
    {
        $this->from(route('login'))
            ->post(route('login.store'), ['usuario' => 'nadie-asi', 'password' => 'no-es-esta'])
            ->assertRedirect(route('login'));

        $this->get(route('login'))->assertOk()
            ->assertSee('role="alert"', false)
            ->assertSeeText('No se pudo ingresar')
            ->assertSee('value="nadie-asi"', false);
    }
}
