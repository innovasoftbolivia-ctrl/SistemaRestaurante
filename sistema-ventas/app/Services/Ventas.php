<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\CobroQr;
use App\Models\Comprobante;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\SerieComprobante;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Models\VentaPago;
use App\Support\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registro y anulación de ventas.
 *
 * Buena parte de las reglas vive en la base y aquí no se repite:
 *   - al insertar una línea, un trigger valida el stock, lo descuenta y
 *     escribe el kardex;
 *   - `sp_recalcular_venta` calcula subtotal e impuesto desde el detalle;
 *   - `sp_emitir_comprobante` toma el correlativo con bloqueo de fila y
 *     congela los datos del cliente;
 *   - `sp_anular_venta` revierte stock, marca la venta y anula el documento.
 *
 * Todo ocurre dentro de una transacción: o se guarda la venta completa, o no
 * se guarda nada (RNF5).
 */
class Ventas
{
    /**
     * Intentos ante un deadlock de MySQL antes de darse por vencido.
     *
     * Tres y no uno porque el empate entre dos transacciones que se pelean por
     * la misma fila es momentáneo: al reintentar, la otra ya terminó. Y tres y
     * no treinta porque si el choque persiste hay algo de fondo que no se
     * arregla insistiendo, y es mejor que el error salga a la luz.
     */
    private const REINTENTOS = 3;

    /**
     * `precio_unitario` es solo para llamadores de confianza (no HTTP): ver
     * el comentario en `agregarLineas`.
     *
     * @param  array<int, array{producto_id: int, cantidad: float, precio_unitario?: float, descuento?: float}>  $lineas
     * @param  array<int, array{metodo_pago_id: int, monto: float, monto_recibido?: float|null, referencia?: string|null}>  $pagos
     */
    public static function registrar(
        SesionCaja $sesion,
        Usuario $usuario,
        array $lineas,
        array $pagos,
        ?Cliente $cliente = null,
        float $descuento = 0,
        ?string $observacion = null,
    ): Venta {
        if ($lineas === []) {
            throw new RuntimeException('La venta no tiene productos.');
        }

        if (! $sesion->estaAbierta()) {
            throw new RuntimeException('No hay una caja abierta para registrar la venta.');
        }

        // El segundo argumento son reintentos ante un deadlock de MySQL. Dos
        // cajeros vendiendo el MISMO producto al mismo tiempo se pelean por la
        // fila de `productos` (el trigger la bloquea con FOR UPDATE para
        // descontar stock), y InnoDB resuelve el empate matando a una de las
        // dos transacciones. Los datos nunca quedan mal —para eso está el
        // bloqueo—, pero sin reintentar, a un cajero se le caía la venta con
        // un «revisa el stock y los importes» que además no era cierto: no
        // había problema de stock, solo mala suerte de milisegundos. Laravel
        // reintenta la transacción entera, que se deshizo por completo al
        // fallar, así que no queda nada a medias.
        return DB::transaction(function () use ($sesion, $usuario, $lineas, $pagos, $cliente, $descuento, $observacion) {
            $venta = Venta::create([
                'cliente_id' => $cliente?->id,
                'usuario_id' => $usuario->id,
                'sesion_caja_id' => $sesion->id,
                'fecha' => now(),
                'descuento' => 0, // se aplica después: la base exige descuento <= subtotal
                'estado' => 'COMPLETADA',
                'observacion' => $observacion,
            ]);

            self::agregarLineas($venta, $lineas);

            // Primer recálculo: deja el subtotal, sin descuento todavía.
            self::recalcular($venta->id);

            if ($descuento > 0) {
                $venta->refresh();

                if ($descuento > (float) $venta->subtotal) {
                    throw new RuntimeException('El descuento no puede superar el subtotal de la venta.');
                }

                $venta->update(['descuento' => $descuento]);

                // Segundo recálculo: el impuesto baja en proporción al descuento.
                self::recalcular($venta->id);
            }

            $venta->refresh();

            self::registrarPagos($venta, $pagos);
            self::emitirComprobante($venta, $cliente);

            Auditor::registrar('VENTA_REGISTRADA', 'ventas', $venta->id, [
                'total' => $venta->fresh()->total,
                'lineas' => count($lineas),
                'cliente_id' => $cliente?->id,
            ], $usuario->id);

            return $venta->fresh(['detalle', 'pagos', 'comprobante']);
        }, self::REINTENTOS);
    }

