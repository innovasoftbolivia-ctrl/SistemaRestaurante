<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\IngresaPorEmpaque;
use App\Http\Controllers\Concerns\OrdenaTablas;
use App\Models\Categoria;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\TomaInventarioDetalle;
use App\Models\Usuario;
use App\Services\Auditor;
use App\Services\Inventario;
use App\Support\Menu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * El almacén, como pantalla propia.
 *
 * Las mismas operaciones existían dentro de la ficha de cada producto, pero
 * llegar hasta ahí exige saber de antemano qué producto se busca. El trabajo
 * del almacenero es al revés: llega la mercadería y hay que cargarla, o toca
 * contar el estante y corregir. Por eso esta pantalla parte del stock —qué
 * falta, qué se acabó— y deja la acción a un clic de cada fila.
 *
 * No duplica reglas: todo movimiento sigue pasando por {@see Inventario}, que
 * es el único sitio donde cambia `productos.stock_actual`.
 */
class InventarioController extends Controller
{
    use IngresaPorEmpaque, OrdenaTablas;

    /** Existencias: qué hay, qué falta y qué se puede hacer al respecto. */
    public function index(Request $request): View
    {
        $filtros = [
            'buscar' => $request->string('buscar')->toString(),
            'categoria' => $request->integer('categoria') ?: null,
            'proveedor' => $request->integer('proveedor') ?: null,
            'stock' => $request->string('stock')->toString(),
        ];

        $orden = $this->orden($request, [
            'nombre' => 'nombre',
            'codigo' => 'codigo',
            'categoria' => Categoria::select('nombre')->whereColumn('categorias.id', 'productos.categoria_id'),
            'stock' => 'stock_actual',
            'minimo' => 'stock_minimo',
            // Lo que falta para llegar al mínimo: es la columna por la que se
            // ordena para saber qué reponer primero.
            'faltante' => DB::raw('(stock_minimo - stock_actual)'),
            'valor' => DB::raw('(stock_actual * precio_compra)'),
        ], 'nombre');

        $productos = $this->aplicarOrden(
            Producto::activos()
                ->with(['categoria:id,nombre', 'unidadMedida:id,codigo,nombre,permite_decimal', 'proveedor:id,razon_social'])
                ->buscar($filtros['buscar'])
                ->when($filtros['categoria'], fn ($q, $id) => $q->where('categoria_id', $id))
                ->when($filtros['proveedor'], fn ($q, $id) => $q->where('proveedor_id', $id))
                ->when($filtros['stock'] === 'BAJO', fn ($q) => $q->bajoMinimo())
                ->when($filtros['stock'] === 'AGOTADO', fn ($q) => $q->where('stock_actual', '<=', 0)),
            $orden
        )
            ->paginate(12)
            ->withQueryString();

        return view('inventario.index', [
            'title' => 'Inventario',
            'trail' => ['Almacén' => route('inventario.index')],
            'productos' => $productos,
            'filtros' => $filtros,
            'categorias' => Categoria::activas()->orderBy('nombre')->pluck('nombre', 'id'),
            'proveedores' => Proveedor::activos()->orderBy('razon_social')->pluck('razon_social', 'id'),
            'resumen' => $this->resumen(),
            'ultimos' => $this->ultimosMovimientos(),
        ]);
    }

    /**
     * El kardex de todo el almacén, no el de un producto suelto.
     *
     * Responde la pregunta que la ficha por producto no puede: «qué se movió
     * ayer», «qué cargó esta persona», «cuántos ajustes hubo este mes».
     */
    public function movimientos(Request $request): View
    {
        $filtros = [
            'producto' => $request->integer('producto') ?: null,
            'origen' => $request->string('origen')->toString(),
            'usuario' => $request->integer('usuario') ?: null,
            'desde' => $request->date('desde'),
            'hasta' => $request->date('hasta'),
        ];

        $origenes = array_combine(
            MovimientoInventario::ORIGENES,
            array_map(
                fn (string $o) => (new MovimientoInventario(['origen' => $o]))->etiqueta_origen,
                MovimientoInventario::ORIGENES,
            ),
        );

        $movimientos = MovimientoInventario::query()
            ->with([
                'producto:id,codigo,nombre,unidad_medida_id',
                'producto.unidadMedida:id,codigo',
                'usuario:id,usuario',
                'proveedor:id,razon_social',
            ])
            ->when($filtros['producto'], fn ($q, $id) => $q->where('producto_id', $id))
            ->when($filtros['origen'] !== '', fn ($q) => $q->where('origen', $filtros['origen']))
            ->when($filtros['usuario'], fn ($q, $id) => $q->where('usuario_id', $id)
                // Quién vendió qué es de cada cajero: sin reportes, el filtro
                // por responsable no alcanza a los movimientos del mostrador.
                ->when(! Menu::puede('reportes.ver'), fn ($m) => $m->whereNotIn('origen', MovimientoInventario::DEL_MOSTRADOR)))
            // Las dos puntas del día, y no `whereDate()`: envolver la columna
            // en una función impide usar el índice de `fecha` (mismo criterio
            // que en `DashboardController`).
            ->when($filtros['desde'], fn ($q, $d) => $q->where('fecha', '>=', $d->startOfDay()))
            ->when($filtros['hasta'], fn ($q, $d) => $q->where('fecha', '<=', $d->endOfDay()))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('inventario.movimientos', [
            'title' => 'Movimientos de inventario',
            'trail' => ['Almacén' => route('inventario.index'), 'Inventario' => route('inventario.index')],
            'movimientos' => $movimientos,
            'filtros' => $filtros,
            'origenes' => $origenes,
            'productos' => Producto::orderBy('nombre')->pluck('nombre', 'id'),
            // Sin reportes, la lista de responsables no enumera a los cajeros:
            // solo a quienes movieron stock fuera del mostrador.
            'usuarios' => Usuario::query()
                ->when(! Menu::puede('reportes.ver'), fn ($q) => $q->whereIn('id', MovimientoInventario::query()
                    ->whereNotIn('origen', MovimientoInventario::DEL_MOSTRADOR)
                    ->whereNotNull('usuario_id')
                    ->select('usuario_id')))
                ->orderBy('usuario')
                ->pluck('usuario', 'id'),
            'resumen' => $this->resumenMovimientos($filtros),
        ]);
    }

