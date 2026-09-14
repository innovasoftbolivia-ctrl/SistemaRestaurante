<?php

namespace App\Http\Controllers;

use App\Models\CobroQr;
use App\Services\Cajas;
use App\Services\CobrosQr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;

/**
 * El cobro por QR desde el mostrador.
 *
 * Todo responde JSON: el punto de venta lo consume sin recargar la página,
 * porque el cajero está esperando con el cliente enfrente.
 *
 * Un cobro solo lo maneja quien lo generó y mientras su turno siga abierto.
 * Sin eso, conociendo un número de cobro cualquiera podría confirmarlo desde
 * otra sesión.
 */
class CobroQrController extends Controller
{
    /** Pide un QR por un importe. Todavía no hay venta. */
    public function crear(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'monto' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'glosa' => ['nullable', 'string', 'max:120'],
        ], [
            'monto.gt' => 'El importe a cobrar debe ser mayor que cero.',
        ]);

        $sesion = Cajas::sesionDe(Auth::user());

        if (! $sesion) {
            return response()->json(['error' => 'Abre tu caja antes de cobrar por QR.'], 422);
        }

        try {
            $cobro = CobrosQr::generar(
                sesion: $sesion,
                usuario: Auth::user(),
                monto: (float) $datos['monto'],
                glosa: $datos['glosa'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => 'No se pudo generar el QR. Cobra por otro medio.'], 500);
        }

        return response()->json($this->comoJson($cobro));
    }

    /** ¿Ya pagaron? Es lo que el mostrador pregunta cada pocos segundos. */
    public function consultar(CobroQr $cobro): JsonResponse
    {
        if ($respuesta = $this->rechazarSiNoEsSuyo($cobro)) {
            return $respuesta;
        }

        try {
            $cobro = CobrosQr::refrescar($cobro);
        } catch (Throwable $e) {
            report($e);

            // Que el banco no conteste no es motivo para romperle la pantalla
            // al cajero: se devuelve lo último que se sabe y él decide.
            return response()->json($this->comoJson($cobro) + [
                'aviso' => 'No se pudo consultar al banco. Reintentando.',
            ]);
        }

        return response()->json($this->comoJson($cobro));
    }

    /** El cajero da por pagado el cobro mirando el celular del cliente. */
    public function confirmar(Request $request, CobroQr $cobro): JsonResponse
    {
        if ($respuesta = $this->rechazarSiNoEsSuyo($cobro)) {
            return $respuesta;
        }

        $datos = $request->validate([
            'referencia' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            $cobro = CobrosQr::confirmarAMano($cobro, Auth::user(), $datos['referencia'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($this->comoJson($cobro));
    }

    /** El cajero cancela un QR que ya no se va a usar. */
    public function anular(CobroQr $cobro): JsonResponse
    {
        if ($respuesta = $this->rechazarSiNoEsSuyo($cobro)) {
            return $respuesta;
        }

        try {
            $cobro = CobrosQr::anular($cobro, Auth::user());
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($this->comoJson($cobro));
    }

    /**
     * El banco avisa que le pagaron.
     *
     * Sin sesión —el banco no inicia sesión— y sin CSRF. El aviso nunca da un
     * cobro por pagado por sí mismo: {@see CobrosQr::procesarAviso()} le
     * pregunta al banco y vale lo que el banco conteste.
     *
     * Responde con `responseCode`/`message`, el formato que espera Banco
     * Económico, además de los campos propios.
     */
    public function aviso(Request $request): JsonResponse
    {
        // Las cabeceras llegan como listas; la pasarela espera un valor por nombre.
        $cabeceras = array_map(fn ($valores) => (string) ($valores[0] ?? ''), $request->headers->all());

        try {
            $cobro = CobrosQr::procesarAviso($request->all(), $cabeceras);
        } catch (RuntimeException $e) {
            return response()->json(['responseCode' => 1, 'message' => $e->getMessage(), 'error' => $e->getMessage()], 403);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['responseCode' => 1, 'message' => 'No se pudo procesar el aviso.', 'error' => 'No se pudo procesar el aviso.'], 500);
        }

        return response()->json(['responseCode' => 0, 'message' => '', 'recibido' => true, 'estado' => $cobro?->estado]);
    }

    /**
     * Un cobro pertenece a quien lo generó, y solo mientras su turno siga
     * abierto.
     */
    private function rechazarSiNoEsSuyo(CobroQr $cobro): ?JsonResponse
    {
        if ($cobro->usuario_id !== Auth::id()) {
            return response()->json(['error' => 'Ese cobro no es tuyo.'], 403);
        }

        $sesion = Cajas::sesionDe(Auth::user());

        if (! $sesion || $sesion->id !== $cobro->sesion_caja_id) {
            return response()->json(['error' => 'Ese cobro es de otro turno de caja.'], 403);
        }

        return null;
    }

    /**
     * Lo que necesita el mostrador para pintar el QR y decidir si puede cobrar.
     *
     * @return array<string, mixed>
     */
    private function comoJson(CobroQr $cobro): array
    {
        return [
            'id' => $cobro->id,
            'estado' => $cobro->estado,
            'etiqueta' => $cobro->etiqueta_estado,
            'monto' => (float) $cobro->monto,
            'moneda' => $cobro->moneda,
            'payload' => $cobro->payload,
            // El banco puede devolver la imagen ya hecha en vez del texto a dibujar.
            'imagen' => str_starts_with((string) $cobro->payload, 'data:image/'),
            'expira_en' => $cobro->expira_en?->toIso8601String(),
            'pagado' => $cobro->estaPagado(),
            'simulado' => CobrosQr::estaSimulado(),
            'referencia' => $cobro->referencia_bancaria,
        ];
    }
}
