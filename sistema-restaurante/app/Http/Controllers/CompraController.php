<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OrdenaTablas;
use App\Models\Compra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\Compras;
use App\Services\CompraSugerida;
use App\Support\Config;
use App\Support\Mensaje;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * La factura del proveedor, entera: lo que se compra hecho (las bebidas
 * embotelladas) llega por caja y se vende por unidad. La pantalla recibe cajas
 * y sueltas, y el costo por caja o por unidad; aquí se convierte todo a
 * unidades y App\Services\Compras sube el stock y fija el último costo.
 *
 * Una compra registrada no se edita ni se borra: ya movió el stock. Lo que se
 * cargó mal se corrige con un ajuste o se devuelve al proveedor.
 */
class CompraController extends Controller
{
    use OrdenaTablas;

    public function index(Request $request): View
    {
        $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $filtros = [
            'proveedor' => $request->integer('proveedor') ?: null,
            'desde' => $request->date('desde'),
            'hasta' => $request->date('hasta'),
        ];

        $orden = $this->orden($request, [
            'fecha' => 'fecha',
            'documento' => 'documento_externo',
            'proveedor' => Proveedor::select('razon_social')->whereColumn('proveedores.id', 'compras.proveedor_id'),
            'total' => 'total_documento',
        ], 'fecha', 'desc');

        $compras = $this->aplicarOrden(
            Compra::with(['proveedor:id,razon_social', 'usuario:id,usuario'])
                ->withCount('detalle')
                // `total_documento` y no `total`: el accesor `total` suma las
                // líneas cargadas y taparía la columna, con una consulta por fila.
                ->addSelect(['total_documento' => DB::table('compra_detalle')
                    ->selectRaw('COALESCE(SUM(importe), 0)')
                    ->whereColumn('compra_id', 'compras.id'),
                ])
                ->when($filtros['proveedor'], fn ($q, $id) => $q->where('proveedor_id', $id))
                // Las dos puntas del día y no `whereDate()`: así se usa el índice.
                ->when($filtros['desde'], fn ($q, $d) => $q->where('fecha', '>=', $d->copy()->startOfDay()))
                ->when($filtros['hasta'], fn ($q, $d) => $q->where('fecha', '<=', $d->copy()->endOfDay())),
            $orden
        )
            ->paginate(15)
            ->withQueryString();

        return view('compras.index', [
            'title' => 'Compras',
            'trail' => ['Inventario' => route('inventario.index')],
            'compras' => $compras,
            'filtros' => $filtros,
            'proveedores' => Proveedor::orderBy('razon_social')->pluck('razon_social', 'id'),
        ]);
    }

    /**
     * La compra sugerida: lo que está en su mínimo o por debajo, en cajas
     * cerradas y por proveedor. Cada grupo se abre como una compra nueva ya
     * llena, para revisarla y guardarla.
     */
    public function sugerida(): View
    {
        return view('compras.sugerida', [
            'title' => 'Compra sugerida',
            'trail' => ['Inventario' => route('inventario.index'), 'Compras' => route('compras.index')],
            'grupos' => CompraSugerida::porProveedor(),
        ]);
    }