    /** Mercadería que llega del proveedor, con el producto elegido en la pantalla. */
    public function ingreso(Request $request): RedirectResponse
    {
        // El producto se resuelve antes de validar: de él depende si la fecha
        // de vencimiento es obligatoria.
        $producto = Producto::with('unidadMedida')->find($request->input('producto_id'));

        $datos = $request->validate([
            'producto_id' => ['required', Rule::exists('productos', 'id')->where('activo', 1)],
            ...$this->reglasDeCantidad(),
            'proveedor_id' => ['nullable', Rule::exists('proveedores', 'id')],
            'documento_externo' => ['nullable', 'string', 'max:30'],
            'costo_unitario' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'costo_por' => ['nullable', 'in:UNIDAD,EMPAQUE'],
            'actualizar_costo' => ['boolean'],
            'motivo' => ['nullable', 'string', 'max:255'],
            // Un producto que se lleva por lotes necesita la fecha: sin ella la
            // tanda nace sin vencimiento, queda fuera de las alertas y sale del
            // estante la última.
            'vence' => [Rule::requiredIf(fn () => (bool) $producto?->controla_vencimiento), 'nullable', 'date', 'after_or_equal:today'],
            'lote' => ['nullable', 'string', 'max:30'],
        ], [
            'producto_id.exists' => 'Ese producto no existe o está descatalogado.',
            'vence.required' => 'Este producto se controla por vencimiento: escribe la fecha de la tanda que llegó.',
            'vence.after_or_equal' => 'Esa fecha ya pasó: no se ingresa mercadería vencida.',
        ], [
            'producto_id' => 'producto',
            'cantidad' => 'cantidad',
            'empaques' => 'cantidad',
            'proveedor_id' => 'proveedor',
            'documento_externo' => 'guía o factura',
            'costo_unitario' => 'costo unitario',
        ]);

        ['cantidad' => $cantidad, 'detalle' => $detalle] = $this->unidadesQueIngresan($request, $producto);

        $costo = $this->costoPorUnidad($request, $producto);

        $movimiento = Inventario::ingreso(
            producto: $producto,
            cantidad: $cantidad,
            proveedorId: $datos['proveedor_id'] ?? null,
            documentoExterno: $datos['documento_externo'] ?? null,
            costoUnitario: $costo,
            motivo: $this->motivoDelIngreso($detalle, $datos['motivo'] ?? null),
            vence: $datos['vence'] ?? null,
            lote: $datos['lote'] ?? null,
        );

        // Después del movimiento: si el costo cambia, que cambie sobre una
        // entrada que ya quedó registrada, no sobre una que podría fallar.
        $cambioCosto = $this->actualizarCosto($request, $producto, $costo);

        Auditor::registrar('INVENTARIO_INGRESO', 'productos', $producto->id, [
            'codigo' => $producto->codigo,
            'cantidad' => $cantidad,
            'detalle' => $detalle,
            'stock_resultante' => $movimiento->stock_resultante,
        ]);

        return back()->with(
            'exito',
            $this->avisoDeIngreso($producto, $cantidad, $detalle, $movimiento->stock_resultante, $cambioCosto)
        );
    }

