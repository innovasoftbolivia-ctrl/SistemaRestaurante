<?php

namespace App\Services;

use App\Models\Devolucion;
use App\Models\DevolucionDetalle;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Support\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Devolución de mercadería de una venta ya cobrada.
 *
 * Igual que en las ventas, el trabajo pesado está en la base: al insertar
 * cada línea, `trg_devolucion_detalle_after_insert` acumula lo devuelto en la
 * venta original, recalcula el total de la devolución, mueve la venta a
 * DEVUELTA o DEVUELTA_PARCIAL y —si la mercadería vuelve al estante—
 * reingresa el stock con su movimiento de kardex.
 *
 * Aquí queda lo que la base no puede saber: que no se devuelva más de lo que
 * se vendió, y que el dinero salga de un cajón abierto.
 */
class Devoluciones
{
    /** Reintentos ante un deadlock; mismo criterio que `Ventas::REINTENTOS`. */
    private const REINTENTOS = 3;

    /**
     * @param  array<int, array{venta_detalle_id: int, cantidad: float, reingresa_stock?: bool}>  $lineas
     */
    public static function registrar(
        Venta $venta,
        Usuario $usuario,
        SesionCaja $sesion,
        array $lineas,
        string $motivo,
        string $reembolso = Devolucion::MISMO_MEDIO,
    ): Devolucion {
        if (! in_array($reembolso, [Devolucion::EFECTIVO, Devolucion::MISMO_MEDIO], true)) {
            throw new RuntimeException('El medio de reembolso no es válido.');
        }

        if (! $venta->admiteDevolucion()) {
            throw new RuntimeException(match ($venta->estado) {
                'ANULADA' => 'La venta está anulada: su stock y su dinero ya se revirtieron.',
                'DEVUELTA' => 'Esta venta ya fue devuelta por completo.',
                default => 'Esta venta no admite devoluciones.',
            });
        }

        if (! $sesion->estaAbierta()) {
            throw new RuntimeException('Necesitas una caja abierta: el dinero de la devolución sale del cajón.');
        }

        $lineas = self::limpiar($lineas);

        if ($lineas === []) {
            throw new RuntimeException('No se indicó ninguna cantidad a devolver.');
        }

        return DB::transaction(function () use ($venta, $usuario, $sesion, $lineas, $motivo, $reembolso) {
            // El turno bloqueado y comprobado de nuevo: el dinero sale de ese
            // cajón, y no puede cerrarse mientras tanto.
            $sesion = SesionCaja::whereKey($sesion->id)->lockForUpdate()->firstOrFail();

            if (! $sesion->estaAbierta()) {
                throw new RuntimeException('Necesitas una caja abierta: el dinero de la devolución sale del cajón.');
            }

            $disponible = round($sesion->efectivoEsperado(), 2);

            // El chequeo de arriba se hizo sobre el `$venta` que cargó el
            // controlador, sin bloquear la fila: si una anulación de esta
            // misma venta está corriendo en paralelo (también dentro de su
            // propia transacción, con su propio `FOR UPDATE`, desde
            // `Ventas::anular()`), las dos podían leer "COMPLETADA" antes de
            // que cualquiera confirme. Relee y bloquea aquí, ya dentro de la
            // transacción: si la anulación va primero, esta espera y ve el
            // estado ya actualizado; si va después, espera a que esta termine.
            $venta = Venta::whereKey($venta->id)->lockForUpdate()->firstOrFail();

            if (! $venta->admiteDevolucion()) {
                throw new RuntimeException(match ($venta->estado) {
                    'ANULADA' => 'La venta está anulada: su stock y su dinero ya se revirtieron.',
                    'DEVUELTA' => 'Esta venta ya fue devuelta por completo.',
                    default => 'Esta venta no admite devoluciones.',
                });
            }

            $devolucion = Devolucion::create([
                'venta_id' => $venta->id,
                'usuario_id' => $usuario->id,
                // Quien registra es quien autoriza: llegar aquí ya exige el
                // permiso `devoluciones.registrar`.
                'autorizado_por' => $usuario->id,
                'sesion_caja_id' => $sesion->id,
                'fecha' => now(),
                // Provisional: se corrige abajo, cuando el trigger ya sabe si
                // quedó algo pendiente en la venta.
                'tipo' => 'PARCIAL',
                'motivo' => $motivo,
            ]);

            foreach ($lineas as $linea) {
                self::agregarLinea($venta, $devolucion, $linea);
            }

            // El trigger deja la venta en DEVUELTA si ya no queda nada por devolver.
            $devolucion->refresh();
            $efectivo = self::efectivoDelCajon($venta, (float) $devolucion->total, $reembolso);

            if ($efectivo > $disponible) {
                throw new RuntimeException(sprintf(
                    'Para devolver %s en efectivo no alcanza lo que debería haber en el cajón (%s). Devuelve por el mismo medio del pago o registra antes el ingreso.',
                    Config::importe($efectivo),
                    Config::importe(max(0, $disponible)),
                ));
            }

            $devolucion->update([
                'tipo' => $venta->fresh()->estado === 'DEVUELTA' ? 'TOTAL' : 'PARCIAL',
                'reembolso' => $reembolso,
                'efectivo' => $efectivo,
            ]);

            $devolucion->refresh();

            // El responsable se pasa explícito: el servicio también se usa
            // fuera de una petición HTTP, donde no hay sesión de la que sacarlo.
            Auditor::registrar('DEVOLUCION_REGISTRADA', 'devoluciones', $devolucion->id, [
                'venta_id' => $venta->id,
                'tipo' => $devolucion->tipo,
                'total' => $devolucion->total,
                'reembolso' => $reembolso,
                'efectivo' => $devolucion->efectivo,
                'turno' => $sesion->id,
                'motivo' => $motivo,
            ], $usuario->id);

            return $devolucion->load('detalle.producto');
        }, self::REINTENTOS);
    }

