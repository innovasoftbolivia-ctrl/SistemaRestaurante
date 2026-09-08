<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OrdenaTablas;
use App\Models\Categoria;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Usuario;
use App\Services\Auditor;
use App\Services\Inventario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
    use OrdenaTablas;

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
            ->when($filtros['usuario'], fn ($q, $id) => $q->where('usuario_id', $id))
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
            'usuarios' => Usuario::orderBy('usuario')->pluck('usuario', 'id'),
            'resumen' => $this->resumenMovimientos($filtros),
        ]);
    }

    /** Mercadería que llega del proveedor, con el producto elegido en la pantalla. */
    public function ingreso(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'producto_id' => ['required', Rule::exists('productos', 'id')->where('activo', 1)],
            'cantidad' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'proveedor_id' => ['nullable', Rule::exists('proveedores', 'id')],
            'documento_externo' => ['nullable', 'string', 'max:30'],
            'costo_unitario' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ], [
            'cantidad.gt' => 'La cantidad que ingresa debe ser mayor que cero.',
            'producto_id.exists' => 'Ese producto no existe o está descatalogado.',
        ], [
            'producto_id' => 'producto',
            'cantidad' => 'cantidad',
            'proveedor_id' => 'proveedor',
            'documento_externo' => 'guía o factura',
            'costo_unitario' => 'costo unitario',
        ]);

        $producto = Producto::findOrFail($datos['producto_id']);
        $this->verificarDecimales($producto, (float) $datos['cantidad'], 'cantidad');

        $movimiento = Inventario::ingreso(
            producto: $producto,
            cantidad: (float) $datos['cantidad'],
            proveedorId: $datos['proveedor_id'] ?? null,
            documentoExterno: $datos['documento_externo'] ?? null,
            costoUnitario: isset($datos['costo_unitario']) ? (float) $datos['costo_unitario'] : null,
            motivo: $datos['motivo'] ?? null,
        );

        Auditor::registrar('INVENTARIO_INGRESO', 'productos', $producto->id, [
            'codigo' => $producto->codigo,
            'cantidad' => $datos['cantidad'],
            'stock_resultante' => $movimiento->stock_resultante,
        ]);

        return back()->with(
            'exito',
            "Ingresaron {$datos['cantidad']} {$producto->unidadMedida?->codigo} de «{$producto->nombre}». Stock: {$movimiento->stock_resultante}."
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

        $producto = Producto::findOrFail($datos['producto_id']);
        $this->verificarDecimales($producto, (float) $datos['stock_contado'], 'stock_contado');

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
        );
    }

    // ------------------------------------------------------------- auxiliares

    /**
     * La unidad manda: no se ingresan 2,5 gaseosas.
     *
     * Mismo control que en {@see ProductoController}; se repite aquí porque
     * esta pantalla no pasa por aquel formulario.
     */
    private function verificarDecimales(Producto $producto, float $cantidad, string $campo): void
    {
        $producto->loadMissing('unidadMedida');

        if (! $producto->unidadMedida?->permite_decimal && fmod($cantidad, 1.0) !== 0.0) {
            throw ValidationException::withMessages([
                $campo => "La unidad «{$producto->unidadMedida?->nombre}» no admite cantidades con decimales.",
            ]);
        }
    }

    /**
     * @return array<string, float|int>
     */
    private function resumen(): array
    {
        $totales = DB::table('productos')
            ->where('activo', 1)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(stock_actual * precio_compra), 0) AS valor')
            ->selectRaw('COALESCE(SUM(stock_actual <= stock_minimo), 0) AS bajo_minimo')
            ->selectRaw('COALESCE(SUM(stock_actual <= 0), 0) AS agotados')
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
            ->when($filtros['usuario'], fn ($q, $id) => $q->where('usuario_id', $id))
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
