<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OrdenaTablas;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\SerieComprobante;
use App\Services\Comprobantes;
use App\Support\Config;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class ComprobanteController extends Controller
{
    use OrdenaTablas;

    /**
     * El documento listo para imprimir, en ticket de 80 mm o en A4.
     * Es una vista propia, sin barra lateral: lo que sale por la impresora.
     */
    public function imprimir(Request $request, Comprobante $comprobante): View
    {
        abort_if(
            VentaController::soloPropias() && $comprobante->venta?->usuario_id !== Auth::id(),
            403,
            'Ese comprobante es de una venta de otra persona.',
        );

        $formato = $request->string('formato')->toString();

        if (! in_array($formato, ['ticket', 'a4'], true)) {
            $formato = 'ticket';
        }

        $comprobante->load([
            'serie.tipo',
            'venta.detalle.producto.unidadMedida:id,codigo',
            'venta.pagos.metodoPago:id,codigo,nombre',
            'venta.usuario:id,usuario',
        ]);

        return view('comprobantes.imprimir', [
            'comprobante' => $comprobante,
            'formato' => $formato,
            // Con `?imprimir=1` la hoja abre el diálogo de impresión sola. Lo
            // pide el botón de la venta recién cobrada, donde la intención es
            // imprimir; abrir el documento desde el listado NO lo pide, porque
            // ahí la intención es mirarlo.
            'autoImprimir' => $request->boolean('imprimir'),
            'negocio' => [
                'nombre' => Config::get('negocio_nombre', config('app.name')),
                'documento' => Config::get('negocio_documento'),
                'direccion' => Config::get('negocio_direccion'),
                'telefono' => Config::get('negocio_telefono'),
            ],
        ]);
    }

    public function index(Request $request): View
    {
        $filtros = [
            'buscar' => $request->string('buscar')->toString(),
            'estado' => $request->string('estado')->toString(),
            'serie' => $request->integer('serie') ?: null,
        ];

        $orden = $this->orden($request, [
            'numero' => 'numero_completo',
            'tipo' => SerieComprobante::select('serie')->whereColumn('series_comprobante.id', 'comprobantes.serie_id'),
            'fecha' => 'fecha_emision',
            'cliente' => 'cliente_nombre',
            'estado' => 'estado',
            'total' => 'total',
        ], 'fecha', 'desc');

        $comprobantes = $this->aplicarOrden(Comprobante::with(['serie.tipo', 'venta:id,estado'])
            ->when($filtros['buscar'] !== '', function ($q) use ($filtros) {
                $texto = $filtros['buscar'];
                $q->where(fn ($sub) => $sub->where('numero_completo', 'like', "%{$texto}%")
                    ->orWhere('cliente_nombre', 'like', "%{$texto}%")
                    ->orWhere('cliente_documento', 'like', "%{$texto}%"));
            })
            ->when($filtros['estado'], fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['serie'], fn ($q, $id) => $q->where('serie_id', $id))
            ->when(VentaController::soloPropias(), fn ($q) => $q->whereHas('venta', fn ($v) => $v->where('usuario_id', Auth::id()))),
            $orden
        )
            ->paginate(15)
            ->withQueryString();

        return view('comprobantes.index', [
            'title' => 'Comprobantes emitidos',
            'comprobantes' => $comprobantes,
            'filtros' => $filtros,
            'series' => SerieComprobante::with('tipo')->get()
                ->mapWithKeys(fn ($s) => [$s->id => $s->tipo?->nombre.' — '.$s->serie]),
            'estados' => ['EMITIDO' => 'Emitido', 'ANULADO' => 'Anulado', 'SUSTITUIDO' => 'Sustituido'],
        ]);
    }

    /**
     * Reemplaza el documento de una venta ya cobrada (HU-42). El caso típico:
     * se entregó recibo y el cliente vuelve pidiendo factura.
     */
    public function sustituir(Request $request, Comprobante $comprobante): RedirectResponse
    {
        // Sin facturación a la vista no hay a qué sustituir: el botón no se
        // muestra, y un envío directo tampoco pasa.
        abort_unless(Config::facturacionVisible(), 404);

        $datos = $request->validate([
            'cliente_id' => ['nullable', Rule::exists('clientes', 'id')->where('activo', 1)],
            'motivo' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'motivo.required' => 'La sustitución necesita un motivo: queda en el documento nuevo.',
            'motivo.min' => 'Explica el motivo con un poco más de detalle.',
        ], [
            'cliente_id' => 'cliente',
            'motivo' => 'motivo',
        ]);

        $cliente = isset($datos['cliente_id']) ? Cliente::find($datos['cliente_id']) : null;

        try {
            $nuevo = Comprobantes::sustituir($comprobante, Auth::user(), $cliente, $datos['motivo']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        } catch (Throwable $e) {
            return back()->with('error', $this->mensajeDeBase($e))->withInput();
        }

        return redirect()->route('ventas.show', $comprobante->venta_id)
            ->with('exito', "Se emitió {$nuevo->nombre_tipo} {$nuevo->numero_completo} en reemplazo de ".
                "{$comprobante->numero_completo}, que queda sustituido.");
    }

    /** Los triggers avisan con SIGNAL; se muestra su texto y no el ruido de PDO. */
    private function mensajeDeBase(Throwable $e): string
    {
        if (preg_match('/SQLSTATE\[45000\].*?:\s*\d+\s+(.+?)(?: \(Connection:|$)/s', $e->getMessage(), $m)) {
            return trim($m[1]);
        }

        report($e);

        return 'No se pudo sustituir el documento. Revisa los datos del cliente e inténtalo de nuevo.';
    }
}