    /** Ajuste por conteo físico, con el producto elegido en la pantalla. */
    public function ajuste(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'producto_id' => ['required', Rule::exists('productos', 'id')->where('activo', 1)],
            'stock_contado' => ['required', 'numeric', 'min:0', 'max:999999'],
            'motivo' => ['required', 'string', 'max:255'],
        ], [
            'motivo.required' => 'Un ajuste sin motivo es un descuadre sin responsable: explica la diferencia.',
            'producto_id.exists' => 'Ese producto no existe o está descatalogado.',
        ], [
            'producto_id' => 'producto',
            'stock_contado' => 'stock contado',
            'motivo' => 'motivo',
        ]);

        $producto = Producto::with('unidadMedida')->findOrFail($datos['producto_id']);
        $this->exigirCantidadEntera($producto, (float) $datos['stock_contado'], 'stock_contado');

        $toma = TomaInventarioDetalle::where('producto_id', $producto->id)
            ->whereNotNull('contado')
            ->whereHas('toma', fn ($q) => $q->where('estado', 'ABIERTA'))
            ->value('toma_id');

        $movimiento = Inventario::ajuste($producto, (float) $datos['stock_contado'], $datos['motivo']);

        if (! $movimiento) {
            return back()->with('aviso', "El conteo coincide con el sistema: «{$producto->nombre}» queda igual.");
        }

        Auditor::registrar('INVENTARIO_AJUSTE', 'productos', $producto->id, [
            'codigo' => $producto->codigo,
            'stock_anterior' => $movimiento->stock_anterior,
            'stock_resultante' => $movimiento->stock_resultante,
            'motivo' => $datos['motivo'],
        ]);

        $signo = $movimiento->variacion > 0 ? '+' : '';

        return back()->with(
            'exito',
            "Ajustado «{$producto->nombre}»: {$movimiento->stock_anterior} → {$movimiento->stock_resultante} ({$signo}{$movimiento->variacion})."
            .($toma ? " Su conteo en la toma de inventario #{$toma} se borró: vuelve a contarlo." : '')
        );
    }

    // ------------------------------------------------------------- auxiliares

    /**
     * @return array<string, float|int>
     */
    private function resumen(): array
    {
        // El valor, sobre todo lo que hay en estante: un producto dado de baja
        // que todavía tiene stock sigue siendo plata invertida. Los conteos,
        // solo sobre el catálogo vigente.
        $totales = DB::table('productos')
            ->selectRaw('COALESCE(SUM(activo = 1), 0) AS total')
            ->selectRaw('COALESCE(SUM(stock_actual * precio_compra), 0) AS valor')
            ->selectRaw('COALESCE(SUM(activo = 1 AND stock_actual <= stock_minimo), 0) AS bajo_minimo')
            ->selectRaw('COALESCE(SUM(activo = 1 AND stock_actual <= 0), 0) AS agotados')
            ->first();

        return [
            'total' => (int) $totales->total,
            'valor' => (float) $totales->valor,
            'bajo_minimo' => (int) $totales->bajo_minimo,
            'agotados' => (int) $totales->agotados,
        ];
    }

    /** Las últimas entradas y ajustes, para ver de un vistazo qué se cargó hoy. */
    private function ultimosMovimientos(): Collection
    {
        return MovimientoInventario::with([
            'producto:id,codigo,nombre,unidad_medida_id',
            'producto.unidadMedida:id,codigo',
            'usuario:id,usuario',
        ])
            ->whereIn('origen', ['COMPRA', 'AJUSTE', 'INICIAL'])
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->limit(6)
            ->get();
    }

    /**
     * Totales del listado filtrado: cuántas unidades entraron y cuántas
     * salieron en ese recorte.
     *
     * @param  array<string, mixed>  $filtros
     * @return array<string, float|int>
     */
    private function resumenMovimientos(array $filtros): array
    {
        $fila = DB::table('movimientos_inventario')
            ->when($filtros['producto'], fn ($q, $id) => $q->where('producto_id', $id))
            ->when($filtros['origen'] !== '', fn ($q) => $q->where('origen', $filtros['origen']))
            ->when($filtros['usuario'], fn ($q, $id) => $q->where('usuario_id', $id)
                // Quién vendió qué es de cada cajero: sin reportes, el filtro
                // por responsable no alcanza a los movimientos del mostrador.
                ->when(! Menu::puede('reportes.ver'), fn ($m) => $m->whereNotIn('origen', MovimientoInventario::DEL_MOSTRADOR)))
            ->when($filtros['desde'], fn ($q, $d) => $q->where('fecha', '>=', $d->copy()->startOfDay()))
            ->when($filtros['hasta'], fn ($q, $d) => $q->where('fecha', '<=', $d->copy()->endOfDay()))
            ->selectRaw('COUNT(*) AS movimientos')
            ->selectRaw('COALESCE(SUM(GREATEST(stock_resultante - stock_anterior, 0)), 0) AS entraron')
            ->selectRaw('COALESCE(SUM(GREATEST(stock_anterior - stock_resultante, 0)), 0) AS salieron')
            ->first();

        return [
            'movimientos' => (int) $fila->movimientos,
            'entraron' => (float) $fila->entraron,
            'salieron' => (float) $fila->salieron,
        ];
    }
}
