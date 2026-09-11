<?php

namespace App\Http\Controllers;

use App\Models\Compra;
use App\Models\DevolucionCompra;
use App\Models\Lote;
use App\Models\Proveedor;
use App\Services\DevolucionesCompra;
use App\Support\Config;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Lo que se le devuelve al proveedor, y lo que él repone a cambio.
 *
 * Toda devolución arranca eligiendo la factura, a propósito: una devolución
 * siempre es «de esta compra», y empezar por el papel evita devolverle a un
 * proveedor lo que trajo otro.
 *
 * Ahora bien, eso NO significa que la única puerta sea abrir la compra y
 * buscar el botón dentro. Esa fue la primera versión y no se encontraba: quien
 * necesita devolver algo entra por el menú, ve el listado y se queda sin saber
 * qué hacer. `elegirCompra()` es la otra puerta —del listado al formulario,
 * pasando por «¿de qué factura?»—, y solo ofrece las compras a las que todavía
 * les queda algo por devolver, porque llevar a un formulario con todas las
 * líneas en cero es peor que no ofrecerlo.
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

    /**
     * «¿De qué factura?»: el paso que faltaba entre el menú y el formulario.
     *
     * Solo las compras con algo pendiente. Una factura ya devuelta del todo no
     * se ofrece: el formulario saldría con todas las líneas topadas en cero y
     * el error se descubriría al final, que es el peor momento.
     */
    public function elegirCompra(Request $request): View
    {
        $filtros = [
            'buscar' => $request->string('buscar')->toString(),
            'proveedor' => $request->integer('proveedor') ?: null,
        ];

        $compras = Compra::with(['proveedor:id,razon_social'])
            ->withCount('detalle')
            // Cuántas LÍNEAS quedan con algo por devolver, y no la suma de las
            // cantidades: una factura mezcla unidades, kilos y litros, y
            // sumarlos da un número que no significa nada. Contar líneas sí.
            ->addSelect(['lineas_pendientes' => DB::table('compra_detalle')
                ->selectRaw('COUNT(*)')
                ->whereColumn('compra_id', 'compras.id')
                ->whereColumn('cantidad_devuelta', '<', 'cantidad'),
            ])
            ->addSelect(['total_documento' => DB::table('compra_detalle')
                ->selectRaw('COALESCE(SUM(importe), 0)')
                ->whereColumn('compra_id', 'compras.id'),
            ])
            ->whereHas('detalle', fn ($q) => $q->whereColumn('cantidad_devuelta', '<', 'cantidad'))
            ->when($filtros['buscar'] !== '', fn ($q) => $q->where('documento_externo', 'like', "%{$filtros['buscar']}%"))
            ->when($filtros['proveedor'], fn ($q, $id) => $q->where('proveedor_id', $id))
            ->orderByDesc('fecha')
            ->paginate(10)
            ->withQueryString();

        return view('devoluciones-compra.elegir', [
            'title' => 'Devolver: ¿de qué factura?',
            'trail' => [
                'Almacén' => route('inventario.index'),
                'Devoluciones a proveedor' => route('devoluciones-compra.index'),
            ],
            'compras' => $compras,
            'filtros' => $filtros,
            'proveedores' => Proveedor::activos()->orderBy('razon_social')->pluck('razon_social', 'id'),
        ]);
    }

    /**
     * El formulario, partiendo de la compra por la que entró la mercadería.
     *
     * `?lote=` es el atajo desde la pantalla de vencimientos: se llega con la
     * tanda vencida ya elegida, su cantidad puesta y el motivo en
     * «vencimiento». Quien ve el problema no tiene que acordarse de con qué
     * factura entró ni volver a teclear lo que la pantalla anterior ya sabía.
     */
    public function create(Request $request, Compra $compra): View
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
            // La tanda con la que se llegó, si se llegó con una. Se comprueba
            // que sea de un producto de ESTA compra: un id de la barra de
            // direcciones no puede prellenar una línea que no corresponde.
            'loteElegido' => $this->loteDelAtajo($request, $compra),
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

    /**
     * La tanda que llega en la URL, si es de esta compra.
     *
     * @return array{lote_id: int, producto_id: int, cantidad: float}|null
     */
    private function loteDelAtajo(Request $request, Compra $compra): ?array
    {
        $id = $request->integer('lote');

        if (! $id) {
            return null;
        }

        $lote = Lote::whereKey($id)
            ->whereIn('producto_id', $compra->detalle->pluck('producto_id'))
            ->abiertos()
            ->first();

        if (! $lote) {
            return null;
        }

        return [
            'lote_id' => $lote->id,
            'producto_id' => $lote->producto_id,
            'cantidad' => (float) $lote->cantidad_actual,
        ];
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
