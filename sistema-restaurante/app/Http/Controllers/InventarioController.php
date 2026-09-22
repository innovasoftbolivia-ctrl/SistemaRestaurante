<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Services\Inventario;
use App\Support\Config;
use App\Support\Mensaje;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;

/**
 * El stock de lo que se compra hecho (las bebidas embotelladas) y su kardex.
 * Las reglas viven en {@see Inventario}; esto las muestra y las dispara.
 */
class InventarioController extends Controller
{
    public function index(Request $request): View
    {
        $filtros = [
            'buscar' => trim($request->string('buscar')->toString()),
            'alerta' => $request->boolean('alerta'),
            'inactivos' => $request->boolean('inactivos'),
        ];

        $conStock = Producto::conStock();

        $productos = Producto::conStock()
            ->with('categoria:id,nombre')
            ->buscar($filtros['buscar'])
            ->when(! $filtros['inactivos'], fn ($q) => $q->activos())
            // Bajo mínimo o en negativo: lo que pide atención hoy.
            ->when($filtros['alerta'], fn ($q) => $q->whereColumn('stock_actual', '<=', 'stock_minimo'))
            ->orderByDesc('activo')
            ->orderBy('nombre')
            ->paginate(25)
            ->withQueryString();

        $resumen = [
            'productos' => (clone $conStock)->activos()->count(),
            'bajo_minimo' => (clone $conStock)->activos()
                ->whereColumn('stock_actual', '<=', 'stock_minimo')->where('stock_actual', '>=', 0)->count(),
            'negativos' => (clone $conStock)->where('stock_actual', '<', 0)->count(),
            // Lo vendido sin stock no vale dinero: se cuenta desde cero.
            'valor' => (float) (clone $conStock)->selectRaw('COALESCE(SUM(GREATEST(stock_actual, 0) * COALESCE(costo, 0)), 0) AS v')
                ->value('v'),
        ];

        return view('inventario.index', [
            'title' => 'Stock',
            'productos' => $productos,
            'filtros' => $filtros,
            'resumen' => $resumen,
            'hayControlados' => Producto::conStock()->exists(),
        ]);
    }

    public function kardex(Request $request, Producto $producto): View
    {
        abort_if(! $producto->controla_stock && ! $producto->movimientos()->exists(), 404);

        $filtros = [
            'desde' => $request->date('desde')?->format('Y-m-d'),
            'hasta' => $request->date('hasta')?->format('Y-m-d'),
        ];

        $movimientos = $producto->movimientos()
            ->with('usuario:id,usuario')
            ->when($filtros['desde'], fn ($q, $d) => $q->where('fecha', '>=', $d.' 00:00:00'))
            ->when($filtros['hasta'], fn ($q, $h) => $q->where('fecha', '<=', $h.' 23:59:59'))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('inventario.kardex', [
            'title' => 'Kardex de '.$producto->nombre,
            'trail' => ['Stock' => route('inventario.index')],
            'producto' => $producto->load('categoria:id,nombre'),
            'movimientos' => $movimientos,
            'filtros' => $filtros,
        ]);
    }

    /** Deja el stock en lo contado: una rotura, un conteo rápido de una sola bebida. */
    public function ajuste(Request $request, Producto $producto): RedirectResponse
    {
        $datos = $request->validate([
            'contado' => ['required', 'numeric', 'min:0', 'max:999999'],
            'motivo' => ['required', 'string', 'max:255'],
        ], [], ['contado' => 'stock contado', 'motivo' => 'motivo']);

        try {
            $movimiento = Inventario::ajuste($producto, (float) $datos['contado'], $datos['motivo'], Auth::user());
        } catch (RuntimeException $e) {
            return back()->with('error', Mensaje::de($e))->withInput();
        }

        return back()->with('exito', $movimiento
            ? "Stock de «{$producto->nombre}» ajustado: quedó en ".Config::cantidad($datos['contado']).' u.'
            : "El stock de «{$producto->nombre}» ya era ese: no hubo nada que ajustar.");
    }
}
