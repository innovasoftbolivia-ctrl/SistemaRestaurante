<?php

namespace App\Http\Controllers;

use App\Models\Compra;
use App\Models\DevolucionCompra;
use App\Models\Proveedor;
use App\Services\DevolucionesCompra;
use App\Support\Config;
use App\Support\Mensaje;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Lo que se le devuelve al proveedor —botellas rotas, un producto equivocado—
 * y lo que él repone después.
 *
 * Toda devolución sale de una compra: siempre es «de esta factura», y así no
 * se le devuelve a un proveedor lo que trajo otro. Las cuentas (stock, lo que
 * queda por devolver, lo que el proveedor debe) las lleva el servicio
 * App\Services\DevolucionesCompra; aquí solo se valida la forma y se muestra.
 */
class DevolucionCompraController extends Controller
{
    public function index(Request $request): View
    {
        $filtros = [
            'espera' => $request->string('espera')->toString(),
            'proveedor' => $request->integer('proveedor') ?: null,
            'debe' => $request->boolean('debe'),
            'desde' => $request->date('desde')?->format('Y-m-d'),
            'hasta' => $request->date('hasta')?->format('Y-m-d'),
        ];

        $devoluciones = DevolucionCompra::with(['compra.proveedor:id,razon_social', 'usuario:id,usuario', 'detalle'])
            ->when(isset(DevolucionCompra::ESPERAS[$filtros['espera']]), fn (Builder $q) => $q->where('espera', $filtros['espera']))
            ->when($filtros['proveedor'], fn (Builder $q, $id) => $q->whereHas('compra', fn (Builder $c) => $c->where('proveedor_id', $id)))
            ->when($filtros['debe'], fn (Builder $q) => $q->where('espera', 'PENDIENTE')
                ->whereHas('detalle', fn (Builder $d) => $d->whereColumn('cantidad_repuesta', '<', 'cantidad')))
            ->when($filtros['desde'], fn (Builder $q, $d) => $q->where('fecha', '>=', $d.' 00:00:00'))
            ->when($filtros['hasta'], fn (Builder $q, $h) => $q->where('fecha', '<=', $h.' 23:59:59'))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('devoluciones-compra.index', [
            'title' => 'Devoluciones al proveedor',
            'trail' => ['Inventario' => route('inventario.index')],
            'devoluciones' => $devoluciones,
            'filtros' => $filtros,
            'esperas' => DevolucionCompra::ESPERAS,
            'proveedores' => Proveedor::orderBy('razon_social')->pluck('razon_social', 'id'),
            // Lo único de la pantalla sobre lo que hay que hacer algo: lo que
            // el proveedor se llevó y todavía no trajo.
            'debiendo' => DevolucionCompra::where('espera', 'PENDIENTE')
                ->whereHas('detalle', fn (Builder $d) => $d->whereColumn('cantidad_repuesta', '<', 'cantidad'))
                ->count(),
        ]);
    }

    public function create(Compra $compra): View|RedirectResponse
    {
        $compra->load(['proveedor', 'detalle.producto:id,codigo,nombre']);

        $lineas = $compra->detalle->filter(fn ($l) => $l->devolvible > 0)->values();

        if ($lineas->isEmpty()) {
            return redirect()->route('compras.show', $compra)
                ->with('error', 'De esta compra ya no queda nada por devolver.');
        }

        return view('devoluciones-compra.create', [
            'title' => 'Devolver al proveedor',
            'trail' => [
                'Compras' => route('compras.index'),
                'Compra #'.$compra->id => route('compras.show', $compra),
            ],
            'compra' => $compra,
            'lineas' => $lineas,
            'motivos' => DevolucionCompra::MOTIVOS,
            'esperas' => DevolucionCompra::ESPERAS,
        ]);
    }

