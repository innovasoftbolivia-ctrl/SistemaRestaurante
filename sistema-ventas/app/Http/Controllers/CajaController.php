<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Support\Config;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class CajaController extends Controller
{
    public function index(): View
    {
        $usuario = Auth::user();

        return view('caja.index', [
            'title' => 'Caja',
            'sesion' => Cajas::sesionDe($usuario)?->loadCount('ventas'),
            'cajas' => Caja::activas()->with('sesionAbierta.usuarioApertura')->orderBy('nombre')->get(),
            // El cajero ve sus turnos; los de los demás y sus diferencias son
            // de quien arquea.
            'historial' => SesionCaja::with(['caja:id,nombre', 'usuarioApertura:id,usuario'])
                ->when(! self::arquea($usuario), fn ($q) => $q->where('usuario_apertura_id', $usuario->id))
                ->withCount('ventas')
                ->orderByDesc('fecha_apertura')
                ->paginate(10),
            'veArqueo' => self::arquea($usuario),
        ]);
    }

    /**
     * Quién ve el efectivo esperado y las diferencias: quien cierra la caja o
     * revisa los reportes.
     *
     * El cajero no. Si viera en vivo «lo que debería haber», sabría cuánto
     * sobra antes de que el administrador cuente, y el arqueo dejaría de
     * controlar nada. Es el mismo criterio que le quitó el cierre.
     */
    public static function arquea(?Usuario $usuario): bool
    {
        return $usuario !== null && ($usuario->tienePermiso('caja.cerrar') || $usuario->tienePermiso('reportes.ver'));
    }

    public function show(SesionCaja $sesion): View
    {
        abort_unless(self::arquea(Auth::user()) || $sesion->usuario_apertura_id === Auth::id(), 403);

        $sesion->load([
            'caja:id,nombre,ubicacion',
            'usuarioApertura:id,usuario',
            'usuarioCierre:id,usuario',
            'movimientos.usuario:id,usuario',
        ]);

        return view('caja.show', [
            'title' => 'Turno de '.$sesion->caja?->nombre,
            'trail' => ['Caja' => route('caja.index')],
            'sesion' => $sesion,
            'ventas' => $sesion->ventas()
                ->with(['cliente:id,nombre', 'comprobante:id,venta_id,numero_completo'])
                ->orderByDesc('fecha')
                ->paginate(15),
            'resumen' => $this->resumen($sesion),
            'veArqueo' => self::arquea(Auth::user()),
        ]);
    }

    /**
     * El resumen del turno listo para imprimir y firmar: constancia de que
     * el administrador arqueó la caja junto al cajero al momento del cierre
     * (O4). Por eso solo existe una vez cerrada la sesión —imprimirlo antes
     * dejaría un documento con el arqueo en blanco, que es justo lo que no
     * se quiere: el conteo se hace en el momento de cerrar, no antes ni
     * aparte.
     */
    public function imprimir(SesionCaja $sesion): View|RedirectResponse
    {
        abort_unless(self::arquea(Auth::user()) || $sesion->usuario_apertura_id === Auth::id(), 403);

        if ($sesion->estaAbierta()) {
            return redirect()->route('caja.show', $sesion)
                ->with('error', 'El resumen se imprime al cerrar la caja, no antes.');
        }

        $sesion->load([
            'caja:id,nombre,ubicacion',
            'usuarioApertura:id,usuario,empleado_id',
            'usuarioApertura.empleado:id,nombre_completo',
            'usuarioCierre:id,usuario',
            'movimientos.usuario:id,usuario',
        ]);

        return view('caja.imprimir', [
            'sesion' => $sesion,
            'resumen' => $this->resumen($sesion),
            'porMetodo' => $this->porMetodoPago($sesion),
            'negocio' => [
                'nombre' => Config::get('negocio_nombre', config('app.name')),
                'documento' => Config::get('negocio_documento'),
                'direccion' => Config::get('negocio_direccion'),
                'telefono' => Config::get('negocio_telefono'),
            ],
        ]);
    }

    public function abrir(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'caja_id' => ['required', Rule::exists('cajas', 'id')->where('activo', 1)],
            'monto_inicial' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'observacion' => ['nullable', 'string', 'max:255'],
        ], [], [
            'caja_id' => 'caja',
            'monto_inicial' => 'monto inicial',
            'observacion' => 'observación',
        ]);

        try {
            Cajas::abrir(
                Caja::findOrFail($datos['caja_id']),
                Auth::user(),
                (float) $datos['monto_inicial'],
                $datos['observacion'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('caja.index')->with('exito', 'Caja abierta. Ya puedes vender.');
    }

    public function movimiento(Request $request, SesionCaja $sesion): RedirectResponse
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(['INGRESO', 'EGRESO'])],
            'concepto' => ['required', 'string', 'max:120'],
            'monto' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
        ], [
            'monto.gt' => 'El monto debe ser mayor que cero.',
        ], [
            'tipo' => 'tipo de movimiento',
        ]);

        // Quien abrió el turno, o quien puede cerrarlo: el administrador
        // registra en el turno del cajero el egreso que supera su tope.
        if ($sesion->usuario_apertura_id !== Auth::id() && ! Auth::user()->tienePermiso('caja.cerrar')) {
            return back()->with('error', 'Solo quien abrió la caja puede registrar sus movimientos.');
        }

        try {
            Cajas::movimiento(
                $sesion,
                Auth::user(),
                $datos['tipo'],
                $datos['concepto'],
                (float) $datos['monto'],
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('exito', 'Movimiento registrado.');
    }

    public function cerrar(Request $request, SesionCaja $sesion): RedirectResponse
    {
        $datos = $request->validate([
            'monto_declarado' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'observacion' => ['nullable', 'string', 'max:255'],
        ], [], [
            'monto_declarado' => 'efectivo contado',
            'observacion' => 'observación',
        ]);

        if ($sesion->usuario_apertura_id !== Auth::id() && ! Auth::user()->tienePermiso('reportes.ver')) {
            return back()->with('error', 'Solo quien abrió la caja puede cerrarla.');
        }

        try {
            $sesion = Cajas::cerrar(
                $sesion,
                Auth::user(),
                (float) $datos['monto_declarado'],
                $datos['observacion'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            // El procedimiento almacenado también puede avisar con SIGNAL
            // (p. ej. si dos personas cierran la misma sesión a la vez).
            return back()->with('error', $this->mensajeDeBase($e));
        }

        // Directo al resumen para imprimir y firmar con el cajero: es el
        // momento del cierre, no un paso aparte para más tarde. La diferencia
        // ya se ve ahí mismo, en el arqueo del documento —no hace falta
        // repetirla en un mensaje, que además no llegaría a mostrarse: esa
        // vista es un documento propio, sin el layout que pinta los flashes.
        return redirect()->route('caja.imprimir', $sesion);
    }

    /** @return array<string, float|int> */
    private function resumen(SesionCaja $sesion): array
    {
        $ventas = $sesion->ventas()->where('estado', '<>', 'ANULADA');

        return [
            'ventas' => (int) $ventas->count(),
            'anuladas' => (int) $sesion->ventas()->where('estado', 'ANULADA')->count(),
            'vendido' => (float) $ventas->sum('total'),
            'ingresos' => (float) $sesion->movimientos()->where('tipo', 'INGRESO')->sum('monto'),
            'egresos' => (float) $sesion->movimientos()->where('tipo', 'EGRESO')->sum('monto'),
            'esperado' => $sesion->estaAbierta() ? $sesion->efectivoEsperado() : (float) $sesion->monto_esperado,
        ];
    }

    /**
     * Cuánto se cobró por cada método en el turno: explica por qué «Vendido»
     * y «Efectivo esperado» no coinciden —solo el efectivo pasa por el
     * cajón— y es justo lo que hace falta para explicarle al cajero por qué
     * cuadra (o no) al momento de firmar.
     */
    private function porMetodoPago(SesionCaja $sesion)
    {
        return DB::table('venta_pagos as vp')
            ->join('ventas as v', function ($join) use ($sesion) {
                $join->on('v.id', '=', 'vp.venta_id')
                    ->where('v.sesion_caja_id', $sesion->id)
                    ->where('v.estado', '<>', 'ANULADA');
            })
            ->join('metodos_pago as mp', 'mp.id', '=', 'vp.metodo_pago_id')
            ->groupBy('mp.nombre', 'mp.afecta_caja')
            ->selectRaw('mp.nombre AS metodo_pago, mp.afecta_caja, SUM(vp.monto) AS monto')
            ->orderByDesc('monto')
            ->get();
    }

    /** Extrae el texto del SIGNAL de MySQL, que llega envuelto en ruido. */
    private function mensajeDeBase(Throwable $e): string
    {
        if (preg_match('/SQLSTATE\[45000\].*?:\s*\d+\s+(.+?)(?: \(Connection:|$)/s', $e->getMessage(), $m)) {
            return trim($m[1]);
        }

        report($e);

        return 'No se pudo cerrar la caja. Inténtalo de nuevo.';
    }
}
