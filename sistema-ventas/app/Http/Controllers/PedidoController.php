<?php

namespace App\Http\Controllers;

use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\Producto;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\Comandas;
use App\Services\Pedidos;
use App\Support\Config;
use App\Support\Mensaje;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Volver a cobrar: el camino de corrección.
 *
 * En el local todo se toma y se cobra en el mostrador, en el mismo acto
 * (`PosController`). Un pedido solo queda sin cobrar cuando se ANULA su venta
 * —se cobró con la forma de pago equivocada, por ejemplo—: el cliente ya tiene
 * su ticket con el número y la cocina ya lo está preparando, así que el pedido
 * vuelve a quedar abierto con su número y sus platos (`Pedidos::reabrirTrasAnular`).
 * Desde aquí se cobra de nuevo —con su mismo número— o se cancela.
 *
 * No es el módulo de mesas ni el de cuentas abiertas, que se retiraron: aquí
 * no se abre un pedido ni se le agregan platos.
 */
class PedidoController extends Controller
{
    /**
     * La pantalla de cobro: el pedido ya fusionado, sus platos con su estado en
     * la cocina, y las formas de pago.
     */
    public function cobrarForm(Pedido $pedido): View|RedirectResponse
    {
        if (! $pedido->estaAbierto()) {
            return redirect()->route('pos.index')
                ->with('error', $pedido->estado === Pedido::CERRADO
                    ? "El {$pedido->numero_visible} ya se cobró."
                    : "El {$pedido->numero_visible} está cancelado.");
        }

        $sesion = Cajas::sesionDe(Auth::user());

        if (! $sesion) {
            return redirect()->route('caja.index')
                ->with('error', 'Abre tu caja antes de cobrar un pedido.');
        }

        $pedido->load(['detalle.producto:id,nombre', 'usuario:id,usuario']);
        $usuario = Auth::user();
        $empezados = $pedido->platosEmpezados();

        try {
            $lineas = Pedidos::lineasAgrupadas($pedido);
            $totales = Pedidos::totalesDe($pedido);
        } catch (RuntimeException $e) {
            // Nada que cobrar (todo cancelado): se ofrece igual la pantalla,
            // para cancelar el pedido, pero sin formas de pago.
            $lineas = [];
            $totales = ['subtotal' => 0.0, 'impuesto' => 0.0, 'total' => 0.0];
            session()->now('aviso', Mensaje::de($e));
        }

        $productos = Producto::whereIn('id', array_column($lineas, 'producto_id'))->get()->keyBy('id');

        return view('pedidos.cobrar', [
            'title' => 'Cobrar · '.$pedido->etiqueta,
            'trail' => ['Punto de venta' => route('pos.index')],
            'pedido' => $pedido,
            // El cliente de la venta que se anuló: el comprobante nuevo sale a su nombre.
            'cliente' => Pedidos::clienteDelCobroAnulado($pedido),
            'sesion' => $sesion,
            // Cada agrupado con el nombre del plato y de cuántas tandas viene:
            // el cajero tiene que poder explicarle el pedido al cliente.
            'lineas' => array_map(fn (array $l) => $l + [
                'nombre' => $productos[$l['producto_id']]?->nombre ?? '—',
                'tandas' => $pedido->detalle
                    ->where('producto_id', $l['producto_id'])
                    ->where('estado_cocina', '<>', PedidoDetalle::CANCELADO)
                    ->count(),
            ], $lineas),
            'totales' => $totales,
            'metodosPago' => MetodoPago::activos()->orderBy('id')->get(),
            'moneda' => Config::moneda(),
            'descuentoMaximo' => (float) Config::get('descuento_max_cajero', '0'),
            'puedeDescontar' => $usuario->tienePermiso('ventas.descuento'),
            'exigeReferencia' => MetodoPago::exigeReferencia(),
            'metodosQr' => MetodoPago::activos()->where('codigo', 'QR')->pluck('id')->values(),
            'qrSimulado' => CobrosQr::estaSimulado(),
            'qrSegundosConsulta' => (int) config('qr.segundos_consulta', 4),
            'impuestoIncluido' => Config::preciosIncluyenImpuesto(),
            // La misma regla que `Pedidos::cancelar` (C1), que es la que manda:
            // aquí solo decide si se ofrece el botón.
            'platosEmpezados' => $empezados,
            'puedeCancelar' => $usuario->tienePermiso('pedidos.registrar')
                && ($empezados === 0 || $usuario->tienePermiso('ventas.anular')),
            'puedeCancelarPlatos' => $usuario->tienePermiso('pedidos.registrar'),
            // Un plato que la cocina ya empezó, solo quien puede anular (C1).
            'puedeCancelarEmpezados' => $usuario->tienePermiso('pedidos.registrar') && $usuario->tienePermiso('ventas.anular'),
            // Un plato que ya estaba en papel en la cocina y se canceló: hay
            // que avisarle en papel también.
            'avisoPendiente' => Comandas::pendiente($pedido)['canceladas']->isNotEmpty(),
        ]);
    }

