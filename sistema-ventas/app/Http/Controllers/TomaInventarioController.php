<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Models\TomaInventario;
use App\Models\TomaInventarioDetalle;
use App\Services\TomasInventario;
use App\Support\Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Pantallas de la toma de inventario. Las reglas viven en
 * {@see TomasInventario}; esto las muestra y las dispara.
 */
class TomaInventarioController extends Controller
{
    /** Filtros de la lista de conteo. */
    public const ESTADOS = [
        'PENDIENTES' => 'Sin contar',
        'CONTADOS' => 'Contados',
        'DIFERENCIAS' => 'Con diferencia',
    ];

    public function index(): View
    {
        $tomas = TomaInventario::with('categoria:id,nombre', 'usuarioApertura:id,usuario', 'usuarioCierre:id,usuario')
            ->withCount(['lineas', 'lineas as contados_count' => fn ($q) => $q->whereNotNull('contado')])
            ->orderByDesc('fecha_apertura')
            ->orderByDesc('id')
            ->paginate(15);

        return view('tomas.index', [
            'title' => 'Toma de inventario',
            'trail' => ['Almacén' => route('inventario.index')],
            'tomas' => $tomas,
            'abierta' => TomaInventario::where('estado', 'ABIERTA')->first(),
            'categorias' => Categoria::activas()->orderBy('nombre')->pluck('nombre', 'id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'categoria_id' => ['nullable', Rule::exists('categorias', 'id')],
            'observacion' => ['nullable', 'string', 'max:255'],
        ], [], ['categoria_id' => 'categoría', 'observacion' => 'observación']);

        try {
            $toma = TomasInventario::abrir(Auth::user(), $datos['categoria_id'] ?? null, $datos['observacion'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('tomas.show', $toma)
            ->with('exito', 'Toma abierta. Cuenta cada producto y escribe lo que hay; se guarda solo.');
    }

    public function show(Request $request, TomaInventario $toma): View
    {
        $filtros = [
            'buscar' => trim($request->string('buscar')->toString()),
            'estado' => array_key_exists($request->string('estado')->toString(), self::ESTADOS)
                ? $request->string('estado')->toString() : '',
            'categoria' => $request->integer('categoria') ?: null,
        ];

        $lineas = $toma->lineas()
            ->join('productos', 'productos.id', '=', 'toma_inventario_detalle.producto_id')
            ->leftJoin('categorias', 'categorias.id', '=', 'productos.categoria_id')
            ->select('toma_inventario_detalle.*')
            ->with([
                'producto:id,codigo,codigo_barras,nombre,categoria_id,unidad_medida_id,contenido_empaque,nombre_empaque,imagen',
                'producto.unidadMedida:id,codigo,nombre,permite_decimal',
                'producto.categoria:id,nombre',
                'usuario:id,usuario',
            ])
            ->when($filtros['buscar'] !== '', fn ($q) => $q->where(function ($sub) use ($filtros) {
                $texto = $filtros['buscar'];
                $sub->where('productos.nombre', 'like', "%{$texto}%")
                    ->orWhere('productos.codigo', 'like', "%{$texto}%")
                    ->orWhere('productos.codigo_barras', 'like', "%{$texto}%");
            }))
            ->when($filtros['categoria'], fn ($q, $id) => $q->where('productos.categoria_id', $id))
            ->when($filtros['estado'] === 'PENDIENTES', fn ($q) => $q->whereNull('toma_inventario_detalle.contado'))
            ->when($filtros['estado'] === 'CONTADOS', fn ($q) => $q->whereNotNull('toma_inventario_detalle.contado'))
            ->when($filtros['estado'] === 'DIFERENCIAS', fn ($q) => $q->where('toma_inventario_detalle.diferencia', '<>', 0))
            // Por categoría y nombre: es el orden en que se recorre el local,
            // góndola por góndola.
            ->orderBy('categorias.nombre')
            ->orderBy('productos.nombre')
            ->paginate(50)
            ->withQueryString();

        // Con la pistola: si lo escaneado da un solo producto, el cursor va
        // directo a su casilla y se escribe la cantidad sin tocar el mouse.
        $enfocar = $filtros['buscar'] !== '' && $lineas->total() === 1 ? $lineas->first()->id : null;

        return view('tomas.show', [
            'title' => "Toma de inventario #{$toma->id}",
            'trail' => ['Almacén' => route('inventario.index'), 'Toma de inventario' => route('tomas.index')],
            'toma' => $toma->load('categoria:id,nombre', 'usuarioApertura:id,usuario', 'usuarioCierre:id,usuario'),
            'lineas' => $lineas,
            'filtros' => $filtros,
            'enfocar' => $enfocar,
            'resumen' => TomasInventario::resumen($toma),
            'categorias' => $toma->categoria_id ? collect() : Categoria::orderBy('nombre')->pluck('nombre', 'id'),
        ]);
    }

    /** Guarda el conteo de una línea. Lo llama la casilla de la lista, sin recargar. */
    public function contar(Request $request, TomaInventario $toma, TomaInventarioDetalle $linea): JsonResponse
    {
        $datos = $request->validate([
            'contado' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            // La hora en que se contó el estante, si se carga después (planilla en papel).
            'contado_en' => ['nullable', 'date', 'before_or_equal:now'],
        ], [
            'contado_en.before_or_equal' => 'La hora del conteo no puede ser posterior a ahora.',
        ], ['contado' => 'conteo', 'contado_en' => 'hora del conteo']);

        $linea->loadMissing('producto.unidadMedida');
        $contado = isset($datos['contado']) ? (float) $datos['contado'] : null;

        if ($contado !== null && ! $linea->producto->unidadMedida?->permite_decimal && floor($contado) != $contado) {
            throw ValidationException::withMessages([
                'contado' => "«{$linea->producto->nombre}» se cuenta por unidades enteras.",
            ]);
        }

        try {
            $linea = TomasInventario::contar(
                $linea,
                Auth::user(),
                $contado,
                filled($datos['contado_en'] ?? null) ? Carbon::parse($datos['contado_en']) : null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'contado' => $linea->contado === null ? null : (float) $linea->contado,
            'sistema' => $linea->stock_sistema === null ? null : (float) $linea->stock_sistema,
            'diferencia' => $linea->diferencia === null ? null : (float) $linea->diferencia,
            'valor' => $linea->valor_diferencia,
            'resumen' => TomasInventario::resumen($toma),
        ]);
    }

    public function cerrar(TomaInventario $toma): RedirectResponse
    {
        try {
            $resumen = TomasInventario::cerrar($toma, Auth::user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $sinContar = $resumen['total'] - $resumen['contados'];

        return redirect()->route('tomas.show', $toma)->with('exito', sprintf(
            'Toma cerrada. Se ajustaron %d producto(s): faltante %s, sobrante %s.%s',
            $resumen['ajustados'],
            Config::importe($resumen['faltante']),
            Config::importe($resumen['sobrante']),
            $sinContar > 0 ? " {$sinContar} producto(s) sin contar quedaron como estaban." : '',
        ));
    }

    public function cancelar(TomaInventario $toma): RedirectResponse
    {
        try {
            TomasInventario::cancelar($toma, Auth::user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tomas.index')
            ->with('exito', "Toma #{$toma->id} cancelada. El stock no se tocó.");
    }

    /**
     * Planilla para contar en papel mientras está abierta; resultado con las
     * diferencias una vez cerrada. Sin el stock del sistema en la planilla: se
     * cuenta lo que hay, no se confirma un número.
     */
    public function imprimir(TomaInventario $toma): View
    {
        $lineas = $toma->lineas()
            ->join('productos', 'productos.id', '=', 'toma_inventario_detalle.producto_id')
            ->leftJoin('categorias', 'categorias.id', '=', 'productos.categoria_id')
            ->select('toma_inventario_detalle.*', 'categorias.nombre AS categoria_nombre')
            ->with('producto:id,codigo,nombre,unidad_medida_id,contenido_empaque,nombre_empaque', 'producto.unidadMedida:id,codigo')
            ->orderBy('categorias.nombre')
            ->orderBy('productos.nombre')
            ->get();

        return view('tomas.imprimir', [
            'negocio' => Config::negocio(),
            'toma' => $toma->load('categoria:id,nombre', 'usuarioApertura:id,usuario', 'usuarioCierre:id,usuario'),
            'grupos' => $lineas->groupBy('categoria_nombre'),
            'resumen' => TomasInventario::resumen($toma),
        ]);
    }
}
