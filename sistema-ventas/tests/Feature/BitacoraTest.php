<?php

namespace Tests\Feature;

use App\Http\Controllers\BitacoraController;
use App\Models\Auditoria;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El visor de la bitácora.
 *
 * El sistema ya registraba ingresos fallidos, anulaciones, cambios de precio...
 * pero no había dónde leerlo: para saber quién anuló una venta había que entrar
 * a la base.
 */
class BitacoraTest extends TestCase
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

    /** @param  array<string, mixed>  $datos */
    private function registro(array $datos): Auditoria
    {
        return Auditoria::create(array_merge([
            'usuario_id' => $this->admin()->id,
            'accion' => 'PRODUCTO_ACTUALIZADO',
            'entidad' => 'productos',
            'entidad_id' => 1,
            'detalle' => null,
            'ip' => '127.0.0.1',
            'fecha' => now(),
        ], $datos));
    }

    // -------------------------------------------------------------- permisos

    /**
     * Quién hizo qué es información sensible: la lee quien tiene `bitacora.ver`,
     * que por defecto es solo el administrador.
     */
    public function test_solo_quien_tiene_el_permiso_la_ve(): void
    {
        $this->assertTrue($this->admin()->tienePermiso('bitacora.ver'));

        $this->actingAs($this->admin())->get(route('bitacora.index'))->assertOk();

        foreach ([$this->cajero(), $this->cocina()] as $usuario) {
            $this->assertFalse($usuario->tienePermiso('bitacora.ver'));
            $this->actingAs($usuario)->get(route('bitacora.index'))->assertForbidden();
        }
    }

    // ------------------------------------------------------------- contenido

    public function test_muestra_quien_que_y_el_cambio_con_su_valor_anterior(): void
    {
        $this->registro([
            'usuario_id' => $this->cocina()->id,
            'detalle' => ['precio_venta' => ['antes' => '3.98', 'despues' => '4.20']],
            'ip' => '192.168.1.40',
        ]);

        $this->actingAs($this->admin())
            ->get(route('bitacora.index'))
            ->assertOk()
            ->assertSee('cocina1')
            ->assertSee('Ítem del menú actualizado')
            ->assertSee('3.98 → 4.20')
            ->assertSee('192.168.1.40');
    }

    /** Cada registro lleva a lo que tocó, cuando eso tiene pantalla. */
    public function test_enlaza_a_lo_que_toco(): void
    {
        $this->registro(['accion' => 'ANULAR_VENTA', 'entidad' => 'ventas', 'entidad_id' => 987654]);

        $this->actingAs($this->admin())
            ->get(route('bitacora.index'))
            ->assertSee('Venta anulada')
            ->assertSee('Venta #987654')
            ->assertSee(route('ventas.show', 987654), false);
    }

    /**
     * Los pedidos y sus platos se leen por su nombre y llevan a la comanda del
     * pedido (también desde un plato). Las mesas ya no existen,
     * pero la bitácora de una instalación vieja todavía tiene registros suyos:
     * se siguen leyendo como «Mesa», sin enlace.
     */
    public function test_los_pedidos_sus_platos_y_las_mesas_viejas_se_leen_por_su_nombre(): void
    {
        $this->registro(['accion' => 'PEDIDO_CANCELADO', 'entidad' => 'pedidos', 'entidad_id' => 876543]);
        $this->registro(['accion' => 'PEDIDO_LINEA_AGREGADA', 'entidad' => 'pedido_detalle', 'entidad_id' => 765432, 'detalle' => ['pedido_id' => 876544]]);
        $this->registro(['accion' => 'MESA_CREADA', 'entidad' => 'mesas', 'entidad_id' => 654321]);

        $this->actingAs($this->admin())
            ->get(route('bitacora.index'))
            ->assertSee('Pedido #876543')
            ->assertSee(route('pedidos.comanda', 876543), false)
            ->assertSee('Plato del pedido #765432')
            ->assertSee(route('pedidos.comanda', 876544), false)
            ->assertSee('Mesa #654321')
            ->assertDontSee('Pedido detalle')
            ->assertDontSee('Mesas #654321');
    }

    /**
     * El detalle guarda lo que escribió alguien —un nombre, un motivo—: es el
     * lugar clásico para colar HTML. Tiene que salir escapado.
     */
    public function test_el_detalle_sale_escapado(): void
    {
        $this->registro(['detalle' => ['nombre' => '<script>alert("x")</script>']]);

        $this->actingAs($this->admin())
            ->get(route('bitacora.index'))
            ->assertOk()
            ->assertDontSee('<script>alert("x")</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_lo_mas_nuevo_va_primero(): void
    {
        $this->registro(['accion' => 'CATEGORIA_CREADA', 'fecha' => now()->subDay()]);
        $this->registro(['accion' => 'CATEGORIA_ELIMINADA', 'fecha' => now()]);

        $html = $this->actingAs($this->admin())->get(route('bitacora.index'))->getContent();

        // Solo dentro de la tabla: el desplegable de acciones del filtro también
        // nombra las dos, en orden alfabético y antes que la tabla.
        $html = substr($html, strpos($html, '<tbody'));

        $this->assertLessThan(
            strpos($html, 'Categoría creada'),
            strpos($html, 'Categoría eliminada'),
            'el registro más reciente no aparece antes que el anterior'
        );
    }

    // --------------------------------------------------------------- filtros

    public function test_filtra_por_accion_y_por_usuario(): void
    {
        $this->registro(['accion' => 'CLIENTE_CREADO', 'usuario_id' => $this->cajero()->id,
            'detalle' => ['marca' => 'hecho-por-cajero']]);
        $this->registro(['accion' => 'PROVEEDOR_CREADO', 'usuario_id' => $this->cocina()->id,
            'detalle' => ['marca' => 'hecho-por-almacen']]);

        $this->actingAs($this->admin())
            ->get(route('bitacora.index', ['accion' => 'CLIENTE_CREADO']))
            ->assertSee('hecho-por-cajero')
            ->assertDontSee('hecho-por-almacen');

        $this->actingAs($this->admin())
            ->get(route('bitacora.index', ['usuario' => $this->cocina()->id]))
            ->assertSee('hecho-por-almacen')
            ->assertDontSee('hecho-por-cajero');
    }

    public function test_filtra_por_fechas(): void
    {
        $this->registro(['detalle' => ['marca' => 'del-lunes'], 'fecha' => '2026-01-05 10:00:00']);
        $this->registro(['detalle' => ['marca' => 'del-viernes'], 'fecha' => '2026-01-09 23:30:00']);

        $this->actingAs($this->admin())
            ->get(route('bitacora.index', ['desde' => '2026-01-09', 'hasta' => '2026-01-09']))
            ->assertSee('del-viernes')
            ->assertDontSee('del-lunes');
    }

    /** Se busca dentro del detalle: un nombre o un precio que alguien cambió. */
    public function test_busca_dentro_del_detalle(): void
    {
        $this->registro(['detalle' => ['nombre' => ['antes' => 'Arroz', 'despues' => 'Arroz grano de oro']]]);
        $this->registro(['detalle' => ['nombre' => ['antes' => 'Aceite', 'despues' => 'Aceite vegetal']]]);

        $this->actingAs($this->admin())
            ->get(route('bitacora.index', ['buscar' => 'grano de oro']))
            ->assertSee('Arroz grano de oro')
            ->assertDontSee('Aceite vegetal');
    }

    // ----------------------------------------------------------- redacción

    public function test_las_acciones_se_leen_en_castellano(): void
    {
        $this->assertSame('Configuración actualizada', BitacoraController::accion('CONFIGURACION_ACTUALIZADA'));
        $this->assertSame('Ingreso fallido', BitacoraController::accion('LOGIN_FALLIDO'));
        $this->assertSame('Ingreso', BitacoraController::accion('LOGIN'));
        $this->assertSame('Venta anulada', BitacoraController::accion('ANULAR_VENTA'));
        $this->assertSame('QR pagado', BitacoraController::accion('QR_PAGADO'));
        $this->assertSame('Pedido abierto', BitacoraController::accion('PEDIDO_ABIERTO'));
        $this->assertSame('Plato agregado al pedido', BitacoraController::accion('PEDIDO_LINEA_AGREGADA'));
        $this->assertSame('Plato movido en la cocina', BitacoraController::accion('PEDIDO_LINEA_ESTADO'));
        $this->assertSame('Cobro anulado: el pedido se vuelve a cobrar', BitacoraController::accion('PEDIDO_REABIERTO'));
        $this->assertSame('Cobro anulado: el pedido no se pudo reabrir', BitacoraController::accion('PEDIDO_NO_REABIERTO'));
        $this->assertSame('Pedido cobrado', BitacoraController::accion('PEDIDO_COBRADO'));
        // Una acción nueva que nadie tradujo se lee igual, sin guiones bajos.
        $this->assertSame('Algo nuevo hecho', BitacoraController::accion('ALGO_NUEVO_HECHO'));
    }
}
