<?php

namespace App\Http\Controllers;

use App\Models\TomaInventario;
use App\Services\TomasInventario;
use App\Support\Mensaje;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * Pantallas de la toma de inventario. Las reglas viven en
 * {@see TomasInventario}; esto las muestra y las dispara.
 */
class TomaInventarioController extends Controller
{
    public function index(): View
    {
        $tomas = TomaInventario::with('usuarioApertura:id,usuario', 'usuarioCierre:id,usuario')
            ->withCount([
                'detalle',
                'detalle as contados_count' => fn ($q) => $q->whereNotNull('contado'),
                'detalle as ajustes_count' => fn ($q) => $q->whereNotNull('movimiento_id'),
            ])
            ->orderByDesc('fecha_apertura')
            ->orderByDesc('id')
            ->paginate(15);

        return view('tomas.index', [
            'title' => 'Toma de inventario',
            'tomas' => $tomas,
            'abierta' => TomasInventario::abierta(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'observacion' => ['nullable', 'string', 'max:255'],
        ], [], ['observacion' => 'observación']);

        try {
            $toma = TomasInventario::abrir(Auth::user(), $datos['observacion'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', Mensaje::de($e))->withInput();
        }

        return redirect()->route('tomas.show', $toma)
            ->with('exito', 'Toma abierta. Cuenta cada bebida, escribe lo que hay y guarda el conteo.');
    }

    public function show(TomaInventario $toma): View
    {
        $lineas = $toma->detalle()
            ->join('productos', 'productos.id', '=', 'toma_inventario_detalle.producto_id')
            ->select('toma_inventario_detalle.*')
            ->with('producto:id,codigo,nombre,contenido_empaque,nombre_empaque,stock_actual,costo', 'usuario:id,usuario')
            ->orderBy('productos.nombre')
            ->get();

        $contadas = $lineas->whereNotNull('contado');

        $resumen = [
            'total' => $lineas->count(),
            'contados' => $contadas->count(),
            'con_diferencia' => $contadas->filter(fn ($l) => round((float) $l->diferencia, 3) != 0)->count(),
            'faltante' => $contadas->filter(fn ($l) => (float) $l->diferencia < 0)
                ->sum(fn ($l) => abs((float) $l->diferencia) * (float) $l->costo_unitario),
            'sobrante' => $contadas->filter(fn ($l) => (float) $l->diferencia > 0)
                ->sum(fn ($l) => (float) $l->diferencia * (float) $l->costo_unitario),
        ];

        return view('tomas.show', [
            'title' => "Toma de inventario #{$toma->id}",
            'trail' => ['Toma de inventario' => route('tomas.index')],
            'toma' => $toma->load('usuarioApertura:id,usuario', 'usuarioCierre:id,usuario'),
            'lineas' => $lineas,
            'resumen' => $resumen,
        ]);
    }

    /** Guarda de una vez lo contado en la planilla; lo que se deja vacío no se toca. */
    public function contar(Request $request, TomaInventario $toma): RedirectResponse
    {
        $datos = $request->validate([
            'contados' => ['array'],
            'contados.*' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            // La hora en que se escribió cada fila (la pone la planilla): el
            // stock del sistema se toma de esa hora, no de la de guardar.
            'contado_en' => ['array'],
            'contado_en.*' => ['nullable', 'integer', 'min:0'],
        ], [
            'contados.*.numeric' => 'Lo contado tiene que ser un número.',
            'contados.*.min' => 'Lo contado no puede ser negativo.',
        ]);

        // Por id de producto: el mismo orden en que bloquean las ventas y las
        // compras. En el de la planilla (por nombre) se trababan entre sí.
        $contados = collect($datos['contados'] ?? [])
            ->filter(fn ($valor) => $valor !== null && $valor !== '')
            ->sortKeys();
        $horas = $datos['contado_en'] ?? [];

        if ($contados->isEmpty()) {
            return back()->with('error', 'No escribiste ningún conteo: llena la columna «Contado» de lo que ya contaste.');
        }

        try {
            DB::transaction(function () use ($contados, $toma, $horas) {
                foreach ($contados as $productoId => $valor) {
                    $hora = isset($horas[$productoId]) ? Carbon::createFromTimestampMs((int) $horas[$productoId]) : null;
                    TomasInventario::contar($toma, (int) $productoId, (float) $valor, Auth::user(), $hora);
                }
            });
        } catch (RuntimeException $e) {
            return back()->with('error', Mensaje::de($e))->withInput();
        }

        return redirect()->route('tomas.show', $toma)
            ->with('exito', $contados->count() === 1 ? 'Conteo guardado.' : "Se guardaron {$contados->count()} conteos.");
    }

    public function cerrar(TomaInventario $toma): RedirectResponse
    {
        try {
            $ajustes = TomasInventario::cerrar($toma, Auth::user());
        } catch (RuntimeException $e) {
            return back()->with('error', Mensaje::de($e));
        }

        return redirect()->route('tomas.show', $toma)->with('exito', match ($ajustes) {
            0 => 'Toma cerrada. Todo cuadraba: no hizo falta ajustar nada.',
            1 => 'Toma cerrada. Se ajustó el stock de 1 producto.',
            default => "Toma cerrada. Se ajustó el stock de {$ajustes} productos.",
        });
    }

    public function cancelar(TomaInventario $toma): RedirectResponse
    {
        try {
            TomasInventario::cancelar($toma, Auth::user());
        } catch (RuntimeException $e) {
            return back()->with('error', Mensaje::de($e));
        }

        return redirect()->route('tomas.index')
            ->with('exito', "Toma #{$toma->id} cancelada. El stock no se tocó.");
    }
}
