<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OrdenaTablas;
use App\Models\Devolucion;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Devoluciones;
use App\Support\Config;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class DevolucionController extends Controller
{
    use OrdenaTablas;

    public function index(Request $request): View
    {
        $filtros = [
            'buscar' => $request->string('buscar')->toString(),
            'tipo' => $request->string('tipo')->toString(),
            'desde' => $request->date('desde')?->format('Y-m-d'),
            'hasta' => $request->date('hasta')?->format('Y-m-d'),
        ];

        $orden = $this->orden($request, [
            'fecha' => 'fecha',
            'motivo' => 'motivo',
            'tipo' => 'tipo',
            'registro' => Usuario::select('usuario')->whereColumn('usuarios.id', 'devoluciones.usuario_id'),
            'devuelto' => 'total',
        ], 'fecha', 'desc');

        $devoluciones = $this->aplicarOrden(Devolucion::with([
            'venta:id,cliente_id,estado',
            'venta.cliente:id,nombre',
            'venta.comprobante:id,venta_id,numero_completo',
            'usuario:id,usuario',
        ])
            ->withCount('detalle')
            ->when($filtros['buscar'] !== '', function ($q) use ($filtros) {
                $texto = $filtros['buscar'];
                $q->where(function ($sub) use ($texto) {
                    $sub->where('motivo', 'like', "%{$texto}%")
                        ->orWhereHas('venta.comprobantes', fn ($c) => $c->where('numero_completo', 'like', "%{$texto}%"))
                        ->orWhereHas('venta.cliente', fn ($c) => $c->where('nombre', 'like', "%{$texto}%"));
                });
            })
            ->when($filtros['tipo'], fn ($q, $tipo) => $q->where('tipo', $tipo))
            ->when($filtros['desde'], fn ($q, $d) => $q->whereDate('fecha', '>=', $d))
            ->when($filtros['hasta'], fn ($q, $h) => $q->whereDate('fecha', '<=', $h)),
            $orden
        )
            ->paginate(15)
            ->withQueryString();

        return view('devoluciones.index', [
            'title' => 'Devoluciones',
            'devoluciones' => $devoluciones,
            'filtros' => $filtros,
            'resumen' => $this->resumen($filtros),
        ]);
    }

    /** Formulario de devolución, montado sobre las líneas de una venta. */
    public function create(Venta $venta): View|RedirectResponse
    {
        if (! $venta->dentroDelPlazoDeDevolucion()) {
            return redirect()->route('ventas.show', $venta)->with('error', sprintf(
                'Pasó el plazo para devolver: se aceptan devoluciones hasta %d día(s) después de la venta.',
                Venta::diasParaDevolver(),
            ));
        }

        $venta->load([
            'cliente:id,nombre',
            'comprobante:id,venta_id,numero_completo',
            'detalle.producto:id,codigo,unidad_medida_id',
            'detalle.producto.unidadMedida:id,codigo,permite_decimal',
        ]);

        $turnos = $this->turnosPosibles();

        return view('devoluciones.create', [
            'title' => 'Devolución de la venta #'.$venta->id,
            'trail' => ['Devoluciones' => route('devoluciones.index')],
            'venta' => $venta,
            'sesion' => $turnos->count() === 1 ? $turnos->first() : null,
            'turnos' => $turnos,
            'proporcionEfectivo' => Devoluciones::proporcionEnEfectivo($venta),
        ]);
    }

    public function store(Request $request, Venta $venta): RedirectResponse
    {
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:255'],
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.venta_detalle_id' => [
                'required',
                Rule::exists('venta_detalle', 'id')->where('venta_id', $venta->id),
            ],
            'lineas.*.cantidad' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.reingresa_stock' => ['boolean'],
            'reembolso' => ['nullable', Rule::in([Devolucion::EFECTIVO, Devolucion::MISMO_MEDIO])],
            'sesion_caja_id' => ['nullable', 'integer'],
        ], [
            'motivo.required' => 'La devolución necesita un motivo: queda registrada con tu nombre.',
            'motivo.min' => 'Explica el motivo con un poco más de detalle.',
            'lineas.*.venta_detalle_id.exists' => 'Una de las líneas no pertenece a esta venta.',
        ], [
            'motivo' => 'motivo',
        ]);

        $turnos = $this->turnosPosibles();
        $sesion = $turnos->count() === 1
            ? $turnos->first()
            : $turnos->firstWhere('id', (int) ($datos['sesion_caja_id'] ?? 0));

        if ($turnos->isEmpty()) {
            return redirect()->route('caja.index')
                ->with('error', 'No hay ninguna caja abierta: el dinero de la devolución sale de un cajón.');
        }

        if (! $sesion) {
            return back()->with('error', 'Elige de qué caja sale el dinero de la devolución.')->withInput();
        }

        try {
            $devolucion = Devoluciones::registrar(
                venta: $venta,
                usuario: Auth::user(),
                sesion: $sesion,
                lineas: $datos['lineas'],
                motivo: $datos['motivo'],
                // En un minimarket lo habitual es devolver en efectivo, aunque
                // se haya pagado por QR: el formulario lo propone así.
                reembolso: $datos['reembolso'] ?? Devolucion::EFECTIVO,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('devoluciones.show', $devolucion)
            ->with('exito', 'Devolución registrada por '.Config::importe($devolucion->total).
                '. El stock que volvió al estante ya está en el kardex.');
    }

    /**
     * De qué turno puede salir el dinero.
     *
     * El propio, si quien devuelve tiene la caja abierta. Si no —el caso de
     * todos los días con una sola caja: el cajero la tiene y el administrador
     * autoriza la devolución—, el turno abierto de la caja física, que es de
     * donde sale la plata. Con varias cajas abiertas se elige.
     *
     * @return Collection<int, SesionCaja>
     */
    private function turnosPosibles(): Collection
    {
        if ($propio = Cajas::sesionDe(Auth::user())) {
            return collect([$propio->load('caja:id,nombre', 'usuarioApertura:id,usuario')]);
        }

        return SesionCaja::with('caja:id,nombre', 'usuarioApertura:id,usuario')
            ->where('estado', 'ABIERTA')
            ->orderBy('caja_id')
            ->get();
    }

    public function show(Devolucion $devolucion): View
    {
        $devolucion->load([
            'venta.cliente:id,nombre',
            'venta.comprobante:id,venta_id,numero_completo',
            'usuario:id,usuario',
            'sesionCaja.caja:id,nombre',
            'detalle.producto:id,codigo,unidad_medida_id',
            'detalle.producto.unidadMedida:id,codigo',
            // La tasa de impuesto está congelada en la línea de la venta.
            'detalle.ventaDetalle:id,descripcion,afecto_impuesto,tasa_impuesto',
        ]);

        return view('devoluciones.show', [
            'title' => 'Devolución #'.$devolucion->id,
            'trail' => ['Devoluciones' => route('devoluciones.index')],
            'devolucion' => $devolucion,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, float|int>
     */
    private function resumen(array $filtros): array
    {
        $base = Devolucion::query()
            ->when($filtros['desde'], fn ($q, $d) => $q->whereDate('fecha', '>=', $d))
            ->when($filtros['hasta'], fn ($q, $h) => $q->whereDate('fecha', '<=', $h));

        return [
            'operaciones' => (int) (clone $base)->count(),
            'devuelto' => (float) (clone $base)->sum('total'),
            'totales' => (int) (clone $base)->where('tipo', 'TOTAL')->count(),
        ];
    }
}
