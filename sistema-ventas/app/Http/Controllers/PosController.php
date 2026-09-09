<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\CobroQr;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * La pantalla de venta. Todo el cobro ocurre en una sola página para que una
 * venta simple se complete con el teclado: código de barras, Enter, cobrar.
 */
class PosController extends Controller
{
    public function index(): View
    {
        $sesion = Cajas::sesionDe(Auth::user());

        return view('pos.index', [
            'title' => 'Punto de venta',
            'sesion' => $sesion,
            'metodosPago' => MetodoPago::activos()->orderBy('id')->get(),
            // Con el conteo al lado: un desplegable esconde que «Abarrotes»
            // tiene setenta productos y «Golosinas» dos.
            'categorias' => Categoria::activas()
                ->withCount(['productos' => fn ($q) => $q->activos()])
                ->having('productos_count', '>', 0)
                ->orderBy('nombre')
                ->get(['id', 'nombre']),
            'clientes' => Cliente::activos()->orderBy('nombre')->limit(50)->get()
                ->map(fn (Cliente $c) => [
                    'id' => $c->id,
                    'nombre' => $c->nombre,
                    'etiqueta' => $c->etiqueta,
                    'juridica' => $c->esJuridica(),
                ]),
            'tasaImpuesto' => Config::tasaImpuesto(),
            'moneda' => Config::moneda(),
            'descuentoMaximo' => (float) Config::get('descuento_max_cajero', '0'),
            'puedeDescontar' => Auth::user()->tienePermiso('ventas.descuento'),
            'clienteGenerico' => Config::get('cliente_generico_nombre', 'Cliente varios'),
            // El mostrador necesita saber QUÉ métodos se cobran por QR para
            // mostrar el código en vez de un campo de referencia.
            'metodosQr' => MetodoPago::activos()->where('codigo', 'QR')->pluck('id')->values(),
            'qrSimulado' => CobrosQr::estaSimulado(),
            'qrSegundosConsulta' => (int) config('qr.segundos_consulta', 4),
        ]);
    }

    /** Búsqueda incremental del mostrador: nombre, código interno o de barras. */
    public function buscar(Request $request): JsonResponse
    {
        $texto = $request->string('q')->toString();
        $categoria = $request->integer('categoria') ?: null;

        $productos = Producto::activos()
            ->with('unidadMedida:id,codigo,permite_decimal')
            ->buscar($texto)
            ->when($categoria, fn ($q, $id) => $q->where('categoria_id', $id))
            ->orderBy('nombre')
            ->limit(24)
            ->get();

        return response()->json(
            $productos->map(fn (Producto $p) => [
                'id' => $p->id,
                'codigo' => $p->codigo,
                'codigo_barras' => $p->codigo_barras,
                'nombre' => $p->nombre,
                'precio' => (float) $p->precio_venta,
                'precio_estante' => $p->precio_estante,
                'afecto' => (bool) $p->afecto_impuesto,
                'stock' => (float) $p->stock_actual,
                'unidad' => $p->unidadMedida?->codigo,
                'decimal' => (bool) $p->unidadMedida?->permite_decimal,
                'imagen' => $p->imagen_url,
                // Tiñe la pieza con la inicial mientras el producto no tenga foto.
                'categoria_id' => $p->categoria_id,
            ])
        );
    }

    /**
     * Precio y stock ACTUALES de los productos que ya están en el carrito.
     *
     * El carrito vive en Alpine y guarda el precio del momento en que se
     * agregó cada línea; si alguien edita el producto mientras el cajero
     * todavía no cobra, la pantalla queda mostrando un total y un vuelto
     * viejos aunque el servidor siempre cobre el precio de catálogo actual.
     * El front llama esto justo antes de cobrar para refrescar el carrito
     * con lo que realmente se va a cobrar, en vez de dejar que el cajero le
     * dé el cambio equivocado a alguien confiando en una cifra desactualizada.
     */
    public function precios(Request $request): JsonResponse
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->values();

        $productos = Producto::activos()->whereIn('id', $ids)->get();