    public function store(Request $request, Compra $compra): RedirectResponse
    {
        $datos = $request->validate([
            'motivo' => ['required', Rule::in(array_keys(DevolucionCompra::MOTIVOS))],
            'espera' => ['required', Rule::in(array_keys(DevolucionCompra::ESPERAS))],
            'documento_externo' => ['nullable', 'string', 'max:30'],
            'observacion' => ['nullable', 'string', 'max:255'],
            'cantidades' => ['required', 'array'],
            'cantidades.*' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ], [
            'motivo.required' => 'Elige el motivo de la devolución.',
            'motivo.in' => 'Elige el motivo de la devolución.',
            'espera.required' => 'Elige en qué queda la devolución con el proveedor.',
            'espera.in' => 'Elige en qué queda la devolución con el proveedor.',
            'cantidades.required' => 'Escribe cuánto se devuelve de al menos un producto.',
            'cantidades.*.numeric' => 'La cantidad a devolver tiene que ser un número.',
            'cantidades.*.min' => 'La cantidad a devolver no puede ser negativa.',
        ], [
            'documento_externo' => 'documento del proveedor',
            'observacion' => 'observación',
        ]);

        $lineas = collect($datos['cantidades'])
            ->map(fn ($cantidad, $id) => ['compra_detalle_id' => (int) $id, 'cantidad' => (float) $cantidad])
            ->values()
            ->all();

        try {
            $devolucion = DevolucionesCompra::registrar(
                $compra,
                Auth::user(),
                $lineas,
                $datos['motivo'],
                $datos['espera'],
                $datos['documento_externo'] ?? null,
                $datos['observacion'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', Mensaje::de($e))->withInput();
        }

        return redirect()->route('devoluciones-compra.show', $devolucion)->with(
            'exito',
            'Devolución registrada por '.Config::importe($devolucion->total).'. '.match ($devolucion->espera) {
                'REPUESTO' => 'El proveedor lo cambió en el momento: el stock queda igual.',
                'PENDIENTE' => 'El stock ya bajó; sube cuando el proveedor traiga el reemplazo.',
                default => 'El stock ya bajó. Queda a cuenta con el proveedor (nota de crédito).',
            }
        );
    }

    public function show(DevolucionCompra $devolucion): View
    {
        $devolucion->load([
            'compra.proveedor',
            'usuario:id,usuario',
            'detalle.producto:id,codigo,nombre',
        ]);

        return view('devoluciones-compra.show', [
            'title' => 'Devolución #'.$devolucion->id,
            'trail' => [
                'Inventario' => route('inventario.index'),
                'Devoluciones al proveedor' => route('devoluciones-compra.index'),
            ],
            'devolucion' => $devolucion,
            'esperas' => DevolucionCompra::ESPERAS,
            'motivos' => DevolucionCompra::MOTIVOS,
        ]);
    }

    /** Llegó lo que el proveedor debía, toda o una parte. */
    public function reponer(Request $request, DevolucionCompra $devolucion): RedirectResponse
    {
        $datos = $request->validate([
            'cantidades' => ['required', 'array'],
            'cantidades.*' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ], [
            'cantidades.required' => 'Escribe cuánto llegó de al menos un producto.',
            'cantidades.*.numeric' => 'La cantidad que llegó tiene que ser un número.',
            'cantidades.*.min' => 'La cantidad que llegó no puede ser negativa.',
        ]);

        $cantidades = collect($datos['cantidades'])
            ->mapWithKeys(fn ($cantidad, $id) => [(int) $id => (float) $cantidad])
            ->all();

        try {
            DevolucionesCompra::reponer($devolucion, Auth::user(), $cantidades);
        } catch (RuntimeException $e) {
            return back()->with('error', Mensaje::de($e))->withInput();
        }

        $devolucion->load('detalle');
        $falta = round((float) $devolucion->detalle->sum(fn ($d) => $d->por_reponer), 3);

        return redirect()->route('devoluciones-compra.show', $devolucion)->with(
            'exito',
            'Reposición registrada: lo que llegó ya entró al stock. '
            .($falta > 0
                ? 'El proveedor todavía debe '.Config::cantidad($falta).' unidades.'
                : 'Con esto el proveedor ya no debe nada de esta devolución.')
        );
    }
}
