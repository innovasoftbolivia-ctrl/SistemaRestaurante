<?php

namespace App\Http\Controllers;

use App\Models\Compra;
use App\Models\DevolucionCompra;
use App\Models\Lote;
use App\Services\DevolucionesCompra;
use App\Support\Config;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Lo que se le devuelve al proveedor, y lo que él repone a cambio.
 *
 * Se entra desde la compra y no desde un menú suelto, a propósito: una
 * devolución siempre es «de esta factura», y arrancar eligiendo la factura
 * evita el error de devolver contra el proveedor equivocado. El listado general
 * existe para lo otro — mirar cuánto se devolvió y por qué.
 *
 * Mismo permiso que las compras (`inventario.ingresar`): es la misma persona,
 * el mismo día y la misma mercadería, solo que yéndose en vez de llegando.
 */
class DevolucionCompraController extends Controller
{
    public function index(Request $request): View
    {
        $filtros = [
            'motivo' => $request->string('motivo')->toString(),
            'desde' => $request->date('desde'),
            'hasta' => $request->date('hasta'),
        ];

        $devoluciones = DevolucionCompra::with(['compra.proveedor:id,razon_social', 'usuario:id,usuario'])
            ->withCount('detalle')
            ->when($filtros['motivo'] !== '', fn ($q) => $q->where('motivo', $filtros['motivo']))
            ->when($filtros['desde'], fn ($q, $d) => $q->where('fecha', '>=', $d->startOfDay()))
            ->when($filtros['hasta'], fn ($q, $d) => $q->where('fecha', '<=', $d->endOfDay()))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('devoluciones-compra.index', [
            'title' => 'Devoluciones a proveedor',
            'trail' => ['Almacén' => route('inventario.index')],
            'devoluciones' => $devoluciones,
            'filtros' => $filtros,
            'motivos' => $this->motivos(),
            // Por motivo y no un total suelto: «Bs 900 por vencimiento» es una
            // conversación con el proveedor distinta de «Bs 900 porque vino
            // fallado», y sumarlas las haría desaparecer a las dos.
            'porMotivo' => DevolucionesCompra::porMotivo(
                $filtros['desde']?->copy()->startOfDay()->toDateTimeString(),
                $filtros['hasta']?->copy()->endOfDay()->toDateTimeString(),
            )->get()->keyBy('motivo'),
        ]);
    }

    /** El formulario, partiendo de la compra por la que entró la mercadería. */
    public function create(Compra $compra): View
    {
        $compra->load([
            'proveedor',
            'detalle.producto:id,codigo,nombre,unidad_medida_id,controla_vencimiento',
            'detalle.producto.unidadMedida:id,codigo,permite_decimal',
        ]);

        // Las tandas abiertas de los productos de esta compra: son las que se
        // pueden elegir al devolver algo vencido.
        $lotes = Lote::whereIn('producto_id', $compra->detalle->pluck('producto_id'))
            ->abiertos()
            ->enOrdenDeSalida()
            ->get()
            ->groupBy('producto_id');

        return view('devoluciones-compra.create', [
            'title' => 'Devolver al proveedor',
            'trail' => [
                'Almacén' => route('inventario.index'),
                'Compras' => route('compras.index'),
            ],
            'compra' => $compra,
            'lotes' => $lotes,
            'motivos' => $this->motivos(),
        ]);
    }

    public function store(Request $request, Compra $compra): RedirectResponse
    {
        $datos = $request->validate([
            'motivo' => ['required', Rule::in(DevolucionCompra::MOTIVOS)],
            'con_reposicion' => ['boolean'],
            'documento_externo' => ['nullable', 'string', 'max:30'],
            'observacion' => ['nullable', 'string', 'max:255'],
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.compra_detalle_id' => ['required', Rule::exists('compra_detalle', 'id')],
            'lineas.*.cantidad' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'lineas.*.lote_id' => ['nullable', Rule::exists('lotes', 'id')],
            // Solo se usa con reposición: la tanda que trae el reemplazo.
            'lineas.*.vence_repuesto' => ['nullable', 'date'],
        ], [
            'lineas.required' => 'Marca al menos un producto para devolver.',
            'lineas.min' => 'Marca al menos un producto para devolver.',
            'motivo.required' => 'Di por qué se devuelve: es lo que después permite contarlo.',
        ]);

        try {
            $devolucion = DevolucionesCompra::registrar(
                usuario: Auth::user(),
                compra: $compra,
                lineas: $datos['lineas'],
                motivo: $datos['motivo'],
                conReposicion: $request->boolean('con_reposicion'),
                documentoExterno: $datos['documento_externo'] ?? null,
                observacion: $datos['observacion'] ?? null,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['lineas' => $e->getMessage()]);
        }

        $lineas = $devolucion->detalle->count();

        return redirect()->route('devoluciones-compra.show', $devolucion)->with(
            'exito',
            "Devolución registrada: {$lineas} ".($lineas === 1 ? 'producto' : 'productos')
            .' por '.Config::importe($devolucion->total).'. '
            .($devolucion->con_reposicion
                ? 'La reposición ya entró al stock.'
                : 'El stock ya bajó.')
        );
    }

    public function show(DevolucionCompra $devolucionCompra): View
    {
        $devolucionCompra->load([
            'compra.proveedor',
            'usuario:id,usuario',
            'detalle.producto:id,codigo,nombre,unidad_medida_id',
            'detalle.producto.unidadMedida:id,codigo',
            'detalle.lote:id,fecha_vencimiento,codigo',
        ]);

        return view('devoluciones-compra.show', [
            'title' => 'Devolución #'.$devolucionCompra->id,
            'trail' => [
                'Almacén' => route('inventario.index'),
                'Devoluciones a proveedor' => route('devoluciones-compra.index'),
            ],
            'devolucion' => $devolucionCompra,
        ]);
    }

    /** @return array<string, string> */
    private function motivos(): array
    {
        return collect(DevolucionCompra::MOTIVOS)
            ->mapWithKeys(fn (string $m) => [
                $m => (new DevolucionCompra(['motivo' => $m]))->etiqueta_motivo,
            ])
            ->all();
    }
}
