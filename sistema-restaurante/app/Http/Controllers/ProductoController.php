<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OrdenaTablas;
use App\Models\Categoria;
use App\Models\Producto;
use App\Services\Auditor;
use App\Services\Inventario;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductoController extends Controller
{
    use OrdenaTablas;

    public function index(Request $request): View
    {
        $filtros = [
            'buscar' => $request->string('buscar')->toString(),
            'categoria' => $request->integer('categoria') ?: null,
            // Por omisión, solo lo que hoy está en el menú. Lo retirado no se
            // vende: verlo cuesta un clic, y no verlo ahorra recorrer cientos
            // de filas muertas para llegar a lo de hoy.
            // `?estado=` vacío —el que manda «Todos»— sigue mostrándolo todo.
            'estado' => $request->has('estado')
                ? $request->string('estado')->toString()
                : 'ACTIVO',
        ];

        $orden = $this->orden($request, [
            'nombre' => 'nombre',
            'codigo' => 'codigo',
            'categoria' => Categoria::select('nombre')->whereColumn('categorias.id', 'productos.categoria_id'),
            'venta' => 'precio_venta',
            // El precio de estante es el de venta más el impuesto: mismo orden,
            // pero con su propia clave para que solo se resalte una columna.
            'estante' => 'precio_venta',
            'estado' => 'activo',
        ], 'nombre');

        $productos = $this->aplicarOrden(
            Producto::with(['categoria:id,nombre'])
                ->buscar($filtros['buscar'])
                ->when($filtros['categoria'], fn ($q, $id) => $q->where('categoria_id', $id))
                ->when($filtros['estado'] !== '', fn ($q) => $q->where('activo', $filtros['estado'] === 'ACTIVO')),
            $orden
        )
            ->paginate(12)
            ->withQueryString();

        return view('productos.index', [
            // Sin miga de pan: el título ya es «Menú» y repetirlo al lado solo
            // ensucia, como en el resto de los listados (Ventas, Caja).
            'title' => 'Menú',
            'productos' => $productos,
            'filtros' => $filtros,
            'categorias' => Categoria::activas()->orderBy('nombre')->pluck('nombre', 'id'),
            'resumen' => $this->resumen(),
        ]);
    }

    public function create(): View
    {
        return view('productos.form', [
            'title' => 'Agregar al menú',
            'trail' => ['Menú' => route('productos.index')],
            'producto' => new Producto(['afecto_impuesto' => true, 'activo' => true]),
            'siguienteCodigo' => $this->siguienteCodigo(),
            ...$this->opciones(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        if ($request->hasFile('imagen')) {
            $datos['imagen'] = $request->file('imagen')->store('productos', 'public');
        }

        // Sin código escrito, el correlativo que ya se proponía en pantalla.
        $datos['codigo'] = filled($datos['codigo'] ?? null) ? $datos['codigo'] : $this->siguienteCodigo();

        $producto = DB::transaction(function () use ($datos, $request) {
            $producto = Producto::create($datos);

            if ($producto->controla_stock && $request->user()->tienePermiso('inventario.gestionar')) {
                Inventario::inicial($producto, (float) $request->input('stock_inicial', 0), $request->user());
            }

            return $producto;
        });

        Auditor::registrar('PRODUCTO_CREADO', 'productos', $producto->id, [
            'codigo' => $producto->codigo,
            'nombre' => $producto->nombre,
            'precio_venta' => $producto->precio_venta,
        ]);

        return redirect()->route('productos.show', $producto)
            ->with('exito', "«{$producto->nombre}» se guardó en el menú.");
    }

    public function show(Producto $producto): View
    {
        $producto->load(['categoria:id,nombre']);

        return view('productos.show', [
            'title' => $producto->nombre,
            'trail' => ['Menú' => route('productos.index')],
            'producto' => $producto,
        ]);
    }

    public function edit(Producto $producto): View
    {
        return view('productos.form', [
            'title' => 'Editar ítem del menú',
            'trail' => ['Menú' => route('productos.index')],
            'producto' => $producto,
            'siguienteCodigo' => null,
            ...$this->opciones(),
        ]);
    }

    public function update(Request $request, Producto $producto): RedirectResponse
    {
        $datos = $this->validar($request, $producto);

        // Retirarlo del menú es la forma de «eliminar» algo con historial: sin
        // el permiso de eliminar, el estado queda como estaba.
        if (! $request->user()->tienePermiso('registros.eliminar')) {
            unset($datos['activo']);
        }

        $datos = $this->resolverImagen($request, $producto, $datos);

        $precioAnterior = (float) $producto->precio_venta;

        // Vuelve a llevar inventario después de haberlo dejado: mientras estuvo
        // apagado las ventas no descontaron, y el stock guardado es viejo. Se
        // cuenta lo que hay y se ajusta, en la misma transacción.
        $reactiva = ($datos['controla_stock'] ?? false) && ! $producto->controla_stock && $producto->movimientos()->exists();

        if ($reactiva && ! $request->filled('stock_inicial')) {
            return back()->withInput()->with('error', 'Vuelve a llevar inventario: escribe cuántas unidades hay hoy. Mientras no llevó inventario, las ventas no lo descontaron.');
        }

        DB::transaction(function () use ($producto, $datos, $request, $reactiva) {
            $producto->update($datos);

            // Recién empieza a llevar inventario: el stock con el que arranca.
            // Una sola vez; después el stock se mueve con compras y tomas.
            if ($producto->controla_stock && ! $producto->movimientos()->exists()) {
                Inventario::inicial($producto->fresh(), (float) $request->input('stock_inicial', 0), $request->user());
            } elseif ($reactiva) {
                Inventario::ajuste($producto->fresh(), (float) $request->input('stock_inicial'),
                    'Vuelve a llevar inventario: stock contado', $request->user());
            }
        });

        // El cambio de precio se audita aparte: es la operación sensible
        // del menú (C3: precios no centralizados).
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
            ->with('exito', "«{$producto->nombre}» se actualizó en el menú.");
    }

    /**
     * Lo que ya tiene historial no se borra: se retira del menú. Su nombre y
     * su precio tienen que seguir siendo legibles en las ventas ya emitidas.
     *
     * Quien decide es la base: si alguna tabla lo referencia, el DELETE falla
     * con el 1451 de la clave foránea y entonces solo se retira.
     */
    public function destroy(Producto $producto): RedirectResponse
    {
        $nombre = $producto->nombre;

        try {
            $producto->delete();

            Auditor::registrar('PRODUCTO_ELIMINADO', 'productos', null, ['nombre' => $nombre]);

            return redirect()->route('productos.index')->with('exito', "«{$nombre}» se quitó del menú.");
        } catch (QueryException $e) {
            // 1451: otra tabla lo referencia.
            if ((int) ($e->errorInfo[1] ?? 0) !== 1451) {
                throw $e;
            }
        }

        $producto->update(['activo' => false]);

        Auditor::registrar('PRODUCTO_DESCATALOGADO', 'productos', $producto->id, ['codigo' => $producto->codigo]);

        return redirect()->route('productos.index')
            ->with('exito', "«{$nombre}» tiene historial registrado, así que se retiró del menú en lugar de eliminarse.");
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

    /**
     * Cifras de cabecera del menú.
     *
     * @return array<string, mixed>
     */
    private function resumen(): array
    {
        return [
            'total' => (int) Producto::where('activo', 1)->count(),
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
        ];
    }

    private function validar(Request $request, ?Producto $producto = null): array
    {
        // Se busca ANTES de validar para poder nombrar al culpable en el aviso.
        // Decir «ya existe» y nada más deja al usuario en un callejón sin
        // salida, y justo ahí acaba de demostrar cuál era su intención: casi
        // siempre no quería crear nada, quería corregir ese plato.
        $choque = $this->productoQueChoca($request, $producto);

        if ($choque) {
            session()->flash('producto_duplicado', [
                'id' => $choque->id,
                'nombre' => $choque->nombre,
                'codigo' => $choque->codigo,
            ]);
        }

        $sugerencia = $choque
            ? ' Si es lo mismo, edítalo en lugar de cargarlo de nuevo.'
            : '';

        $datos = $request->validate([
            'categoria_id' => ['required', Rule::exists('categorias', 'id')],
            // Opcional: si no viene, lo pone el sistema. Ya lo proponía en el
            // formulario, y exigirlo obligaba a inventar uno cuando nadie lo
            // tiene en la cabeza.
            'codigo' => [
                'nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('productos', 'codigo')->ignore($producto?->id),
            ],
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'precio_venta' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'afecto_impuesto' => ['boolean'],
            'activo' => ['boolean'],
            // Inventario: solo para lo que se compra hecho (las bebidas).
            'controla_stock' => ['boolean'],
            'stock_minimo' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'nombre_empaque' => ['nullable', 'string', 'max:20', 'required_with:contenido_empaque'],
            'contenido_empaque' => ['nullable', 'numeric', 'gt:1', 'max:9999999', 'required_with:nombre_empaque'],
            'costo' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'stock_inicial' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            // La foto es opcional. 2 MB alcanza de sobra para una miniatura de
            // mostrador y evita que el menú se vuelva pesado de cargar.
            'imagen' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'quitar_imagen' => ['boolean'],
        ], [
            'codigo.unique' => $choque
                ? "El código ya es de «{$choque->nombre}».".$sugerencia
                : 'Ya hay algo en el menú con ese código interno.',
            'codigo.regex' => 'El código admite letras, números, punto, guion y guion bajo.',
            'imagen.image' => 'La foto debe ser una imagen.',
            'imagen.mimes' => 'La foto tiene que ser JPG, PNG o WEBP.',
            'imagen.max' => 'La foto no puede pesar más de 2 MB.',
            'contenido_empaque.gt' => 'El empaque tiene que traer más de una unidad (una caja de 12, un paquete de 6).',
            'nombre_empaque.required_with' => 'Ponle nombre al empaque: Caja, Paquete, Six-pack…',
            'contenido_empaque.required_with' => 'Di cuántas unidades trae el empaque.',
        ], [
            'categoria_id' => 'categoría',
            'codigo' => 'código',
            'precio_venta' => 'precio de venta',
            'afecto_impuesto' => 'afecto a impuesto',
            'imagen' => 'foto',
            'stock_minimo' => 'stock mínimo',
            'nombre_empaque' => 'nombre del empaque',
            'contenido_empaque' => 'unidades por empaque',
            'stock_inicial' => 'stock inicial',
        ]);

        // La foto no se asigna en masa: el archivo se guarda aparte y lo que
        // llega aquí es el `UploadedFile`, no la ruta. El stock inicial
        // tampoco: entra por el kardex (Inventario::inicial), no a mano.
        unset($datos['imagen'], $datos['quitar_imagen'], $datos['stock_inicial']);

        // El inventario (llevar stock, empaque, mínimo, costo) es de quien
        // gestiona el inventario: con solo `productos.gestionar` se metía stock
        // inicial al kardex y se editaba el costo que congela la ganancia. Sin
        // ese permiso, esos campos quedan como estaban.
        if (! $request->user()->tienePermiso('inventario.gestionar')) {
            unset($datos['controla_stock'], $datos['stock_minimo'], $datos['nombre_empaque'], $datos['contenido_empaque'], $datos['costo']);

            return $datos;
        }

        $datos['controla_stock'] = $request->boolean('controla_stock');
        $datos['stock_minimo'] = (float) ($datos['stock_minimo'] ?? 0);

        // O las dos cosas del empaque, o ninguna (lo exige también la base).
        if (blank($datos['nombre_empaque'] ?? null) || blank($datos['contenido_empaque'] ?? null)) {
            $datos['nombre_empaque'] = null;
            $datos['contenido_empaque'] = null;
        }

        if (! $datos['controla_stock']) {
            // Un plato no tiene empaque de compra. El costo se conserva: la
            // ganancia de lo ya vendido lo sigue necesitando.
            $datos['nombre_empaque'] = null;
            $datos['contenido_empaque'] = null;
        }

        return $datos;
    }

    /**
     * El ítem del menú que ya usa ese código interno, si lo hay.
     *
     * Solo sirve para redactar el aviso: quien impide el duplicado sigue
     * siendo la regla `unique` —y por debajo, el índice único de la tabla—.
     */
    private function productoQueChoca(Request $request, ?Producto $producto): ?Producto
    {
        $codigo = trim($request->string('codigo')->toString());

        // Sin código no hay nada que comparar. Hace falta cortar aquí: un
        // `where` vacío no filtra nada y devolvería el primero del menú como
        // si fuera el que choca.
        if ($codigo === '') {
            return null;
        }

        return Producto::query()
            ->when($producto, fn ($q) => $q->whereKeyNot($producto->id))
            ->where('codigo', $codigo)
            ->first();
    }
}
