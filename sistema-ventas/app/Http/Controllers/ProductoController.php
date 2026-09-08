<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OrdenaTablas;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\UnidadMedida;
use App\Services\Auditor;
use App\Services\Inventario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductoController extends Controller
{
    use OrdenaTablas;

    public function index(Request $request): View
    {
        $filtros = [
            'buscar' => $request->string('buscar')->toString(),
            'categoria' => $request->integer('categoria') ?: null,
            'proveedor' => $request->integer('proveedor') ?: null,
            'estado' => $request->string('estado')->toString(),
            'stock' => $request->string('stock')->toString(),
        ];

        $orden = $this->orden($request, [
            'nombre' => 'nombre',
            'codigo' => 'codigo',
            'categoria' => Categoria::select('nombre')->whereColumn('categorias.id', 'productos.categoria_id'),
            'proveedor' => Proveedor::select('razon_social')->whereColumn('proveedores.id', 'productos.proveedor_id'),
            'compra' => 'precio_compra',
            'venta' => 'precio_venta',
            // El precio de estante es el de venta más el impuesto: mismo orden,
            // pero con su propia clave para que solo se resalte una columna.
            'estante' => 'precio_venta',
            'stock' => 'stock_actual',
            'estado' => 'activo',
        ], 'nombre');

        $productos = $this->aplicarOrden(
            Producto::with(['categoria:id,nombre', 'unidadMedida:id,codigo,nombre', 'proveedor:id,razon_social'])
                ->buscar($filtros['buscar'])
                ->when($filtros['categoria'], fn ($q, $id) => $q->where('categoria_id', $id))
                ->when($filtros['proveedor'], fn ($q, $id) => $q->where('proveedor_id', $id))
                ->when($filtros['estado'] !== '', fn ($q) => $q->where('activo', $filtros['estado'] === 'ACTIVO'))
                ->when($filtros['stock'] === 'BAJO', fn ($q) => $q->bajoMinimo())
                ->when($filtros['stock'] === 'AGOTADO', fn ($q) => $q->where('stock_actual', '<=', 0)),
            $orden
        )
            ->paginate(12)
            ->withQueryString();

        return view('productos.index', [
            'title' => 'Productos',
            'trail' => ['Catálogo' => route('productos.index')],
            'productos' => $productos,
            'filtros' => $filtros,
            'categorias' => Categoria::activas()->orderBy('nombre')->pluck('nombre', 'id'),
            'proveedores' => Proveedor::activos()->orderBy('razon_social')->pluck('razon_social', 'id'),
            'resumen' => $this->resumen(),
        ]);
    }

    public function create(): View
    {
        return view('productos.form', [
            'title' => 'Nuevo producto',
            'trail' => ['Catálogo' => route('productos.index'), 'Productos' => route('productos.index')],
            'producto' => new Producto(['afecto_impuesto' => true, 'activo' => true]),
            'siguienteCodigo' => $this->siguienteCodigo(),
            ...$this->opciones(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);
        $stockInicial = (float) $request->input('stock_inicial', 0);

        if ($request->hasFile('imagen')) {
            $datos['imagen'] = $request->file('imagen')->store('productos', 'public');
        }

        $producto = DB::transaction(function () use ($datos, $stockInicial) {
            $producto = Producto::create($datos);

            // El stock nunca se escribe directo: entra por el kardex.
            Inventario::cargaInicial($producto, $stockInicial);

            return $producto;
        });

        Auditor::registrar('PRODUCTO_CREADO', 'productos', $producto->id, [
            'codigo' => $producto->codigo,
            'nombre' => $producto->nombre,
            'precio_venta' => $producto->precio_venta,
            'stock_inicial' => $stockInicial,
        ]);

        return redirect()->route('productos.show', $producto)
            ->with('exito', "Producto «{$producto->nombre}» registrado.");
    }

    public function show(Producto $producto): View
    {
        $producto->load(['categoria:id,nombre', 'unidadMedida', 'proveedor:id,razon_social,telefono,email']);

        return view('productos.show', [
            'title' => $producto->nombre,
            'trail' => ['Catálogo' => route('productos.index'), 'Productos' => route('productos.index')],
            'producto' => $producto,
            'movimientos' => $producto->movimientos()
                ->with(['usuario:id,usuario', 'proveedor:id,razon_social'])
                ->orderByDesc('fecha')
                ->orderByDesc('id')
                ->paginate(15),
            'proveedores' => Proveedor::activos()->orderBy('razon_social')->pluck('razon_social', 'id'),
        ]);
    }

    public function edit(Producto $producto): View
    {
        return view('productos.form', [
            'title' => 'Editar producto',
            'trail' => ['Catálogo' => route('productos.index'), 'Productos' => route('productos.index')],
            'producto' => $producto,
            'siguienteCodigo' => null,
            ...$this->opciones(),
        ]);
    }

    public function update(Request $request, Producto $producto): RedirectResponse
    {
        $datos = $this->validar($request, $producto);

        $datos = $this->resolverImagen($request, $producto, $datos);

        $precioAnterior = (float) $producto->precio_venta;
        $producto->update($datos);

        // El cambio de precio se audita aparte: es la operación sensible
        // del catálogo (C3: precios no centralizados).
        if ($precioAnterior !== (float) $producto->precio_venta) {
            Auditor::registrar('CAMBIO_PRECIO', 'productos', $producto->id, [
                'codigo' => $producto->codigo,
                'anterior' => $precioAnterior,
                'nuevo' => (float) $producto->precio_venta,
            ]);
        }

        Auditor::registrar('PRODUCTO_ACTUALIZADO', 'productos', $producto->id, [
            'codigo' => $producto->codigo,
            'nombre' => $producto->nombre,
        ]);

        return redirect()->route('productos.show', $producto)
            ->with('exito', "Producto «{$producto->nombre}» actualizado.");
    }

    /**
     * Un producto con historial no se borra: se descataloga. Su nombre y su
     * precio tienen que seguir siendo legibles en las ventas ya emitidas.
     */
    public function destroy(Producto $producto): RedirectResponse
    {
        $nombre = $producto->nombre;

        if ($producto->movimientos()->exists()) {
            $producto->update(['activo' => false]);

            Auditor::registrar('PRODUCTO_DESCATALOGADO', 'productos', $producto->id, ['codigo' => $producto->codigo]);

            return redirect()->route('productos.index')
                ->with('exito', "«{$nombre}» tiene movimientos registrados, así que se descatalogó en lugar de eliminarse.");
        }

        $producto->delete();

        Auditor::registrar('PRODUCTO_ELIMINADO', 'productos', null, ['nombre' => $nombre]);

        return redirect()->route('productos.index')->with('exito', "Producto «{$nombre}» eliminado.");
    }

    // ------------------------------------------------------------- inventario

    /** Mercadería que llega del proveedor. */
    public function ingresar(Request $request, Producto $producto): RedirectResponse
    {
        $datos = $request->validate([
            'cantidad' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'proveedor_id' => ['nullable', Rule::exists('proveedores', 'id')],
            'documento_externo' => ['nullable', 'string', 'max:30'],
            'costo_unitario' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ], [
            'cantidad.gt' => 'La cantidad que ingresa debe ser mayor que cero.',
        ], [
            'cantidad' => 'cantidad',
            'proveedor_id' => 'proveedor',
            'documento_externo' => 'guía o factura',
            'costo_unitario' => 'costo unitario',
        ]);

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

        return back()->with('exito', "Ingresaron {$datos['cantidad']} {$producto->unidadMedida?->codigo} de «{$producto->nombre}». Stock: {$movimiento->stock_resultante}.");
    }

    /** Ajuste por conteo físico. */
    public function ajustar(Request $request, Producto $producto): RedirectResponse
    {
        $datos = $request->validate([
            'stock_contado' => ['required', 'numeric', 'min:0', 'max:999999'],
            'motivo' => ['required', 'string', 'max:255'],
        ], [
            'motivo.required' => 'Un ajuste sin motivo es un descuadre sin responsable: explica la diferencia.',
        ], [
            'stock_contado' => 'stock contado',
            'motivo' => 'motivo',
        ]);

        $this->verificarDecimales($producto, (float) $datos['stock_contado'], 'stock_contado');

        $movimiento = Inventario::ajuste($producto, (float) $datos['stock_contado'], $datos['motivo']);

        if (! $movimiento) {
            return back()->with('exito', 'El conteo coincide con el sistema: no hizo falta ajustar nada.');
        }

        Auditor::registrar('INVENTARIO_AJUSTE', 'productos', $producto->id, [
            'codigo' => $producto->codigo,
            'stock_anterior' => $movimiento->stock_anterior,
            'stock_resultante' => $movimiento->stock_resultante,
            'motivo' => $datos['motivo'],
        ]);

        $signo = $movimiento->variacion > 0 ? '+' : '';

        return back()->with('exito', "Inventario ajustado ({$signo}{$movimiento->variacion}). Stock: {$movimiento->stock_resultante}.");
    }

    // ----------------------------------------------------------------- apoyo

    /**
     * Guarda la foto nueva, o borra la actual si se pidió quitarla. En ambos
     * casos el archivo viejo se elimina del disco: si no, la carpeta se llena
     * de imágenes que ya no referencia nadie.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function resolverImagen(Request $request, Producto $producto, array $datos): array
    {
        $anterior = $producto->imagen;

        if ($request->hasFile('imagen')) {
            $datos['imagen'] = $request->file('imagen')->store('productos', 'public');
        } elseif ($request->boolean('quitar_imagen')) {
            $datos['imagen'] = null;
        } else {
            return $datos; // se conserva la que ya tenía
        }

        if ($anterior) {
            Storage::disk('public')->delete($anterior);
        }

        return $datos;
    }

    /** Una unidad que no admite decimales no puede tener medio artículo. */
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
     * Cifras de cabecera del catálogo.
     *
     * Va por el query builder y no por Eloquent a propósito: en un modelo, un
     * alias que coincide con un accesor (`bajo_minimo`) devuelve lo que calcula
     * el accesor sobre una fila vacía, no la suma.
     *
     * @return array<string, mixed>
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

    /** Propone el siguiente código correlativo del tipo P-0001. */
    private function siguienteCodigo(): string
    {
        $ultimo = Producto::where('codigo', 'regexp', '^P-[0-9]+$')
            ->orderByRaw('CAST(SUBSTRING(codigo, 3) AS UNSIGNED) DESC')
            ->value('codigo');

        $numero = $ultimo ? ((int) substr($ultimo, 2)) + 1 : 1;

        return 'P-'.str_pad((string) $numero, 4, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private function opciones(): array
    {
        return [
            'categorias' => Categoria::activas()->orderBy('nombre')->pluck('nombre', 'id'),
            'unidades' => UnidadMedida::orderBy('codigo')->get()
                ->mapWithKeys(fn (UnidadMedida $u) => [$u->id => $u->etiqueta]),
            'proveedores' => Proveedor::activos()->orderBy('razon_social')->pluck('razon_social', 'id'),
        ];
    }

    private function validar(Request $request, ?Producto $producto = null): array
    {
        // Se busca ANTES de validar para poder nombrar al culpable en el aviso.
        // Decir «ya existe» y nada más deja al usuario en un callejón sin
        // salida, y justo ahí acaba de demostrar cuál era su intención: casi
        // siempre no quería crear nada, quería sumarle stock a ese producto.
        $choque = $this->productoQueChoca($request, $producto);

        if ($choque) {
            session()->flash('producto_duplicado', [
                'id' => $choque->id,
                'nombre' => $choque->nombre,
                'codigo' => $choque->codigo,
            ]);
        }

        $sugerencia = $choque
            ? ' Si lo que quieres es sumarle stock, no lo cargues de nuevo: entra a Almacén → Inventario y usa «Ingresar mercadería».'
            : '';

        $datos = $request->validate([
            'categoria_id' => ['required', Rule::exists('categorias', 'id')],
            'unidad_medida_id' => ['required', Rule::exists('unidades_medida', 'id')],
            'proveedor_id' => ['nullable', Rule::exists('proveedores', 'id')],
            'codigo' => [
                'required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('productos', 'codigo')->ignore($producto?->id),
            ],
            'codigo_barras' => [
                'nullable', 'string', 'max:50', 'regex:/^[0-9]+$/',
                Rule::unique('productos', 'codigo_barras')->ignore($producto?->id),
            ],
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'precio_compra' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'precio_venta' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'afecto_impuesto' => ['boolean'],
            'stock_minimo' => ['required', 'numeric', 'min:0', 'max:999999'],
            'activo' => ['boolean'],
            // La foto es opcional. 2 MB alcanza de sobra para una miniatura de
            // mostrador y evita que el catálogo se vuelva pesado de cargar.
            'imagen' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'quitar_imagen' => ['boolean'],
        ], [
            'codigo.unique' => $choque
                ? "El código ya es de «{$choque->nombre}».".$sugerencia
                : 'Ya existe un producto con ese código interno.',
            'codigo.regex' => 'El código admite letras, números, punto, guion y guion bajo.',
            'codigo_barras.unique' => $choque
                ? "Ese código de barras ya es de «{$choque->nombre}».".$sugerencia
                : 'Ese código de barras ya está asignado a otro producto.',
            'codigo_barras.regex' => 'El código de barras solo admite dígitos.',
            'imagen.image' => 'La foto debe ser una imagen.',
            'imagen.mimes' => 'La foto tiene que ser JPG, PNG o WEBP.',
            'imagen.max' => 'La foto no puede pesar más de 2 MB.',
        ], [
            'categoria_id' => 'categoría',
            'unidad_medida_id' => 'unidad de medida',
            'proveedor_id' => 'proveedor',
            'codigo' => 'código',
            'codigo_barras' => 'código de barras',
            'precio_compra' => 'precio de compra',
            'precio_venta' => 'precio de venta',
            'stock_minimo' => 'stock mínimo',
            'afecto_impuesto' => 'afecto a impuesto',
            'imagen' => 'foto',
        ]);

        // La foto no se asigna en masa: el archivo se guarda aparte y lo que
        // llega aquí es el `UploadedFile`, no la ruta.
        unset($datos['imagen'], $datos['quitar_imagen']);

        return $datos;
    }

    /**
     * El producto que ya usa ese código interno o de barras, si lo hay.
     *
     * Solo sirve para redactar el aviso: quien impide el duplicado sigue
     * siendo la regla `unique` —y por debajo, el índice único de la tabla—.
     */
    private function productoQueChoca(Request $request, ?Producto $producto): ?Producto
    {
        $codigo = trim($request->string('codigo')->toString());
        $barras = trim($request->string('codigo_barras')->toString());

        // Sin ninguno de los dos no hay nada que comparar. Hace falta cortar
        // aquí: un `where` con un grupo vacío no filtra nada y devolvería el
        // primer producto del catálogo como si fuera el que choca.
        if ($codigo === '' && $barras === '') {
            return null;
        }

        return Producto::query()
            ->when($producto, fn ($q) => $q->whereKeyNot($producto->id))
            ->where(function ($q) use ($codigo, $barras) {
                if ($codigo !== '') {
                    $q->orWhere('codigo', $codigo);
                }

                if ($barras !== '') {
                    $q->orWhere('codigo_barras', $barras);
                }
            })
            ->first();
    }
}
