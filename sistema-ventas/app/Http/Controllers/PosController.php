<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\Pedidos;
use App\Support\Config;
use App\Support\Mensaje;
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
 * venta simple se complete con el teclado: código del plato, Enter, cobrar.
 *
 * Es por donde entra todo lo que se pide en el local: el cliente pide y paga
 * aquí, se lleva un ticket con el número del pedido y espera su plato. Por eso
 * cada venta crea un pedido (para comer aquí o para llevar) que la cocina ve,
 * y lo cobra en el mismo acto (`Pedidos::venderEnMostrador`).
 */
class PosController extends Controller
{
    /** Cuántos clientes trae la lista del mostrador; el resto se busca. */
    private const CLIENTES_EN_LISTA = 50;

    public function index(Request $request): View
    {
        $sesion = Cajas::sesionDe(Auth::user());

        $clientes = Cliente::activos()->orderBy('nombre')->limit(self::CLIENTES_EN_LISTA)->get();

        // Si se llegó con un cliente elegido (?cliente=), tiene que estar en la
        // lista aunque por orden alfabético quede fuera.
        $pedido = (int) $request->query('cliente');

        if ($pedido && ! $clientes->contains('id', $pedido) && ($cliente = Cliente::activos()->find($pedido))) {
            $clientes->push($cliente);
        }

        // Sin caja abierta, el mostrador ofrece abrirla ahí mismo: las cajas
        // libres y lo que dejó en el cajón el último turno de cada una.
        $cajasLibres = $sesion ? collect() : Caja::activas()->whereDoesntHave('sesionAbierta')->orderBy('nombre')->get();

        return view('pos.index', [
            'title' => 'Punto de venta',
            'sesion' => $sesion,
            'cajasLibres' => $cajasLibres,
            'fondos' => $cajasLibres
                ->mapWithKeys(fn (Caja $c) => [$c->id => Cajas::fondoDejadoEn($c)])
                ->reject(fn (?float $f) => $f === null),
            'metodosPago' => MetodoPago::activos()->orderBy('id')->get(),
            // Con el conteo al lado: un desplegable esconde que «Platos de
            // fondo» tiene treinta y «Postres» dos.
            'categorias' => Categoria::activas()
                ->withCount(['productos' => fn ($q) => $q->activos()])
                ->having('productos_count', '>', 0)
                ->orderBy('nombre')
                ->get(['id', 'nombre']),
            'clientesEnLista' => self::CLIENTES_EN_LISTA,
            'hayMasClientes' => Cliente::activos()->count() > self::CLIENTES_EN_LISTA,
            'clientes' => $clientes
                ->map(fn (Cliente $c) => [
                    'id' => $c->id,
                    'nombre' => $c->nombre,
                    'etiqueta' => $c->etiqueta,
                    'juridica' => $c->llevaFactura(),
                ]),
            'tasaImpuesto' => Config::tasaImpuesto(),
            'impuestoIncluido' => Config::preciosIncluyenImpuesto(),
            'moneda' => Config::moneda(),
            'descuentoMaximo' => (float) Config::get('descuento_max_cajero', '0'),
            'puedeDescontar' => Auth::user()->tienePermiso('ventas.descuento'),
            'clienteGenerico' => Config::get('cliente_generico_nombre', 'Cliente varios'),
            // El mostrador necesita saber QUÉ métodos se cobran por QR para
            // mostrar el código en vez de un campo de referencia.
            'metodosQr' => MetodoPago::activos()->where('codigo', 'QR')->pluck('id')->values(),
            // Los QR que este cajero cobró en su turno y no terminaron en venta
            // (la venta falló, se recargó la página): se ofrecen para usarlos.
            'qrSinVenta' => $sesion
                ? $sesion->cobrosQrSinVenta()->where('usuario_id', Auth::id())->get()
                    ->map(fn ($c) => [
                        'id' => $c->id, 'estado' => $c->estado, 'monto' => (float) $c->monto,
                        'pagado' => true, 'imagen' => false, 'payload' => null,
                        'referencia' => $c->referencia_bancaria,
                    ])->values()
                : collect(),
            'huboError' => session()->has('error') || session()->has('errors'),
            // El camino de corrección: los pedidos cuya venta se anuló. El
            // cliente ya tiene su ticket con el número y la cocina ya los
            // prepara; se cobran de nuevo —con el mismo número— o se cancelan.
            'porCobrar' => Pedido::abiertos()
                ->with(['ultimaVenta.cliente:id,nombre', 'detalle'])
                ->orderBy('fecha_apertura')
                ->get(),
            // Recién cancelado un pedido que la cocina ya tenía en papel: se
            // ofrece imprimir el aviso (ver `PedidoController::cancelar`).
            'avisoCocina' => session('comanda_pendiente') ? Pedido::find(session('comanda_pendiente')) : null,
            'qrSimulado' => CobrosQr::estaSimulado(),
            'qrSegundosConsulta' => (int) config('qr.segundos_consulta', 4),
        ]);
    }