    public function create(Request $request): View
    {
        $productos = Producto::conStock()->activos()
            ->with('categoria:id,nombre')
            ->orderBy('nombre')
            ->get()
            ->map(fn (Producto $p) => [
                'id' => $p->id,
                'codigo' => $p->codigo,
                'nombre' => $p->nombre,
                'categoria' => $p->categoria?->nombre,
                'contenido' => $p->contenido_empaque ? (float) $p->contenido_empaque : null,
                'empaque' => $p->nombre_empaque,
                'empaque_visible' => $p->empaque_visible,
                'stock' => $p->stock_en_empaques,
                'costo' => $p->costo !== null ? (float) $p->costo : null,
            ])
            ->values();

        // Desde la compra sugerida: las líneas de ese proveedor, ya llenas. Lo
        // escrito a mano (una validación que rebotó) manda sobre la sugerencia.
        $sugerido = $request->has('sugerida') ? $request->integer('sugerida') : null;

        return view('compras.create', [
            'title' => 'Registrar compra',
            'lineasSugeridas' => $sugerido !== null ? CompraSugerida::paraElFormulario($sugerido) : [],
            'proveedorSugerido' => $sugerido ?: null,
            'trail' => ['Inventario' => route('inventario.index'), 'Compras' => route('compras.index')],
            'proveedores' => Proveedor::activos()->orderBy('razon_social')->pluck('razon_social', 'id'),
            'productos' => $productos,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'proveedor_id' => ['required', 'integer', Rule::exists('proveedores', 'id')->where('activo', 1)],
            'documento_externo' => ['nullable', 'string', 'max:30'],
            'observacion' => ['nullable', 'string', 'max:255'],
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.producto_id' => ['required', 'integer', 'distinct', Rule::exists('productos', 'id')->where('controla_stock', 1)],
            'lineas.*.empaques' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'lineas.*.sueltas' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'lineas.*.costo' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'lineas.*.costo_por' => ['nullable', Rule::in(['empaque', 'unidad'])],
        ], [
            'lineas.required' => 'Agrega al menos un producto a la compra.',
            'lineas.min' => 'Agrega al menos un producto a la compra.',
            'proveedor_id.required' => 'Elige a qué proveedor se le compró.',
            'proveedor_id.exists' => 'Ese proveedor no existe o está desactivado.',
            'lineas.*.producto_id.required' => 'Elige el producto de cada línea.',
            'lineas.*.producto_id.exists' => 'Uno de los productos no lleva inventario: actívalo en su ficha del menú.',
            'lineas.*.producto_id.distinct' => 'La compra repite un producto: júntalo en una sola línea.',
            'lineas.*.costo.required' => 'Escribe el costo de cada línea.',
        ], [
            'proveedor_id' => 'proveedor',
            'documento_externo' => 'número de factura',
            'observacion' => 'observación',
            'lineas.*.empaques' => 'cajas',
            'lineas.*.sueltas' => 'sueltas',
            'lineas.*.costo' => 'costo',
        ]);

        $proveedor = Proveedor::findOrFail($datos['proveedor_id']);
        $productos = Producto::whereIn('id', array_column($datos['lineas'], 'producto_id'))->get()->keyBy('id');

        $lineas = [];

        foreach ($datos['lineas'] as $i => $linea) {
            $producto = $productos[(int) $linea['producto_id']];
            $contenido = (float) $producto->contenido_empaque;
            $conEmpaque = $contenido > 1;

            // El servidor rehace la cuenta: lo que el navegador mostró como
            // total no decide nada.
            $empaques = $conEmpaque ? (int) ($linea['empaques'] ?? 0) : 0;
            $cantidad = round($empaques * $contenido + (float) ($linea['sueltas'] ?? 0), 3);

            if ($cantidad <= 0) {
                throw ValidationException::withMessages([
                    "lineas.{$i}.sueltas" => "La cantidad de «{$producto->nombre}» tiene que ser mayor que cero.",
                ]);
            }

            $costo = (float) $linea['costo'];
            $porEmpaque = $conEmpaque && ($linea['costo_por'] ?? 'unidad') === 'empaque';

            $lineas[] = [
                'producto_id' => $producto->id,
                'cantidad' => $cantidad,
                // A cuatro decimales: una caja de 25,00 con 12 son 2,0833 por
                // unidad; a dos, la compra ya no sumaba la factura.
                'costo_unitario' => $porEmpaque ? round($costo / $contenido, 4) : $costo,
            ];
        }

        try {
            $compra = Compras::registrar(
                $proveedor,
                $request->user(),
                $lineas,
                $datos['documento_externo'] ?? null,
                $datos['observacion'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', Mensaje::de($e))->withInput();
        }

        $n = $compra->detalle->count();

        return redirect()->route('compras.show', $compra)->with(
            'exito',
            "Compra registrada: {$n} ".($n === 1 ? 'producto' : 'productos').' por '
                .Config::importe($compra->total).'. El stock ya está actualizado.'
        );
    }

    public function show(Compra $compra): View
    {
        $compra->load([
            'proveedor',
            'usuario:id,usuario',
            'detalle.producto:id,codigo,nombre,contenido_empaque,nombre_empaque',
            'devoluciones' => fn ($q) => $q->orderBy('fecha'),
            'devoluciones.detalle',
            'devoluciones.usuario:id,usuario',
        ]);

        return view('compras.show', [
            'title' => $compra->documento_externo ? "Compra {$compra->documento_externo}" : "Compra #{$compra->id}",
            'trail' => ['Inventario' => route('inventario.index'), 'Compras' => route('compras.index')],
            'compra' => $compra,
            // Si ya no queda nada por devolver, el botón no se ofrece.
            'sePuedeDevolver' => $compra->detalle->contains(fn ($l) => $l->devolvible > 0),
        ]);
    }
}
