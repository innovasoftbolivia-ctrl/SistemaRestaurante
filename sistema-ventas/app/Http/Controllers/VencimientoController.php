<?php

namespace App\Http\Controllers;

use App\Models\Lote;
use App\Models\Producto;
use App\Services\Lotes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Qué se está por vencer, para poder hacer algo a tiempo.
 *
 * Es la razón de ser de los lotes: ver con semanas de antelación lo que va a
 * caducar permite rematarlo, devolverlo al proveedor o al menos no descubrirlo
 * el día que ya no sirve. Sin esta pantalla, partir el stock por fechas no le
 * ahorraría un peso a nadie.
 *
 * Vive en el almacén y se mira con los mismos permisos que el inventario: quien
 * carga, quien ajusta y quien consulta reportes.
 */
class VencimientoController extends Controller
{
    /** Ventanas de aviso que se ofrecen. La de 30 días es la de referencia. */
    private const VENTANAS = [7, 15, 30, 60, 90];

    public function index(Request $request): View
    {
        $dias = (int) $request->integer('dias');
        $dias = in_array($dias, self::VENTANAS, true) ? $dias : Lotes::DIAS_DE_AVISO;

        $lotes = Lotes::alertas($dias)->paginate(20)->withQueryString();

        return view('vencimientos.index', [
            'title' => 'Vencimientos',
            'trail' => ['Almacén' => route('inventario.index')],
            'lotes' => $lotes,
            'dias' => $dias,
            'ventanas' => self::VENTANAS,
            'resumen' => $this->resumen($dias),
        ]);
    }

    /**
     * Las tres cifras que importan: lo ya vencido, lo que está por vencer y lo
     * que no tiene fecha.
     *
     * La tercera no es un detalle técnico: si al encender el control quedaron
     * 200 unidades sin fechar, «no tengo nada por vencer» no significa que todo
     * esté bien, significa que el control todavía no está completo. Callarlo
     * daría una tranquilidad falsa.
     *
     * @return array<string, float|int>
     */
    private function resumen(int $dias): array
    {
        $base = fn () => DB::table('lotes as l')
            ->join('productos as p', 'p.id', '=', 'l.producto_id')
            ->where('p.activo', 1)
            ->where('l.cantidad_actual', '>', 0);

        return [
            'vencidos' => (int) $base()->whereNotNull('l.fecha_vencimiento')
                ->whereRaw('l.fecha_vencimiento < CURDATE()')->count(),
            'valor_vencido' => (float) $base()->whereNotNull('l.fecha_vencimiento')
                ->whereRaw('l.fecha_vencimiento < CURDATE()')
                ->sum(DB::raw('l.cantidad_actual * p.precio_compra')),
            'por_vencer' => (int) $base()->whereNotNull('l.fecha_vencimiento')
                ->whereRaw('l.fecha_vencimiento >= CURDATE()')
                ->whereRaw('l.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL ? DAY)', [$dias])
                ->count(),
            'sin_fecha' => (float) $base()->whereNull('l.fecha_vencimiento')->sum('l.cantidad_actual'),
            'controlados' => Producto::activos()->where('controla_vencimiento', 1)->count(),
        ];
    }

    /** El detalle de un producto: sus tandas, con lo que queda de cada una. */
    public function producto(Producto $producto): View
    {
        return view('vencimientos.producto', [
            'title' => 'Lotes de '.$producto->nombre,
            'trail' => [
                'Almacén' => route('inventario.index'),
                'Vencimientos' => route('vencimientos.index'),
            ],
            'producto' => $producto->load('unidadMedida'),
            'lotes' => Lote::where('producto_id', $producto->id)
                ->with('compraDetalle.compra:id,documento_externo')
                ->enOrdenDeSalida()
                ->paginate(20),
            'sinFecha' => Lotes::sinFecha($producto),
        ]);
    }
}
