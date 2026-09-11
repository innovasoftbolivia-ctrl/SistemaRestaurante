<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\IngresaPorEmpaque;
use App\Http\Controllers\Concerns\OrdenaTablas;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\UnidadMedida;
use App\Services\Compras;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Las compras al proveedor, como documento.
 *
 * Convive con «Ingresar mercadería» del almacén y no lo reemplaza: aquella es
 * la vía de una línea —llegó una caja suelta, no hay papel que archivar— y esta
 * es la de la factura completa. El esquema previó las dos desde el principio
 * (`movimientos_inventario` guarda `proveedor_id` y `documento_externo` sueltos
 * para la primera, y `compra_id` para la segunda).
 *
 * No hay permiso propio: quien puede ingresar mercadería puede registrar la
 * compra, porque es el mismo acto. Inventar `compras.registrar` habría obligado
 * a repartirlo por los roles sin que nadie ganara nada.
 */
class CompraController extends Controller
{
    use IngresaPorEmpaque, OrdenaTablas;

    /** Qué compras se hicieron, a quién y por cuánto. */
    public function index(Request $request): View
    {
        $filtros = [
            'buscar' => $request->string('buscar')->toString(),
            'proveedor' => $request->integer('proveedor') ?: null,
            'desde' => $request->date('desde'),
            'hasta' => $request->date('hasta'),
        ];

        $orden = $this->orden($request, [
            'fecha' => 'fecha',
            'documento' => 'documento_externo',
            'proveedor' => Proveedor::select('razon_social')->whereColumn('proveedores.id', 'compras.proveedor_id'),
        ], 'fecha', 'desc');

        $compras = $this->aplicarOrden(
            Compra::with(['proveedor:id,razon_social', 'usuario:id,usuario'])
                ->withCount('detalle')
                // El total sale de una columna generada del detalle, y se trae
                // con una subconsulta para no cargar las líneas de quince
                // compras solo para sumarlas.
                //
                // `total_documento` y no `total`: `Compra::getTotalAttribute()`
                // suma `$this->detalle`, así que una columna llamada igual
                // quedaría tapada por el accesor y el listado dispararía una
                // consulta por fila sin que se notara.
                ->addSelect(['total_documento' => DB::table('compra_detalle')
                    ->selectRaw('COALESCE(SUM(importe), 0)')
                    ->whereColumn('compra_id', 'compras.id'),
                ])
                ->when($filtros['buscar'] !== '', fn ($q) => $q->where('documento_externo', 'like', "%{$filtros['buscar']}%"))
                ->when($filtros['proveedor'], fn ($q, $id) => $q->where('proveedor_id', $id))
                // Las dos puntas del día, y no `whereDate()`: envolver la
                // columna en una función impide usar el índice de `fecha`.
                ->when($filtros['desde'], fn ($q, $d) => $q->where('fecha', '>=', $d->startOfDay()))
                ->when($filtros['hasta'], fn ($q, $d) => $q->where('fecha', '<=', $d->endOfDay())),
            $orden
        )
            ->paginate(15)
            ->withQueryString();

        return view('compras.index', [
            'title' => 'Compras',
            'trail' => ['Almacén' => route('inventario.index')],
            'compras' => $compras,
            'filtros' => $filtros,
            'proveedores' => Proveedor::activos()->orderBy('razon_social')->pluck('razon_social', 'id'),
            'resumen' => $this->resumen($filtros),
        ]);
    }

    public function create(): View
    {
        return view('compras.create', [
            'title' => 'Registrar compra',
            'trail' => ['Almacén' => route('inventario.index'), 'Compras' => route('compras.index')],
            'proveedores' => Proveedor::activos()->orderBy('razon_social')->pluck('razon_social', 'id'),
            // Para el alta rápida sin salir de la pantalla: el producto que
            // llega hoy por primera vez y el proveedor nuevo son el caso
            // normal de una compra, no la excepción.
            'categorias' => Categoria::activas()->orderBy('nombre')->pluck('nombre', 'id'),
            'unidades' => UnidadMedida::orderBy('codigo')->get()
                ->mapWithKeys(fn (UnidadMedida $u) => [$u->id => $u->etiqueta]),
            // La misma lista que el alta de producto, no una copia: si mañana
            // se agrega «Jaba» tiene que aparecer en los dos sitios.
            'empaques' => ProductoController::empaquesUsuales(),
        ]);
    }