    /** Búsqueda incremental del mostrador: nombre o código interno. */
    public function buscar(Request $request): JsonResponse
    {
        $texto = $request->string('q')->toString();
        $categoria = $request->integer('categoria') ?: null;

        $productos = Producto::activos()
            ->buscar($texto)
            ->when($categoria, fn ($q, $id) => $q->where('categoria_id', $id))
            ->orderBy('nombre')
            ->limit(24)
            ->get();

        return response()->json(
            $productos->map(fn (Producto $p) => [
                'id' => $p->id,
                'codigo' => $p->codigo,
                'nombre' => $p->nombre,
                'precio' => (float) $p->precio_venta,
                'precio_estante' => $p->precio_estante,
                'afecto' => (bool) $p->afecto_impuesto,
                'imagen' => $p->imagen_url,
                // Tiñe la pieza con la inicial mientras no tenga foto.
                'categoria_id' => $p->categoria_id,
            ])
        );
    }

    /**
     * Precios ACTUALES de lo que ya está en el carrito.
     *
     * El carrito vive en Alpine y guarda el precio del momento en que se
     * agregó cada línea; si alguien edita el menú mientras el cajero
     * todavía no cobra, la pantalla queda mostrando un total y un vuelto
     * viejos aunque el servidor siempre cobre el precio del menú actual.
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
                // El régimen de impuesto también puede cambiar mientras el
                // carrito está armado, y en modo incluido no mueve el precio.
                'afecto' => (bool) $p->afecto_impuesto,
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
            'descuento' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            // El total que vio el cajero: si no cuadra con el que calcula el
            // servidor, la venta no se registra.
            'total_esperado' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'observacion' => ['nullable', 'string', 'max:255'],
            // Comer aquí o para llevar: lo dice el ticket y la cocina. Si no
            // llega —una pantalla vieja—, para comer aquí, que es lo habitual.
            'tipo' => ['nullable', Rule::in(Pedido::TIPOS)],
            // A nombre de quién, para llamarlo cuando esté (opcional).
            'nombre_cliente' => ['nullable', 'string', 'max:80'],

            'lineas' => ['required', 'array', 'min:1'],
            // Un ítem, una línea: el mostrador ya las agrupa, y es lo que
            // exige el índice único de `venta_detalle`.
            'lineas.*.producto_id' => ['required', 'distinct', Rule::exists('productos', 'id')],
            // Tres decimales como máximo: los que guarda la base.
            'lineas.*.cantidad' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            // Lo que lee el cocinero: «sin cebolla», «término medio».
            'lineas.*.nota' => ['nullable', 'string', 'max:255'],
            // El precio SIEMPRE sale del menú, nunca de aquí: no se valida ni
            // se usa, aunque el formulario lo mande.

            'pagos' => ['required', 'array', 'min:1'],
            'pagos.*.metodo_pago_id' => ['required', Rule::exists('metodos_pago', 'id')->where('activo', 1)],
            // Sin importe significa «el resto»: lo calcula el servidor.
            'pagos.*.monto' => ['nullable', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'pagos.*.monto_recibido' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'pagos.*.referencia' => ['nullable', 'string', 'max:60'],
            // El pago por QR viene respaldado por su cobro. Que esté pagado,
            // sea de este turno, libre y por el importe exacto lo controla
            // `Ventas::registrar`, dentro de la misma transacción de la venta.
            'pagos.*.cobro_qr_id' => ['nullable', 'integer', 'exists:cobros_qr,id'],
        ], [
            'lineas.required' => 'La venta no tiene nada que cobrar.',
            'pagos.required' => 'Falta indicar cómo se pagó.',
        ]);

        $cliente = isset($datos['cliente_id']) ? Cliente::find($datos['cliente_id']) : null;
        $descuento = (float) ($datos['descuento'] ?? 0);

        try {
            // El pedido y su cobro, juntos: o queda cobrado con su venta y su
            // comprobante, o no queda nada.
            $venta = Pedidos::venderEnMostrador(
                sesion: $sesion,
                usuario: Auth::user(),
                lineas: array_map(fn (array $l) => [
                    'producto_id' => (int) $l['producto_id'],
                    'cantidad' => $l['cantidad'],
                    'nota' => $l['nota'] ?? null,
                ], $datos['lineas']),
                pagos: $datos['pagos'],
                tipo: $datos['tipo'] ?? Pedido::LOCAL,
                cliente: $cliente,
                nombreCliente: $datos['nombre_cliente'] ?? null,
                descuento: $descuento,
                observacion: $datos['observacion'] ?? null,
                totalEsperado: isset($datos['total_esperado']) ? (float) $datos['total_esperado'] : null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', Mensaje::de($e))->withInput();
        } catch (Throwable $e) {
            // Los triggers de la base avisan con SIGNAL: se muestra su mensaje.
            return back()->with('error', $this->mensajeDeBase($e))->withInput();
        }

        $pedido = $venta->pedido;

        return redirect()->route('ventas.show', $venta)
            ->with('exito', ($pedido ? "{$pedido->etiqueta}: cobrado. " : 'Venta registrada. ')
                .'Comprobante '.$venta->comprobante?->numero_completo.'.');
    }

    /** Extrae el texto del SIGNAL de MySQL, que llega envuelto en ruido. */
    private function mensajeDeBase(Throwable $e): string
    {
        return Mensaje::deLaBase($e, 'No se pudo registrar la venta. Revisa los importes e inténtalo de nuevo.');
    }
}
