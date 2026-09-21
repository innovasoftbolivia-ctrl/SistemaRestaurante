<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lo que el dueño mira en los reportes para decidir: cuándo se vende, qué no
 * se vende y dónde se escapa la plata (descuentos, anulaciones, cajas que no
 * cuadran). Cada cifra, contra lo que de verdad pasó.
 */
class ReportesDelNegocioTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    private function turno(): SesionCaja
    {
        return Cajas::sesionDe($this->admin()) ?? Cajas::abrir(Caja::firstOrFail(), $this->admin(), 200);
    }

    private function vender(string $codigo = 'P-0004', float $cantidad = 1, float $descuento = 0): Venta
    {
        return Ventas::registrar(
            sesion: $this->turno()->fresh(),
            usuario: $this->admin(),
            lineas: [['producto_id' => Producto::where('codigo', $codigo)->value('id'), 'cantidad' => $cantidad]],
            descuento: $descuento,
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
    }

    private function reporte(string $ruta = 'reportes.ventas')
    {
        return $this->actingAs($this->admin())
            ->get(route($ruta, ['desde' => Config::jornadaActual(), 'hasta' => Config::jornadaActual()]))
            ->assertOk();
    }

    public function test_los_descuentos_y_lo_anulado_se_ven_en_plata(): void
    {
        $this->vender(cantidad: 2, descuento: 1.00);
        $anulada = $this->vender();
        Ventas::anular($anulada->fresh(), $this->admin(), 'Se cobró dos veces');

        $respuesta = $this->reporte();
        $resumen = $respuesta->viewData('resumen');

        $this->assertSame(1.0, $resumen['descuentos']);
        $this->assertSame(1, $resumen['con_descuento']);
        $this->assertSame((float) $anulada->fresh()->total, $resumen['monto_anulado']);

        // La anulación, con quién anuló y por qué.
        $fila = $respuesta->viewData('anuladas')->firstWhere('id', $anulada->id);
        $this->assertSame('Se cobró dos veces', $fila->motivo_anulacion);
        $this->assertSame('admin', $fila->anulo);
        $respuesta->assertSee('Se cobró dos veces');
    }

    public function test_la_venta_cae_en_su_hora(): void
    {
        $venta = $this->vender();
        $hora = (int) now()->format('G');

        $fila = collect($this->reporte()->viewData('porHora'))->firstWhere('hora', $hora);

        $this->assertSame(1, $fila['ventas']);
        $this->assertSame((float) $venta->fresh()->total, $fila['monto']);
    }

    /** En un mes hay cinco de unos días y cuatro de otros: el promedio es por jornada. */
    public function test_el_dia_de_la_semana_se_promedia_por_jornada(): void
    {
        $venta = $this->vender();

        $semana = collect($this->actingAs($this->admin())
            ->get(route('reportes.ventas', [
                'desde' => Carbon::parse(Config::jornadaActual())->subDays(13)->toDateString(),
                'hasta' => Config::jornadaActual(),
            ]))
            ->viewData('porDiaSemana'));

        // Catorce jornadas: dos de cada día.
        $this->assertSame([2, 2, 2, 2, 2, 2, 2], $semana->pluck('jornadas')->all());

        $hoy = $semana->firstWhere('ventas', '>', 0);
        $this->assertSame(round((float) $venta->fresh()->total / 2, 2), $hoy['promedio']);
    }

    public function test_el_menu_muestra_lo_que_no_se_vendio(): void
    {
        $this->vender('P-0004');

        $respuesta = $this->reporte('reportes.productos');
        $sinVentas = $respuesta->viewData('sinVentas')->pluck('codigo');

        $this->assertNotContains('P-0004', $sinVentas);
        $this->assertSame(
            Producto::activos()->where('codigo', '<>', 'P-0004')->count(),
            $sinVentas->count(),
        );
    }

    /** Las categorías se reparten todo lo vendido, ni más ni menos. */
    public function test_las_categorias_suman_lo_vendido(): void
    {
        $this->vender('P-0004', 2, descuento: 0.50);
        $this->vender('P-0009', 1);

        $respuesta = $this->reporte('reportes.productos');

        $this->assertEqualsWithDelta(
            $respuesta->viewData('totalVendido'),
            (float) $respuesta->viewData('porCategoria')->sum('monto'),
            0.001,
        );
    }

    public function test_el_cuadre_de_caja_muestra_el_faltante(): void
    {
        $this->vender();
        $turno = $this->turno()->fresh();
        $esperado = (float) DB::selectOne('SELECT monto_inicial FROM sesiones_caja WHERE id = ?', [$turno->id])->monto_inicial
            + (float) Venta::where('sesion_caja_id', $turno->id)->where('estado', 'COMPLETADA')->sum('total');

        Cajas::cerrar($turno, $this->admin(), $esperado - 5, 'Faltó cambio', conCuentasAbiertas: true);

        $respuesta = $this->reporte();
        $fila = $respuesta->viewData('cuadres')->firstWhere('id', $turno->id);

        $this->assertSame(-5.0, (float) $fila->diferencia);
        $respuesta->assertSee('con faltante');
    }
}
