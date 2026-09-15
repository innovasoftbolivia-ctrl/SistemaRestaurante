<?php

namespace App\Http\Controllers;

use App\Models\Lote;
use App\Models\Producto;
use App\Services\Inventario;
use App\Services\Lotes;
use App\Support\Config;
use Illuminate\Http\RedirectResponse;
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

    /**
     * Sacar del inventario una tanda vencida, de una sola vez.
     *
     * Lo vencido sigue contando como stock hasta que alguien lo dice: el
     * mostrador lo dejaría vender y el reporte lo sigue valorando. Esto es lo
     * que lo saca, y es un AJUSTE como cualquier otro —con su movimiento en el
     * kardex, su responsable y su motivo—, no un borrado.
     *
     * El motivo lo arma el servidor y no se teclea. Es la diferencia entre un
     * kardex donde dice «Baja por vencimiento — lote L06227, venció el
     * 11/09/2026» y uno donde cada quien escribió lo que le pareció.
     */
    public function baja(Request $request, Lote $lote): RedirectResponse
    {
        $datos = $request->validate([
            'observacion' => ['nullable', 'string', 'max:120'],
        ]);

        $lote->load('producto.unidadMedida');

        if ((float) $lote->cantidad_actual <= 0) {
            return back()->with('error', 'Esa tanda ya no tiene unidades.');
        }

        // Solo lo que ya venció. Lo que caduca la semana que viene todavía se
        // puede vender o devolver, y darlo de baja sería tirar mercadería buena.
        if (! $lote->vencido) {
            return back()->with('error', 'Esa tanda todavía no venció: se puede vender o devolver al proveedor.');
        }

        $cantidad = Config::cantidad($lote->cantidad_actual);
        $unidad = $lote->producto?->unidadMedida?->codigo;

        try {
            $movimiento = Inventario::bajaDeLote($lote, $this->motivoDeLaBaja($lote, $datos['observacion'] ?? null));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if (! $movimiento) {
            return back()->with('error', 'Esa tanda ya no tiene unidades.');
        }

        return back()->with(
            'exito',
            "Dadas de baja {$cantidad} {$unidad} de «{$lote->producto?->nombre}». "
            .'Quedan '.Config::cantidad($movimiento->stock_resultante)." {$unidad} en stock, "
            .'y el movimiento está en el kardex.'
        );
    }

    /** Lo que va a leer quien revise el kardex dentro de seis meses. */
    private function motivoDeLaBaja(Lote $lote, ?string $observacion): string
    {
        $partes = ['Baja por vencimiento'];

        if ($lote->codigo) {
            $partes[] = "lote {$lote->codigo}";
        }

        if ($lote->fecha_vencimiento) {
            $partes[] = 'venció el '.$lote->fecha_vencimiento->format('d/m/Y');
        }

        $motivo = implode(', ', $partes);

        return $observacion ? $motivo.' — '.$observacion : $motivo;
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