    public function cobrar(Request $request, Pedido $pedido): RedirectResponse
    {
        $sesion = Cajas::sesionDe(Auth::user());

        if (! $sesion) {
            return redirect()->route('caja.index')
                ->with('error', 'Abre tu caja antes de cobrar un pedido.');
        }

        // Las mismas reglas que el mostrador para los pagos: los importes y
        // las líneas los pone el servidor a partir del pedido, así que aquí no
        // se valida nada del detalle.
        $datos = $request->validate([
            'descuento' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'total_esperado' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'observacion' => ['nullable', 'string', 'max:255'],

            'pagos' => ['required', 'array', 'min:1'],
            'pagos.*.metodo_pago_id' => ['required', Rule::exists('metodos_pago', 'id')->where('activo', 1)],
            // Sin importe significa «el resto»: lo calcula el servidor.
            'pagos.*.monto' => ['nullable', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'pagos.*.monto_recibido' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'pagos.*.referencia' => ['nullable', 'string', 'max:60'],
            // El pago por QR viene respaldado por su cobro, generado con las
            // mismas rutas del mostrador. Que esté pagado, sea de este turno,
            // libre y por el importe exacto lo controla `Ventas::registrar`.
            'pagos.*.cobro_qr_id' => ['nullable', 'integer', 'exists:cobros_qr,id'],
        ], [
            'pagos.required' => 'Falta indicar cómo se pagó.',
        ]);

        $descuento = (float) ($datos['descuento'] ?? 0);

        try {
            $venta = Pedidos::cobrar(
                pedido: $pedido,
                sesion: $sesion,
                usuario: Auth::user(),
                pagos: $datos['pagos'],
                descuento: $descuento,
                observacion: $datos['observacion'] ?? null,
                totalEsperado: isset($datos['total_esperado']) ? (float) $datos['total_esperado'] : null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', Mensaje::de($e))->withInput();
        } catch (Throwable $e) {
            return back()->with('error', Mensaje::deLaBase($e, 'No se pudo cobrar el pedido. Revisa los importes e inténtalo de nuevo.'))->withInput();
        }

        return redirect()->route('ventas.show', $venta)
            ->with('exito', "{$pedido->etiqueta}: cobrado. Comprobante ".$venta->comprobante?->numero_completo.'.');
    }

    /**
     * Cancelar un plato del pedido con el cobro anulado: no se cobra, pero queda.
     *
     * Solo mientras el pedido espera volver a cobrarse y la cocina no lo terminó (las
     * reglas son las de `Pedidos::actualizarEstadoLinea`): el cliente ya no lo
     * quiere al rehacer el cobro. Si la cocina ya lo empezó, lo cancela un
     * administrador y con su motivo.
     */
    public function cancelarLinea(Request $request, Pedido $pedido, PedidoDetalle $linea): RedirectResponse
    {
        if ($linea->pedido_id !== $pedido->id) {
            abort(404);
        }

        $datos = $request->validate([
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            Pedidos::actualizarEstadoLinea($linea, PedidoDetalle::CANCELADO, Auth::user(), $datos['motivo'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', Mensaje::de($e));
        }

        return back()->with('exito', "«{$linea->descripcion}» cancelado: no se le cobra al cliente.");
    }

    /** Cancela el pedido con el cobro anulado, con su motivo (y con C1: ver `Pedidos::cancelar`). */
    public function cancelar(Request $request, Pedido $pedido): RedirectResponse
    {
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:4', 'max:255'],
        ], [
            'motivo.required' => 'Explica por qué se cancela el pedido.',
        ]);

        try {
            Pedidos::cancelar($pedido, Auth::user(), $datos['motivo']);
        } catch (RuntimeException $e) {
            return back()->with('error', Mensaje::de($e));
        }

        // Si la cocina ya lo tenía en papel, hay que avisarle también en papel:
        // el punto de venta ofrece imprimir el aviso de cancelación.
        $aviso = Comandas::hayPendiente($pedido->fresh()) ? $pedido->id : null;

        return redirect()->route('pos.index')
            ->with('exito', "{$pedido->numero_visible} cancelado.")
            ->with('comanda_pendiente', $aviso);
    }
}