    /**
     * Búsqueda de productos para armar las líneas.
     *
     * Es hermana de `pos.productos` pero no la misma: aquel vive detrás de
     * `ventas.registrar` —el almacenero no lo tiene— y devuelve precio de venta
     * y stock, que aquí no pintan nada. Esta devuelve lo que hace falta para
     * cargar una línea: el empaque, para contar en cajas, y el costo de
     * referencia, para no tener que recordarlo.
     */
    public function buscar(Request $request): JsonResponse
    {
        $productos = Producto::activos()
            ->with('unidadMedida:id,codigo,nombre,permite_decimal')
            ->buscar($request->string('q')->toString())
            ->orderBy('nombre')
            ->limit(15)
            ->get();

        return response()->json($productos->map->comoLineaDeCompra());
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'proveedor_id' => ['required', Rule::exists('proveedores', 'id')->where('activo', 1)],
            'documento_externo' => ['nullable', 'string', 'max:30'],
            'observacion' => ['nullable', 'string', 'max:255'],
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.producto_id' => ['required', Rule::exists('productos', 'id')],
            // Cada línea llega como llegó la mercadería —cajas y sueltas— o
            // como un total suelto, igual que en las otras dos puertas. El
            // servidor rehace la cuenta: lo que el navegador diga que suma no
            // decide nada.
            'lineas.*.cantidad' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'lineas.*.empaques' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'lineas.*.sueltas' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'lineas.*.costo_unitario' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'lineas.*.actualizar_costo' => ['nullable', 'boolean'],
            // Solo la piden las líneas de productos con control de vencimiento.
            'lineas.*.vence' => ['nullable', 'date'],
            'lineas.*.lote' => ['nullable', 'string', 'max:30'],
        ], [
            'lineas.required' => 'Agrega al menos un producto a la compra.',
            'lineas.min' => 'Agrega al menos un producto a la compra.',
            'proveedor_id.required' => 'Di de qué proveedor es esta compra.',
        ], [
            'proveedor_id' => 'proveedor',
            'documento_externo' => 'guía o factura',
        ]);

        $proveedor = Proveedor::findOrFail($datos['proveedor_id']);

        try {
            $compra = Compras::registrar(
                usuario: Auth::user(),
                proveedor: $proveedor,
                lineas: $this->resolverLineas($datos['lineas']),
                documentoExterno: $datos['documento_externo'] ?? null,
                observacion: $datos['observacion'] ?? null,
            );
        } catch (RuntimeException $e) {
            // Las reglas del servicio —producto descatalogado, media gaseosa—
            // vuelven al formulario como un error más, sin perder lo tecleado.
            throw ValidationException::withMessages(['lineas' => $e->getMessage()]);
        }

        $lineas = $compra->detalle->count();

        return redirect()->route('compras.show', $compra)->with(
            'exito',
            "Compra registrada: {$lineas} ".($lineas === 1 ? 'producto' : 'productos')
            .' por '.Compras::total($compra).'. El stock ya está actualizado.'
        );
    }

    /**
     * Convierte lo que se tecleó en lo que entiende el servicio.
     *
     * Las cajas y las sueltas se traducen aquí, con la misma cuenta que usan la
     * ficha del producto y el almacén, y de paso sale la frase del kardex. El
     * servicio recibe solo unidades: no tiene por qué saber que existen cajas.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     * @return array<int, array<string, mixed>>
     */
    private function resolverLineas(array $lineas): array
    {
        return collect($lineas)->map(function (array $linea) {
            $producto = Producto::with('unidadMedida')->findOrFail($linea['producto_id']);

            ['cantidad' => $cantidad, 'detalle' => $detalle] = $this->unidadesDeclaradas(
                producto: $producto,
                cantidad: $linea['cantidad'] ?? null,
                empaques: isset($linea['empaques']) ? (int) $linea['empaques'] : null,
                sueltas: isset($linea['sueltas']) ? (float) $linea['sueltas'] : null,
                campo: 'lineas',
            );

            return [
                'producto_id' => $producto->id,
                'cantidad' => $cantidad,
                'costo_unitario' => (float) $linea['costo_unitario'],
                'detalle' => $detalle,
                'actualizar_costo' => (bool) ($linea['actualizar_costo'] ?? false),
                'vence' => $linea['vence'] ?? null,
                'lote' => $linea['lote'] ?? null,
            ];
        })->all();
    }

    /** El documento: sus líneas, su total y el rastro que dejó en el kardex. */
    public function show(Compra $compra): View
    {
        $compra->load([
            'proveedor',
            'usuario:id,usuario',
            'detalle.producto:id,codigo,nombre,unidad_medida_id,contenido_empaque,nombre_empaque',
            'detalle.producto.unidadMedida:id,codigo',
        ]);

        return view('compras.show', [
            'title' => $compra->documento_externo ?: "Compra #{$compra->id}",
            'trail' => ['Almacén' => route('inventario.index'), 'Compras' => route('compras.index')],
            'compra' => $compra,
        ]);
    }

    /**
     * Cifras del recorte que se está mirando.
     *
     * @param  array<string, mixed>  $filtros
     * @return array<string, float|int>
     */
    private function resumen(array $filtros): array
    {
        $fila = DB::table('compras as c')
            ->leftJoin('compra_detalle as d', 'd.compra_id', '=', 'c.id')
            ->when($filtros['buscar'] !== '', fn ($q) => $q->where('c.documento_externo', 'like', "%{$filtros['buscar']}%"))
            ->when($filtros['proveedor'], fn ($q, $id) => $q->where('c.proveedor_id', $id))
            ->when($filtros['desde'], fn ($q, $d) => $q->where('c.fecha', '>=', $d->copy()->startOfDay()))
            ->when($filtros['hasta'], fn ($q, $d) => $q->where('c.fecha', '<=', $d->copy()->endOfDay()))
            ->selectRaw('COUNT(DISTINCT c.id) AS compras')
            ->selectRaw('COALESCE(SUM(d.importe), 0) AS total')
            ->selectRaw('COUNT(d.id) AS lineas')
            ->first();

        return [
            'compras' => (int) $fila->compras,
            'total' => (float) $fila->total,
            'lineas' => (int) $fila->lineas,
        ];
    }
}
