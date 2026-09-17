<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\IngresaPorEmpaque;
use App\Http\Controllers\Concerns\OrdenaTablas;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\UnidadMedida;
use App\Services\Auditor;
use App\Services\Inventario;
use App\Services\Lotes;
use App\Support\Palabras;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductoController extends Controller
{
    use IngresaPorEmpaque, OrdenaTablas;

    public function index(Request $request): View
    {
        $filtros = [
            'buscar' => $request->string('buscar')->toString(),
            'categoria' => $request->integer('categoria') ?: null,
            'proveedor' => $request->integer('proveedor') ?: null,
            // Por omisión, solo el catálogo vigente. Un descatalogado no se
            // compra ni se vende: verlo cuesta un clic, y no verlo ahorra
            // recorrer cientos de filas muertas para llegar a lo de hoy.
            // `?estado=` vacío —el que manda «Todos»— sigue mostrándolo todo.
            'estado' => $request->has('estado')
                ? $request->string('estado')->toString()
                : 'ACTIVO',
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

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $datos = $this->validar($request);

        $request->validate([
            ...$this->reglasDeCantidad(),
            'stock_inicial' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            // Un perecedero que entra con stock necesita la fecha de ese stock,
            // igual que un ingreso: sin ella, su lote nace sin fecha.
            'vence' => [
                Rule::requiredIf(fn () => $request->boolean('controla_vencimiento')
                    && ((float) $request->input('stock_inicial') > 0 || (int) $request->input('empaques') > 0 || (float) $request->input('sueltas') > 0)),
                'nullable', 'date', 'after_or_equal:today',
            ],
        ], [
            'vence.required' => 'Este producto se controla por vencimiento: escribe la fecha del stock con que entra.',
        ], ['stock_inicial' => 'stock inicial', 'empaques' => 'stock inicial', 'vence' => 'vencimiento']);

        if ($request->hasFile('imagen')) {
            $datos['imagen'] = $request->file('imagen')->store('productos', 'public');
        }

        // Sin código escrito, el correlativo que ya se proponía en pantalla.
        $datos['codigo'] = filled($datos['codigo'] ?? null) ? $datos['codigo'] : $this->siguienteCodigo();

        [$producto, $stockInicial, $detalle] = DB::transaction(function () use ($request, $datos) {
            $producto = Producto::create($datos);

            // La cuenta de cajas necesita la unidad de venta, y se acaba de
            // crear: sin cargarla, `unidadesQueIngresan()` no sabría si esta
            // unidad admite decimales.
            $producto->load('unidadMedida');

            // A diferencia de un ingreso, aquí un cero es una respuesta
            // legítima: se da de alta el producto y la mercadería llega mañana.
            ['cantidad' => $cantidad, 'detalle' => $detalle] = $this->unidadesQueIngresan(
                $request, $producto, campo: 'stock_inicial', obligatoria: false,
            );

            // El stock nunca se escribe directo: entra por el kardex.
            Inventario::cargaInicial($producto, $cantidad, $detalle, $request->input('vence'));

            return [$producto, $cantidad, $detalle];
        });

        Auditor::registrar('PRODUCTO_CREADO', 'productos', $producto->id, [
            'codigo' => $producto->codigo,
            'nombre' => $producto->nombre,
            'precio_venta' => $producto->precio_venta,
            'stock_inicial' => $stockInicial,
            'detalle' => $detalle,
        ]);

        // Alta rápida desde la pantalla de compras: quien está cargando una
        // factura no puede irse a otra pantalla y volver, porque perdería las
        // líneas que ya tecleó. Se responde el producto listo para usarse como
        // línea. Si la validación falla, Laravel ya devuelve el 422 con los
        // errores en JSON solo porque se pidió ese formato.
        if ($request->wantsJson()) {
            return response()->json($producto->comoLineaDeCompra(), 201);
        }

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
        $controlabaVencimiento = $producto->controla_vencimiento;

        // Con el producto bloqueado: una venta en curso termina antes (o
        // espera), así el lote sin fecha se abre por el stock que de verdad
        // quedó. Sin el candado, la venta descontaba stock sin tocar lotes y el
        // lote se abría por el stock de antes: más en lotes que en el estante.
        DB::transaction(function () use ($producto, $datos, $controlabaVencimiento) {
            Producto::whereKey($producto->id)->lockForUpdate()->first();
            $producto->update($datos);

            // Al encender el control, el stock que ya tenía existe y hay que
            // contarlo, pero su fecha no la sabe nadie: se abre un lote sin fecha
            // por esa diferencia. Inventar una fecha sería peor que admitir que no
            // se conoce, y la pantalla lo muestra aparte para que se vea que el
            // control todavía no está completo.
            if (! $controlabaVencimiento && $producto->controla_vencimiento) {
                Lotes::cuadrarConElStock($producto->fresh());
            }
        });

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
        // Mismo criterio que Almacén › Inventario: a un producto descatalogado
        // no se le carga mercadería.
        if (! $producto->activo) {
            return back()->with('error', "«{$producto->nombre}» está descatalogado: vuelve a darlo de alta antes de ingresarle mercadería.");
        }

        $datos = $request->validate([
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
            'vence.required' => 'Este producto se controla por vencimiento: escribe la fecha de la tanda que llegó.',
            'vence.after_or_equal' => 'Esa fecha ya pasó: no se ingresa mercadería vencida.',
        ], [
            'cantidad' => 'cantidad',
            'empaques' => 'cantidad',
            'proveedor_id' => 'proveedor',
            'documento_externo' => 'guía o factura',
            'costo_unitario' => 'costo unitario',
            'vence' => 'fecha de vencimiento',
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

        $this->exigirCantidadEntera($producto, (float) $datos['stock_contado'], 'stock_contado');

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

    /**
     * Una unidad que se vende entera (UND, CAJA, PQT) no puede terminar con
     * stock fraccionario: la venta, el ajuste y la toma exigen enteros, y ese
     * medio que sobra no se podría vender ni corregir.
     *
     * Dos puertas por donde entraba: un empaque de 2,5 unidades (3 cajas
     * metían 7,5) y pasar a UND un producto que se vendía por kilo con 68,5 kg.
     *
     * @param  array<string, mixed>  $datos
     */
    private function validarUnidadEntera(Request $request, ?Producto $producto, array $datos): void
    {
        $unidad = UnidadMedida::find($datos['unidad_medida_id']);

        if (! $unidad || $unidad->permite_decimal) {
            return;
        }

        $errores = [];
        $contenido = $request->boolean('viene_en_empaque') ? ($datos['contenido_empaque'] ?? null) : null;

        if ($contenido !== null && ! self::esEntero((float) $contenido)) {
            $errores['contenido_empaque'] = "El producto se vende por {$unidad->nombre}, entero: el empaque tiene que traer un número entero de unidades.";
        }

        if ($producto
            && (int) $producto->unidad_medida_id !== (int) $unidad->id
            && ! self::esEntero((float) $producto->stock_actual)) {
            $errores['unidad_medida_id'] = sprintf(
                'Tiene %s en stock, con decimales. Ajusta el stock a un número entero antes de pasarlo a %s, que se vende entero.',
                rtrim(rtrim(number_format((float) $producto->stock_actual, 3, '.', ''), '0'), '.'),
                $unidad->nombre,
            );
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }
    }

    private static function esEntero(float $cantidad): bool
    {
        return abs($cantidad - round($cantidad)) < 0.0005;
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
        $unidades = UnidadMedida::orderBy('codigo')->get();

        return [
            'categorias' => Categoria::activas()->orderBy('nombre')->pluck('nombre', 'id'),
            'unidades' => $unidades->mapWithKeys(fn (UnidadMedida $u) => [$u->id => $u->etiqueta]),
            // El formulario necesita algo más que la etiqueta: con la unidad
            // elegida arma en vivo la frase «compras 1 caja de 24 UND y vendes
            // de a 1 UND», y decide si el stock inicial admite decimales.
            'unidadesInfo' => $unidades->mapWithKeys(fn (UnidadMedida $u) => [$u->id => [
                'codigo' => $u->codigo,
                'nombre' => mb_strtolower($u->nombre),
                'decimal' => (bool) $u->permite_decimal,
            ]]),
            'proveedores' => Proveedor::activos()->orderBy('razon_social')->pluck('razon_social', 'id'),
        ];
    }

    /**
     * Los empaques con los que llega la mercadería a una tienda, con su plural.
     *
     * El plural viaja calculado desde aquí y no se arma en el navegador: las
     * reglas ya existen en {@see Palabras} y una segunda copia en JavaScript se
     * separaría de esta el día que alguien corrija una. Además son cinco
     * palabras conocidas, no hace falta resolverlo en vivo.
     *
     * La lista mezcla a propósito lo que se cuenta (caja, plancha, docena), lo
     * que se pesa (saco, fardo, balde) y lo que se mide (bidón, turril): el
     * empaque no depende de la unidad en la que se vende, y separarlos en
     * listas obligaría a adivinar a qué familia pertenece cada unidad.
     *
     * @return array<string, string>
     */
    public static function empaquesUsuales(): array
    {
        $nombres = ['Caja', 'Paquete', 'Bolsa', 'Saco', 'Fardo', 'Plancha',
            'Docena', 'Bidón', 'Turril', 'Balde', 'Blíster'];

        return array_combine($nombres, array_map(Palabras::plural(...), $nombres));
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
            // El empaque es opcional, pero a medias no vale: si se marca que
            // el producto viene en caja hay que decir cómo se llama y cuántas
            // unidades trae, que es lo único que hace útil el dato.
            //
            // `exclude_unless` y no `nullable`: los dos campos siguen en la
            // página cuando la casilla se desmarca —solo se ocultan— y el
            // navegador los envía igual, con lo que hubiera escrito antes de
            // cambiar de idea. Sin excluirlos, un «Caja / 1» abandonado hacía
            // fallar `min:2` y ya no se podía guardar un producto a granel.
            // Excluidos, la casilla manda y esos restos ni se miran.
            'viene_en_empaque' => ['boolean'],
            'nombre_empaque' => ['exclude_unless:viene_en_empaque,1', 'required', 'string', 'min:2', 'max:20'],
            'contenido_empaque' => ['exclude_unless:viene_en_empaque,1', 'required', 'numeric', 'gt:1', 'max:999999'],
            // Opcional: si no viene, lo pone el sistema. Ya lo proponía en el
            // formulario, y exigirlo obligaba a inventar uno en el alta rápida
            // desde la pantalla de compras, donde nadie lo tiene en la cabeza.
            'codigo' => [
                'nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('productos', 'codigo')->ignore($producto?->id),
            ],
            'codigo_barras' => [
                'nullable', 'string', 'max:50', 'regex:/^[0-9]+$/',
                Rule::unique('productos', 'codigo_barras')->ignore($producto?->id),
            ],
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'precio_compra' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            // Quien da de alta el producto tiene delante la factura, y ahí el
            // costo está por caja. Se acepta como viene y el sistema divide.
            'precio_compra_por' => ['nullable', 'in:UNIDAD,EMPAQUE'],
            'precio_venta' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'afecto_impuesto' => ['boolean'],
            // Encender el control es lo que hace que el stock de este producto
            // se lleve por lotes con fecha. Se decide producto por producto:
            // el detergente no vence y pedirle una fecha cada vez que llega es
            // la forma más rápida de que alguien escriba cualquier cosa.
            'controla_vencimiento' => ['boolean'],
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
            'nombre_empaque.required' => 'Ponle nombre al empaque: caja, paquete, plancha…',
            'contenido_empaque.required' => 'Falta decir cuántas unidades trae el empaque.',
            'contenido_empaque.gt' => 'Un empaque de una sola unidad no ahorra ninguna cuenta. Si el producto no viene en caja, desmarca la casilla.',
            'imagen.image' => 'La foto debe ser una imagen.',
            'imagen.mimes' => 'La foto tiene que ser JPG, PNG o WEBP.',
            'imagen.max' => 'La foto no puede pesar más de 2 MB.',
        ], [
            'categoria_id' => 'categoría',
            'unidad_medida_id' => 'unidad de medida',
            'proveedor_id' => 'proveedor',
            'nombre_empaque' => 'nombre del empaque',
            'contenido_empaque' => 'contenido del empaque',
            'codigo' => 'código',
            'codigo_barras' => 'código de barras',
            'precio_compra' => 'precio de compra',
            'precio_venta' => 'precio de venta',
            'stock_minimo' => 'stock mínimo',
            'afecto_impuesto' => 'afecto a impuesto',
            'imagen' => 'foto',
        ]);

        $this->validarUnidadEntera($request, $producto, $datos);

        // La foto no se asigna en masa: el archivo se guarda aparte y lo que
        // llega aquí es el `UploadedFile`, no la ruta.
        unset($datos['imagen'], $datos['quitar_imagen']);

        // La casilla decide, no los campos: si se desmarca «viene en empaque»,
        // el contenido y el nombre se guardan en NULL. Si no, un producto que
        // dejó de venir en caja seguiría ofreciendo la casilla de cajas al
        // ingresar mercadería. Con la casilla desmarcada las dos claves ni
        // llegan hasta aquí, porque las excluyó la validación.
        $enEmpaque = $request->boolean('viene_en_empaque') && isset($datos['contenido_empaque']);

        $datos['contenido_empaque'] = $enEmpaque ? (float) $datos['contenido_empaque'] : null;
        $datos['nombre_empaque'] = $enEmpaque ? trim((string) $datos['nombre_empaque']) : null;

        unset($datos['viene_en_empaque']);

        // El costo se guarda SIEMPRE por unidad de venta: es lo que leen el
        // margen, el valor del inventario y los reportes. Si se escribió por
        // caja, la división se hace aquí y una sola vez. Va después de resolver
        // el empaque a propósito: sin `contenido_empaque` no hay entre qué
        // dividir, y una casilla desmarcada tiene que dejar el número como está.
        if ($request->input('precio_compra_por') === 'EMPAQUE' && $datos['contenido_empaque']) {
            $datos['precio_compra'] = round((float) $datos['precio_compra'] / $datos['contenido_empaque'], 2);
        }

        unset($datos['precio_compra_por']);

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
