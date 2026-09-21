<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OrdenaTablas;
use App\Models\Cliente;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Comprobantes;
use App\Services\Ventas;
use App\Support\Config;
use App\Support\Mensaje;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class VentaController extends Controller
{
    use OrdenaTablas;

    public function index(Request $request): View
    {
        $filtros = [
            'buscar' => $request->string('buscar')->toString(),
            'estado' => $request->string('estado')->toString(),
            // Sin permiso de reportes, solo las propias: el listado general y
            // sus totales son información del negocio, no del mostrador.
            'usuario' => self::soloPropias() ? Auth::id() : ($request->integer('usuario') ?: null),
            'desde' => $request->date('desde')?->format('Y-m-d'),
            'hasta' => $request->date('hasta')?->format('Y-m-d'),
        ];

        $orden = $this->orden($request, [
            'fecha' => 'fecha',
            'cliente' => Cliente::select('nombre')->whereColumn('clientes.id', 'ventas.cliente_id'),
            'cajero' => Usuario::select('usuario')->whereColumn('usuarios.id', 'ventas.usuario_id'),
            'estado' => 'estado',
            'total' => 'total',
        ], 'fecha', 'desc');

        $ventas = $this->aplicarOrden($this->filtradas($filtros)->with([
            'cliente:id,nombre',
            'usuario:id,usuario',
            'comprobante:id,venta_id,numero_completo,estado',
            // La columna «Pedido»: de qué pedido salió la venta, que es como
            // se la busca cuando alguien viene a reclamar con su ticket.
            // `numero_dia` y `jornada` son lo que se imprime en la columna: el
            // número que se cantó, y su jornada, porque el listado mezcla
            // fechas y el contador vuelve a 1 en cada jornada.
            'pedido:id,tipo,numero_dia,jornada,fecha_apertura',
        ]), $orden)
            ->paginate(15)
            ->withQueryString();

        return view('ventas.index', [
            'title' => self::soloPropias() ? 'Mis ventas' : 'Ventas',
            'soloPropias' => self::soloPropias(),
            'ventas' => $ventas,
            'filtros' => $filtros,
            'estados' => Venta::ESTADOS,
            'cajeros' => Usuario::whereHas('ventas')->orderBy('usuario')->pluck('usuario', 'id'),
            'resumen' => $this->resumen($filtros),
        ]);
    }

    /** Quien no ve reportes solo ve sus propias ventas y sus comprobantes. */
    public static function soloPropias(): bool
    {
        return ! Auth::user()?->tienePermiso('reportes.ver');
    }

    public function show(Venta $venta): View
    {
        abort_if(self::soloPropias() && $venta->usuario_id !== Auth::id(), 403, 'Esa venta la registró otra persona.');

        $venta->load([
            'cliente',
            'usuario:id,usuario,empleado_id',
            'usuario.empleado:id,nombre_completo',
            'anuladaPor:id,usuario',
            'sesionCaja.caja:id,nombre',
            'detalle.producto:id,codigo',
            'pagos.metodoPago:id,codigo,nombre',
            'comprobantes.serie.tipo',
            // De qué pedido era, también si se anuló, y con qué venta se volvió a cobrar.
            'pedido.venta:id,pedido_id',
            'pedido.ultimaVenta.cliente:id,nombre',
        ]);

        $comprobante = $venta->comprobante;

        // Sustituir es para cambiar un recibo por una factura: sin facturación, no se ofrece.
        $puedeSustituir = Config::facturacionVisible() && $comprobante && Comprobantes::puedeSustituirse($comprobante);

        return view('ventas.show', [
            'title' => 'Venta #'.$venta->id,
            'trail' => ['Ventas' => route('ventas.index')],
            'venta' => $venta,
            'puedeSustituir' => $puedeSustituir,
            'bloqueoSustitucion' => $comprobante ? Comprobantes::motivoBloqueo($comprobante) : null,
            'venceSustitucion' => Comprobantes::venceEl($venta),
            // Para pasar de recibo a factura hay que asignar una persona
            // jurídica. Solo si se puede sustituir: si no, era cargar todos los
            // clientes en cada ficha para nada.
            'clientes' => ! $puedeSustituir ? collect() : Cliente::activos()->orderBy('nombre')->get()
                ->map(fn (Cliente $c) => [
                    'id' => $c->id,
                    'etiqueta' => $c->etiqueta,
                    'juridica' => $c->llevaFactura(),
                ]),
        ]);
    }

    public function anular(Request $request, Venta $venta): RedirectResponse
    {
        // Un rol hecho a medida puede tener `ventas.anular` sin ver todo: anula lo suyo.
        abort_if(self::soloPropias() && $venta->usuario_id !== Auth::id(), 403, 'Esa venta la registró otra persona.');

        $datos = $request->validate([
            'motivo_anulacion' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'motivo_anulacion.required' => 'La anulación necesita un motivo: queda registrado con tu nombre.',
            'motivo_anulacion.min' => 'Explica el motivo con un poco más de detalle.',
        ], [
            'motivo_anulacion' => 'motivo',
        ]);

        $pedido = $venta->pedido;

        try {
            Ventas::anular($venta, Auth::user(), $datos['motivo_anulacion']);
        } catch (RuntimeException $e) {
            return back()->with('error', Mensaje::de($e));
        } catch (Throwable $e) {
            // El procedimiento almacenado también puede avisar con SIGNAL
            // (p. ej. si dos personas anulan la misma venta a la vez).
            return back()->with('error', $this->mensajeDeBase($e));
        }

        $mensaje = 'Venta anulada: el comprobante quedó anulado, conservando su correlativo.';

        if ($pedido?->refresh()->estaAbierto()) {
            $mensaje .= " El {$pedido->numero_visible} queda para volver a cobrar, con su número y sus platos (la cocina los sigue viendo): "
                .'cóbralo de nuevo desde el punto de venta, en «Volver a cobrar».';
        }

        return back()->with('exito', $mensaje);
    }

    /** Extrae el texto del SIGNAL de MySQL, que llega envuelto en ruido. */
    private function mensajeDeBase(Throwable $e): string
    {
        return Mensaje::deLaBase($e, 'No se pudo anular la venta. Inténtalo de nuevo.');
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, float|int>
     */
    /**
     * Las ventas que muestra el listado. El listado y sus totales salen de aquí
     * los dos: antes los totales ignoraban el estado y la búsqueda, y con el
     * filtro «Anulada» la tabla mostraba 2 ventas y la tarjeta sumaba 10.
     */
    private function filtradas(array $filtros): Builder
    {
        return Venta::query()
            ->when($filtros['buscar'] !== '', function ($q) use ($filtros) {
                $texto = $filtros['buscar'];
                $q->where(function ($sub) use ($texto) {
                    $sub->whereHas('comprobantes', fn ($c) => $c->where('numero_completo', 'like', "%{$texto}%"))
                        ->orWhereHas('cliente', fn ($c) => $c->where('nombre', 'like', "%{$texto}%"))
                        ->orWhere('id', ltrim($texto, '#'));
                });
            })
            ->when($filtros['estado'], fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['usuario'], fn ($q, $id) => $q->where('usuario_id', $id))
            // Por jornada, como los reportes: el «detalle por jornada» enlaza
            // aquí, y la venta de la 01:30 es de la noche anterior. Rango
            // explícito y no `whereDate`: envolver la columna en una función
            // anula el índice de fecha y obliga a recorrer la tabla entera.
            ->when($filtros['desde'], fn ($q, $d) => $q->where('fecha', '>=', Config::momentosDeJornadas($d, $d)[0]))
            ->when($filtros['hasta'], fn ($q, $h) => $q->where('fecha', '<=', Config::momentosDeJornadas($h, $h)[1]));
    }

    private function resumen(array $filtros): array
    {
        // Una sola pasada, con los mismos filtros que la tabla.
        $fila = $this->filtradas($filtros)
            ->selectRaw("SUM(estado <> 'ANULADA') AS operaciones")
            ->selectRaw("COALESCE(SUM(IF(estado <> 'ANULADA', total, 0)), 0) AS vendido")
            ->selectRaw("COALESCE(SUM(IF(estado <> 'ANULADA', impuesto, 0)), 0) AS impuesto")
            ->selectRaw("SUM(estado = 'ANULADA') AS anuladas")
            ->first();

        return [
            'operaciones' => (int) $fila->operaciones,
            'vendido' => (float) $fila->vendido,
            'impuesto' => (float) $fila->impuesto,
            'anuladas' => (int) $fila->anuladas,
        ];
    }
}
