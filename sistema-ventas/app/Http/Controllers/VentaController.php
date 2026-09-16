<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OrdenaTablas;
use App\Models\Cliente;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Comprobantes;
use App\Services\Ventas;
use Carbon\Carbon;
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

        $ventas = $this->aplicarOrden(Venta::with([
            'cliente:id,nombre',
            'usuario:id,usuario',
            'comprobante:id,venta_id,numero_completo,estado',
        ])
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
            // Rango explícito y no `whereDate`: envolver la columna en DATE()
            // anula el índice de fecha y obliga a recorrer la tabla entera.
            ->when($filtros['desde'], fn ($q, $d) => $q->where('fecha', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filtros['hasta'], fn ($q, $h) => $q->where('fecha', '<=', Carbon::parse($h)->endOfDay())),
            $orden
        )
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
            'detalle.producto:id,codigo,unidad_medida_id',
            'detalle.producto.unidadMedida:id,codigo',
            'pagos.metodoPago:id,codigo,nombre',
            'comprobantes.serie.tipo',
            'devoluciones',
        ]);

        $comprobante = $venta->comprobante;

        return view('ventas.show', [
            'title' => 'Venta #'.$venta->id,
            'trail' => ['Ventas' => route('ventas.index')],
            'venta' => $venta,
            'puedeSustituir' => $comprobante && Comprobantes::puedeSustituirse($comprobante),
            'bloqueoSustitucion' => $comprobante ? Comprobantes::motivoBloqueo($comprobante) : null,
            'venceSustitucion' => Comprobantes::venceEl($venta),
            // Para pasar de recibo a factura hay que asignar una persona jurídica.
            'clientes' => Cliente::activos()->orderBy('nombre')->get()
                ->map(fn (Cliente $c) => [
                    'id' => $c->id,
                    'etiqueta' => $c->etiqueta,
                    'juridica' => $c->llevaFactura(),
                ]),
        ]);
    }

    public function anular(Request $request, Venta $venta): RedirectResponse
    {
        $datos = $request->validate([
            'motivo_anulacion' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'motivo_anulacion.required' => 'La anulación necesita un motivo: queda registrado con tu nombre.',
            'motivo_anulacion.min' => 'Explica el motivo con un poco más de detalle.',
        ], [
            'motivo_anulacion' => 'motivo',
        ]);

        try {
            Ventas::anular($venta, Auth::user(), $datos['motivo_anulacion']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            // El procedimiento almacenado también puede avisar con SIGNAL
            // (p. ej. si dos personas anulan la misma venta a la vez).
            return back()->with('error', $this->mensajeDeBase($e));
        }

        return back()->with('exito', 'Venta anulada. El stock volvió al inventario y el comprobante quedó anulado.');
    }

    /** Extrae el texto del SIGNAL de MySQL, que llega envuelto en ruido. */
    private function mensajeDeBase(Throwable $e): string
    {
        if (preg_match('/SQLSTATE\[45000\].*?:\s*\d+\s+(.+?)(?: \(Connection:|$)/s', $e->getMessage(), $m)) {
            return trim($m[1]);
        }

        report($e);

        return 'No se pudo anular la venta. Inténtalo de nuevo.';
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, float|int>
     */
    private function resumen(array $filtros): array
    {
        // Una sola pasada por el mismo rango, con el índice de fecha: antes
        // eran cuatro recorridos de la tabla entera para cuatro cifras.
        $fila = Venta::query()
            ->when($filtros['usuario'], fn ($q, $id) => $q->where('usuario_id', $id))
            ->when($filtros['desde'], fn ($q, $d) => $q->where('fecha', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filtros['hasta'], fn ($q, $h) => $q->where('fecha', '<=', Carbon::parse($h)->endOfDay()))
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