    /**
     * Qué parte de lo cobrado entró al cajón: la proporción pagada en efectivo.
     */
    public static function proporcionEnEfectivo(Venta $venta): float
    {
        $total = (float) $venta->total;

        if ($total <= 0) {
            return 0.0;
        }

        $efectivo = (float) DB::table('venta_pagos as vp')
            ->join('metodos_pago as mp', 'mp.id', '=', 'vp.metodo_pago_id')
            ->where('vp.venta_id', $venta->id)
            ->where('mp.afecta_caja', 1)
            ->sum('vp.monto');

        return min(1.0, $efectivo / $total);
    }

    /**
     * Lo que sale del cajón: todo, si se devuelve en efectivo; si se devuelve
     * por el mismo medio, solo la parte que en su día entró en efectivo
     * (la misma cuenta que hacía el arqueo antes de guardar este dato).
     */
    public static function efectivoDelCajon(Venta $venta, float $total, string $reembolso): float
    {
        return $reembolso === Devolucion::EFECTIVO
            ? round($total, 2)
            : round($total * self::proporcionEnEfectivo($venta), 2);
    }

    /**
     * @param  array{venta_detalle_id: int, cantidad: float, reingresa_stock?: bool}  $linea
     */
    private static function agregarLinea(Venta $venta, Devolucion $devolucion, array $linea): void
    {
        /** @var VentaDetalle $original */
        $original = VentaDetalle::where('venta_id', $venta->id)
            ->whereKey($linea['venta_detalle_id'])
            ->lockForUpdate()
            ->first();

        if (! $original) {
            throw new RuntimeException('Una de las líneas no pertenece a esta venta.');
        }

        $cantidad = round((float) $linea['cantidad'], 3);
        $pendiente = $original->pendiente_devolucion;

        if ($cantidad > $pendiente) {
            throw new RuntimeException(
                "De «{$original->descripcion}» solo quedan ".Config::cantidad($pendiente).
                ' sin devolver.'
            );
        }

        $original->loadMissing('producto.unidadMedida');

        if (! $original->producto?->unidadMedida?->permite_decimal && fmod($cantidad, 1.0) !== 0.0) {
            throw new RuntimeException("«{$original->descripcion}» se devuelve por unidad entera.");
        }

        $datos = [
            'devolucion_id' => $devolucion->id,
            'venta_detalle_id' => $original->id,
            'producto_id' => $original->producto_id,
            'cantidad' => $cantidad,
            // Se devuelve lo que el cliente pagó por unidad, no el precio de
            // lista: si la venta tuvo descuento de cabecera, `precio_unitario`
            // de la línea original sigue siendo el de catálogo (el descuento
            // solo vive en `ventas.descuento`, prorrateado sobre el total, no
            // sobre cada línea). Sin este ajuste se devolvía —y se restaba del
            // cajón al cerrar— más de lo que en realidad se cobró.
            'precio_unitario' => self::precioNetoUnitario($venta, $original),
            'reingresa_stock' => $linea['reingresa_stock'] ?? true,
        ];

        // Sin triggers, PHP hace lo que hacían: copiar el régimen de impuesto
        // de la línea original y reingresar el stock (ver config/ventas.php).
        if (ReglasEnPhp::activa()) {
            DevolucionDetalle::create(ReglasEnPhp::antesDeInsertarLineaDevolucion($datos));
            ReglasEnPhp::despuesDeInsertarLineaDevolucion(
                $devolucion->id,
                $original->id,
                $original->producto_id,
                $cantidad,
                (bool) $datos['reingresa_stock'],
            );
        } else {
            DevolucionDetalle::create($datos);
        }

        // Lo devuelto vuelve al estante —si vuelve: lo roto no reingresa— pero
        // salió hace días y nadie anotó de qué tanda era. Se repone en el lote
        // abierto que vence antes, que es por el que habría salido. Va fuera
        // del `if` por lo mismo que en la venta: una sola implementación de
        // lotes para las dos vías.
        if ($datos['reingresa_stock']) {
            Lotes::reponer($original->producto, $cantidad);
        }
    }

    /**
     * El precio por unidad neto de descuento de línea y de cabecera, igual
     * proporción que `sp_recalcular_venta`. Público porque el formulario de
     * devolución también lo necesita: le muestra al cajero el mismo importe
     * que este servicio va a registrar, no el precio de catálogo sin
     * prorratear (ver DevolucionController::create).
     */
    public static function precioNetoUnitario(Venta $venta, VentaDetalle $original): float
    {
        $subtotal = (float) $venta->subtotal;
        $factorCabecera = $subtotal > 0 ? ($subtotal - (float) $venta->descuento) / $subtotal : 1.0;

        // `importe` ya descuenta el descuento de línea (si lo hubiera); dividir
        // entre la cantidad da el precio neto por unidad antes de prorratear
        // el descuento de cabecera.
        $precioNetoDeLinea = (float) $original->importe / (float) $original->cantidad;

        return round($precioNetoDeLinea * $factorCabecera, 2);
    }

    /**
     * Descarta las líneas con cantidad cero: el formulario manda todas las de
     * la venta y el cajero solo llena las que devuelve.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     * @return array<int, array{venta_detalle_id: int, cantidad: float, reingresa_stock?: bool}>
     */
    private static function limpiar(array $lineas): array
    {
        return array_values(array_filter(
            $lineas,
            fn ($l) => isset($l['venta_detalle_id']) && (float) ($l['cantidad'] ?? 0) > 0,
        ));
    }
}