        return response()->json(
            $productos->map(fn (Producto $p) => [
                'id' => $p->id,
                'precio' => (float) $p->precio_venta,
                'precio_estante' => $p->precio_estante,
                'stock' => (float) $p->stock_actual,
            ])
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $sesion = Cajas::sesionDe(Auth::user());

        if (! $sesion) {
            return redirect()->route('caja.index')
                ->with('error', 'Abre tu caja antes de registrar una venta.');
        }

        $datos = $request->validate([
            'cliente_id' => ['nullable', Rule::exists('clientes', 'id')->where('activo', 1)],
            'descuento' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'observacion' => ['nullable', 'string', 'max:255'],

            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.producto_id' => ['required', Rule::exists('productos', 'id')],
            'lineas.*.cantidad' => ['required', 'numeric', 'gt:0'],
            // El precio SIEMPRE sale del catálogo en `Ventas::registrar`, nunca
            // de aquí: no se valida ni se usa, aunque el formulario lo mande.

            'pagos' => ['required', 'array', 'min:1'],
            'pagos.*.metodo_pago_id' => ['required', Rule::exists('metodos_pago', 'id')->where('activo', 1)],
            // Sin importe significa «el resto»: lo calcula el servidor.
            'pagos.*.monto' => ['nullable', 'numeric', 'gt:0'],
            'pagos.*.monto_recibido' => ['nullable', 'numeric', 'min:0'],
            'pagos.*.referencia' => ['nullable', 'string', 'max:60'],
            // Una forma de pago puede venir respaldada por un cobro por QR ya
            // confirmado. Se valida abajo que esté pagado, libre y por el
            // importe correcto antes de registrar nada.
            'pagos.*.cobro_qr_id' => ['nullable', 'integer', 'exists:cobros_qr,id'],
        ], [
            'lineas.required' => 'La venta no tiene productos.',
            'pagos.required' => 'Falta indicar cómo se pagó.',
        ]);

        $cliente = isset($datos['cliente_id']) ? Cliente::find($datos['cliente_id']) : null;
        $descuento = (float) ($datos['descuento'] ?? 0);

        if ($error = $this->descuentoNoAutorizado($descuento, $datos['lineas'])) {
            return back()->with('error', $error)->withInput();
        }

        // Los cobros por QR se revisan ANTES de tocar el stock: si uno no está
        // pagado o ya se usó, la venta no llega a existir.
        if ($error = $this->cobrosQrNoUtilizables($datos['pagos'])) {
            return back()->with('error', $error)->withInput();
        }

        try {
            $venta = Ventas::registrar(
                sesion: $sesion,
                usuario: Auth::user(),
                lineas: $datos['lineas'],
                pagos: $datos['pagos'],
                cliente: $cliente,
                descuento: $descuento,
                observacion: $datos['observacion'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        } catch (Throwable $e) {
            // Los triggers de la base avisan con SIGNAL: se muestra su mensaje.
            return back()->with('error', $this->mensajeDeBase($e))->withInput();
        }

        $this->consumirCobrosQr($datos['pagos'], $venta);

        return redirect()->route('ventas.show', $venta)
            ->with('exito', 'Venta registrada. Comprobante '.$venta->comprobante?->numero_completo.'.');
    }

    /**
     * Revisa que cada cobro por QR sirva para pagar esta venta.
     *
     * Se hace antes de registrar: un cobro sin pagar, vencido o ya usado en
     * otra venta tiene que detener la operación mientras todavía no se
     * descontó stock ni se emitió comprobante.
     *
     * @param  array<int, array<string, mixed>>  $pagos
     */
    private function cobrosQrNoUtilizables(array $pagos): ?string
    {
        foreach ($pagos as $pago) {
            $id = $pago['cobro_qr_id'] ?? null;

            if (! $id) {
                continue;
            }

            $cobro = CobroQr::find($id);

            if (! $cobro || $cobro->usuario_id !== Auth::id()) {
                return 'Ese cobro por QR no es tuyo.';
            }

            if (! $cobro->estaPagado()) {
                return 'El cobro por QR todavía no está pagado.';
            }

            if ($cobro->venta_id !== null) {
                return 'Ese cobro por QR ya se usó en otra venta.';
            }

            // El importe del QR y el de la línea tienen que coincidir: si no,
            // se cobraría de menos amparándose en un QR de otro monto.
            $monto = $pago['monto'] ?? null;

            if ($monto !== null && abs((float) $monto - (float) $cobro->monto) > 0.01) {
                return 'El importe del cobro por QR no coincide con el de la venta.';
            }
        }

        return null;
    }

    /**
     * Ata los cobros a la venta ya registrada.
     *
     * Si algo falla aquí el dinero ya entró y la venta ya existe, así que no se
     * deshace nada: se deja constancia para poder atarlo a mano.
     *
     * @param  array<int, array<string, mixed>>  $pagos
     */
    private function consumirCobrosQr(array $pagos, Venta $venta): void
    {
        foreach ($pagos as $pago) {
            if (! ($pago['cobro_qr_id'] ?? null)) {
                continue;
            }

            try {
                CobrosQr::consumir((int) $pago['cobro_qr_id'], $venta);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * El descuento por encima del umbral necesita autorización (O4).
     *
     * La base se calcula con el precio del catálogo, nunca con lo que venga
     * en el request: si se confiara en `precio_unitario` del cliente, bastaría
     * mandarlo en 0 para que esta función no viera ningún descuento y dejara
     * pasar una venta regalada sin pedir autorización.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     */
    private function descuentoNoAutorizado(float $descuento, array $lineas): ?string
    {
        if ($descuento <= 0 || Auth::user()->tienePermiso('ventas.descuento')) {
            return null;
        }

        $precios = Producto::whereIn('id', array_column($lineas, 'producto_id'))
            ->pluck('precio_venta', 'id');

        $base = array_sum(array_map(
            fn ($l) => (float) $l['cantidad'] * (float) ($precios[$l['producto_id']] ?? 0),
            $lineas,
        ));

        $umbral = (float) Config::get('descuento_max_cajero', '0');
        $porcentaje = $base > 0 ? $descuento / $base * 100 : 0;

        if ($porcentaje > $umbral) {
            return 'Un descuento del '.round($porcentaje, 1).'% supera el máximo de '.$umbral.
                '% permitido sin autorización. Pide a un administrador que registre la venta.';
        }

        return null;
    }

    /** Extrae el texto del SIGNAL de MySQL, que llega envuelto en ruido. */
    private function mensajeDeBase(Throwable $e): string
    {
        if (preg_match('/SQLSTATE\[45000\].*?:\s*\d+\s+(.+?)(?: \(Connection:|$)/s', $e->getMessage(), $m)) {
            return trim($m[1]);
        }

        report($e);

        return 'No se pudo registrar la venta. Revisa el stock y los importes e inténtalo de nuevo.';
    }
}