    /** Subtotal, impuesto y total desde el detalle. Lo hace la base, salvo en la vía portable. */
    private static function recalcular(int $ventaId): void
    {
        ReglasEnPhp::activa()
            ? ReglasEnPhp::recalcularVenta($ventaId)
            : DB::statement('CALL sp_recalcular_venta(?)', [$ventaId]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineas
     */
    private static function agregarLineas(Venta $venta, array $lineas): void
    {
        foreach ($lineas as $linea) {
            $producto = Producto::findOrFail($linea['producto_id']);

            if (! $producto->activo) {
                throw new RuntimeException("«{$producto->nombre}» está descatalogado y no se puede vender.");
            }

            $cantidad = (float) $linea['cantidad'];

            if ($cantidad <= 0) {
                throw new RuntimeException("La cantidad de «{$producto->nombre}» debe ser mayor que cero.");
            }

            if (! $producto->unidadMedida?->permite_decimal && fmod($cantidad, 1.0) !== 0.0) {
                throw new RuntimeException("«{$producto->nombre}» se vende por unidad entera.");
            }

            if ($cantidad > (float) $producto->stock_actual) {
                throw new RuntimeException(
                    "No hay stock suficiente de «{$producto->nombre}»: quedan ".
                    Config::cantidad($producto->stock_actual).' '.$producto->unidadMedida?->codigo.'.'
                );
            }

            $datos = [
                'venta_id' => $venta->id,
                'producto_id' => $producto->id,
                // Copia histórica: la venta no cambia si mañana cambia el catálogo.
                'descripcion' => $producto->nombre,
                // La unidad se copia por el mismo motivo que el nombre y el
                // precio. Sin esto, corregir un producto de UND a KG reescribía
                // todos los tickets viejos de ese producto.
                'unidad' => $producto->unidadMedida?->codigo,
                'cantidad' => $cantidad,
                // `precio_unitario` explícito es para llamadores de confianza
                // (pruebas, scripts internos): PosController, el único que
                // recibe pedidos por HTTP, nunca lo manda —lo quitó de sus
                // reglas de validación a propósito— así que el navegador jamás
                // decide el precio de una venta real.
                'precio_unitario' => $linea['precio_unitario'] ?? $producto->precio_venta,
                'descuento' => $linea['descuento'] ?? 0,
            ];

            // Sin triggers en la base, el régimen de impuesto y el descuento de
            // stock los hace PHP (ver config/ventas.php).
            if (ReglasEnPhp::activa()) {
                $detalle = VentaDetalle::create(ReglasEnPhp::antesDeInsertarLineaVenta($datos));
                ReglasEnPhp::despuesDeInsertarLineaVenta($venta->id, $producto->id, $cantidad);
            } else {
                $detalle = VentaDetalle::create($datos);
            }

            // Los lotes se descuentan aquí, fuera del `if`, y no dentro del
            // trigger ni de su gemelo de PHP: así hay UNA implementación de
            // FEFO para las dos vías en vez de dos que hay que mantener
            // iguales a mano. El stock ya bajó arriba; esto solo reparte esa
            // baja entre las tandas, empezando por la que vence antes.
            Lotes::consumir($producto, $cantidad, $detalle->id);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $pagos
     */
    private static function registrarPagos(Venta $venta, array $pagos): void
    {
        if ($pagos === []) {
            throw new RuntimeException('La venta no tiene forma de pago.');
        }

        $total = round((float) $venta->total, 2);

        /*
         * Un pago puede venir sin importe: significa «el resto». El mostrador
         * usa esa forma para el cobro simple, de modo que el total lo pone
         * siempre el servidor y un céntimo de diferencia en el redondeo del
         * navegador no puede tumbar la venta.
         */
        $sinMonto = array_filter($pagos, fn ($p) => ! isset($p['monto']) || $p['monto'] === null || $p['monto'] === '');

        if (count($sinMonto) > 1) {
            throw new RuntimeException('Solo una forma de pago puede quedar sin importe.');
        }

        $explicito = round(array_sum(array_map(
            fn ($p) => (float) ($p['monto'] ?? 0),
            $pagos,
        )), 2);

        if ($sinMonto !== []) {
            $resto = round($total - $explicito, 2);

            if ($resto <= 0) {
                throw new RuntimeException('Las formas de pago indicadas ya cubren el total de la venta.');
            }

            $indice = array_key_first($sinMonto);
            $pagos[$indice]['monto'] = $resto;
            $explicito = $total;
        }

        if ($explicito !== $total) {
            throw new RuntimeException(
                'Lo pagado ('.Config::importe($explicito).') no coincide con el total de la venta ('.
                Config::importe($total).').'
            );
        }

        $cobrosUsados = [];

        foreach ($pagos as $pago) {
            $metodo = MetodoPago::findOrFail($pago['metodo_pago_id']);
            $monto = round((float) $pago['monto'], 2);
            $recibido = isset($pago['monto_recibido']) ? round((float) $pago['monto_recibido'], 2) : null;

            if ($monto <= 0) {
                throw new RuntimeException('Cada forma de pago debe tener un monto mayor que cero.');
            }

            $cobro = self::cobroQrDelPago($venta, $metodo, $pago, $monto, $cobrosUsados);

            // El vuelto solo existe en efectivo: en tarjeta se cobra el importe exacto.
            if (! $metodo->esEfectivo()) {
                $recibido = null;
            } elseif ($recibido !== null && $recibido < $monto) {
                throw new RuntimeException('El efectivo recibido es menor que el importe a cobrar.');
            }

            VentaPago::create([
                'venta_id' => $venta->id,
                'metodo_pago_id' => $metodo->id,
                'monto' => $monto,
                'monto_recibido' => $recibido,
                'referencia' => $pago['referencia'] ?? $cobro?->referencia_bancaria,
            ]);

            $cobro?->update(['venta_id' => $venta->id]);
        }
    }

    /**
     * El cobro por QR que respalda un pago, ya bloqueado y comprobado.
     *
     * Todo dentro de la transacción de la venta y con la fila del cobro
     * bloqueada: si algo no cuadra, la venta entera se deshace, y dos ventas a
     * la vez no pueden gastar el mismo QR. Las reglas:
     *
     *   - pago por QR ⇔ cobro: un QR sin cobro detrás no se acepta, y un cobro
     *     no puede respaldar un pago en efectivo (el dinero está en el banco,
     *     no en el cajón);
     *   - el cobro es de quien vende, de este turno, está pagado y libre;
     *   - el importe del pago —también cuando es «el resto»— es exactamente el
     *     del QR. Si el carrito cambió después de cobrar, no cuadra.
     *
     * @param  array<string, mixed>  $pago
     * @param  array<int, true>  $usados
     */
    private static function cobroQrDelPago(Venta $venta, MetodoPago $metodo, array $pago, float $monto, array &$usados): ?CobroQr
    {
        $id = (int) ($pago['cobro_qr_id'] ?? 0);
        $esQr = $metodo->codigo === 'QR';

        if (! $esQr) {
            if ($id) {
                throw new RuntimeException('Un cobro por QR solo puede respaldar un pago por QR.');
            }

            return null;
        }

        if (! $id) {
            throw new RuntimeException('El pago por QR necesita su cobro: genera el QR y espera la confirmación del pago.');
        }

        if (isset($usados[$id])) {
            throw new RuntimeException('El mismo cobro por QR no puede pagar dos veces.');
        }

        $usados[$id] = true;
        $cobro = CobroQr::whereKey($id)->lockForUpdate()->first();

        if (! $cobro || $cobro->usuario_id !== $venta->usuario_id || $cobro->sesion_caja_id !== $venta->sesion_caja_id) {
            throw new RuntimeException('Ese cobro por QR no es de este turno de caja.');
        }

        if (! $cobro->estaPagado()) {
            throw new RuntimeException('El cobro por QR todavía no está pagado.');
        }

        if ($cobro->venta_id !== null) {
            throw new RuntimeException('Ese cobro por QR ya se usó en otra venta.');
        }

        if (abs($monto - round((float) $cobro->monto, 2)) > 0.001) {
            throw new RuntimeException(sprintf(
                'El QR se pagó por %s y el pago de la venta es de %s: el total cambió después de cobrar. Revisa el carrito.',
                Config::importe($cobro->monto),
                Config::importe($monto),
            ));
        }

        return $cobro;
    }

    /**
     * Factura para persona jurídica, recibo para el resto. El tipo lo decide
     * la serie, y un trigger comprueba que corresponda al cliente.
     */
    private static function emitirComprobante(Venta $venta, ?Cliente $cliente): Comprobante
    {
        $serie = self::seriePara($cliente);

        if (ReglasEnPhp::activa()) {
            [$id] = ReglasEnPhp::emitirComprobante($venta->id, $serie->id);
        } else {
            DB::statement('CALL sp_emitir_comprobante(?, ?, @comprobante_id, @numero)', [
                $venta->id, $serie->id,
            ]);

            $id = DB::selectOne('SELECT @comprobante_id AS id')->id;
        }

        if (! $id) {
            throw new RuntimeException('No se pudo emitir el comprobante de la venta.');
        }

        return Comprobante::findOrFail($id);
    }

    /** La serie a usar sale de la configuración del negocio. */
    public static function seriePara(?Cliente $cliente): SerieComprobante
    {
        $clave = $cliente?->esJuridica() ? 'serie_factura' : 'serie_recibo';
        $id = (int) Config::get($clave, '0');

        $serie = SerieComprobante::with('tipo')->find($id);

        if (! $serie) {
            throw new RuntimeException("No hay una serie configurada en «{$clave}».");
        }

        return $serie;
    }

    /**
     * Anular revierte el stock y deja el documento anulado, conservando el
     * correlativo. La venta no se borra nunca (RNF6).
     */
    public static function anular(Venta $venta, Usuario $usuario, string $motivo): Venta
    {
        if ($venta->estado !== 'COMPLETADA') {
            throw new RuntimeException('Solo se puede anular una venta completada.');
        }

        // Igual que `Cajas::cerrar()`: `sp_anular_venta` hace su SELECT ...
        // FOR UPDATE y sus escrituras en sentencias separadas, así que sin
        // envolverlo en una transacción real el lock no sobrevive más allá
        // del propio SELECT (autocommit). Envuelto aquí, una anulación y una
        // devolución que lleguen casi al mismo tiempo para la misma venta se
        // serializan: la segunda espera, y al retomar ya ve el nuevo estado.
        //
        // sp_anular_venta ya escribe su propia entrada en `auditoria`.
        DB::transaction(function () use ($venta, $usuario, $motivo) {
            // El turno bloqueado: una anulación y el cierre de esa caja no
            // pueden cruzarse, y lo que se decidió en pantalla se vuelve a
            // comprobar aquí.
            $turno = SesionCaja::whereKey($venta->sesion_caja_id)->lockForUpdate()->first();

            if ($turno?->estado !== 'ABIERTA') {
                throw new RuntimeException('El turno de caja de esta venta ya cerró, así que no se puede anular: ese dinero ya se contó en su arqueo. Si hay que devolver algo, registra una devolución.');
            }

            ReglasEnPhp::activa()
                ? ReglasEnPhp::anularVenta($venta->id, $usuario->id, $motivo)
                : DB::statement('CALL sp_anular_venta(?, ?, ?)', [$venta->id, $usuario->id, $motivo]);

            // La anulación devuelve al estante TODO lo que salió, así que los
            // lotes tienen que recibirlo de vuelta. Se lee el detalle después
            // de anular porque el reparto sigue al stock, y el stock lo acaba
            // de reponer el procedimiento.
            foreach ($venta->detalle()->with('producto')->get() as $linea) {
                if ($linea->producto) {
                    Lotes::reponerDeVenta($linea->producto, $linea->id, (float) $linea->cantidad);
                }
            }
        });

        return $venta->fresh();
    }
}
