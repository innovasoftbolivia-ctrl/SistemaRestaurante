<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExigeVencimiento;
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
    use ExigeVencimiento;

    public function index(Request $request): View
    {
        $filtros = [
            'motivo' => $request->string('motivo')->toString(),
            'desde' => $request->date('desde'),
            'hasta' => $request->date('hasta'),
            'pendientes' => $request->boolean('pendientes'),
        ];

        $devoluciones = DevolucionCompra::with(['compra.proveedor:id,razon_social', 'usuario:id,usuario', 'detalle'])
            ->withCount('detalle')
            ->when($filtros['pendientes'], fn ($q) => $q->esperandoReposicion())
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
            // Lo que el proveedor debe hoy. Va aparte de los totales por
            // motivo porque no es una cifra de dinero devuelto: es una deuda
            // abierta, y es la única de la pantalla sobre la que hay que hacer
            // algo.
            'esperando' => DevolucionCompra::esperandoReposicion()->count(),
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
            'esperas' => $this->esperas(),
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
            'espera' => ['required', Rule::in(DevolucionCompra::ESPERAS)],
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
            'espera.required' => 'Di en qué quedaron: si repone ahora, si lo trae después o si acredita.',
        ]);

        // Lo que el proveedor cambia en el momento entra con su fecha.
        if ($datos['espera'] === 'REPUESTO') {
            $this->exigirVencimiento(
                array_map(fn ($l) => (int) DB::table('compra_detalle')->where('id', $l['compra_detalle_id'])->value('producto_id'), $datos['lineas']),
                array_map(fn ($l) => $l['vence_repuesto'] ?? null, $datos['lineas']),
                'vence_repuesto',
            );
        }

        try {
            $devolucion = DevolucionesCompra::registrar(
                usuario: Auth::user(),
                compra: $compra,
                lineas: $datos['lineas'],
                motivo: $datos['motivo'],
                espera: $datos['espera'],
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
            .match ($devolucion->espera) {
                'REPUESTO' => 'La reposición ya entró al stock.',
                'PENDIENTE' => 'El stock ya bajó, y queda anotado que el proveedor debe reponerla.',
                default => 'El stock ya bajó. Se espera la nota de crédito.',
            }
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
     * Lo que el proveedor trajo después, contra una devolución que lo esperaba.
     *
     * Cierra el hilo que faltaba: hasta aquí el reemplazo entraba como un
     * ingreso cualquiera y nadie podía responder «de lo que devolví, ¿qué me
     * repusieron y qué me siguen debiendo?».
     */
    public function reponer(Request $request, DevolucionCompra $devolucionCompra): RedirectResponse
    {
        $datos = $request->validate([
            'documento_externo' => ['nullable', 'string', 'max:30'],
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.linea_id' => ['required', Rule::exists('devolucion_compra_detalle', 'id')],
            'lineas.*.cantidad' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'lineas.*.vence' => ['nullable', 'date'],
        ], [
            'lineas.required' => 'Marca al menos un producto de los que trajo el proveedor.',
            'lineas.min' => 'Marca al menos un producto de los que trajo el proveedor.',
        ]);

        $this->exigirVencimiento(
            array_map(fn ($l) => (int) DB::table('devolucion_compra_detalle')->where('id', $l['linea_id'])->value('producto_id'), $datos['lineas']),
            array_map(fn ($l) => $l['vence'] ?? null, $datos['lineas']),
            'vence',
        );

        try {
            $devolucion = DevolucionesCompra::reponer(
                usuario: Auth::user(),
                devolucion: $devolucionCompra,
                lineas: $datos['lineas'],
                documentoExterno: $datos['documento_externo'] ?? null,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['lineas' => $e->getMessage()]);
        }

        $falta = $devolucion->pendiente_reposicion;

        return redirect()->route('devoluciones-compra.show', $devolucion)->with(
            'exito',
            'Reposición registrada: la mercadería ya entró al stock. '
            .($falta > 0
                ? 'Todavía faltan '.Config::cantidad($falta).' por reponer.'
                : 'Con esto el proveedor ya no debe nada de esta devolución.')
        );
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

    /** @return array<string, string> */
    private function esperas(): array
    {
        return collect(DevolucionCompra::ESPERAS)
            ->mapWithKeys(fn (string $e) => [
                $e => (new DevolucionCompra(['espera' => $e]))->etiqueta_espera,
            ])
            ->all();
    }
}
