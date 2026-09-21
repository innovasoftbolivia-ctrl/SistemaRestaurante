<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\CobroQr;
use App\Models\Comprobante;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\SerieComprobante;
use App\Models\SesionCaja;
use App\Models\TipoComprobante;
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
 *   - al insertar una línea, un trigger le copia el régimen de impuesto;
 *   - `sp_recalcular_venta` calcula subtotal e impuesto desde el detalle;
 *   - `sp_emitir_comprobante` toma el correlativo con bloqueo de fila y
 *     congela los datos del negocio y del cliente;
 *   - `sp_anular_venta` marca la venta y anula el documento.
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
     * `$pedido` es el pedido que se cobra (`Pedidos::cobrar`): la venta lo
     * graba en `pedido_id` y lo conserva aunque después se anule.
     *
     * @param  array<int, array{producto_id: int, cantidad: float, precio_unitario?: float}>  $lineas
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
        ?float $totalEsperado = null,
        ?Pedido $pedido = null,
        bool $desdePedido = false,
    ): Venta {
        if ($lineas === []) {
            throw new RuntimeException('La venta no tiene nada que cobrar.');
        }

        if (! $sesion->estaAbierta()) {
            throw new RuntimeException('No hay una caja abierta para registrar la venta.');
        }

        // El segundo argumento son reintentos ante un deadlock de MySQL. Dos
        // cajeros que cobran a la vez pueden pelearse por las mismas filas
        // (el turno de caja, la serie del comprobante), e InnoDB resuelve el
        // empate matando a una de las dos transacciones. Los datos nunca
        // quedan mal —para eso están los bloqueos—, pero sin reintentar a un
        // cajero se le caía la venta por mala suerte de milisegundos. Laravel
        // reintenta la transacción entera, que se deshizo por completo al
        // fallar, así que no queda nada a medias.
        return DB::transaction(function () use ($sesion, $usuario, $lineas, $pagos, $cliente, $descuento, $observacion, $totalEsperado, $pedido, $desdePedido) {
            // El turno, leído con candado compartido: varias ventas pueden
            // entrar a la vez, pero un cierre en curso las hace esperar, y al
            // terminar la venta encuentra la caja cerrada. Sin esto, una venta
            // que empezó con la pantalla vieja se colaba en un turno ya
            // arqueado y su efectivo no aparecía en ningún cierre.
            $turno = SesionCaja::whereKey($sesion->id)->sharedLock()->first();

            if (! $turno?->estaAbierta()) {
                throw new RuntimeException('La caja se cerró mientras registrabas la venta. Abre un turno nuevo para seguir vendiendo.');
            }

            $venta = Venta::create([
                'cliente_id' => $cliente?->id,
                'usuario_id' => $usuario->id,
                'sesion_caja_id' => $sesion->id,
                'pedido_id' => $pedido?->id,
                'fecha' => now(),
                'descuento' => 0, // se aplica después: la base exige descuento <= subtotal
                // El modo de precio queda con la venta: si mañana cambia la
                // configuración, esta venta se sigue calculando igual.
                'impuesto_incluido' => Config::preciosIncluyenImpuesto(),
                'estado' => 'COMPLETADA',
                'observacion' => $observacion,
            ]);

            self::agregarLineas($venta, $lineas, $desdePedido);

            // Primer recálculo: deja el subtotal, sin descuento todavía.
            self::recalcular($venta->id);

            if ($descuento > 0) {
                $venta->refresh();

                self::exigirAutorizacionDelDescuento($venta, $usuario, $descuento);

                if ($venta->impuesto_incluido) {
                    // El cliente ve el descuento sobre el precio final: el
                    // recálculo lo reparte entre base e impuesto.
                    if ($descuento > (float) $venta->total) {
                        throw new RuntimeException('El descuento no puede superar el total de la venta.');
                    }

                    $venta->update(['descuento_precio_final' => $descuento]);
                } else {
                    if ($descuento > (float) $venta->subtotal) {
                        throw new RuntimeException('El descuento no puede superar el subtotal de la venta.');
                    }

                    $venta->update(['descuento' => $descuento]);
                }

                // Segundo recálculo: el impuesto baja en proporción al descuento.
                self::recalcular($venta->id);
            }

            $venta->refresh();

            // Lo que el mostrador le cantó al cliente tiene que ser lo que se
            // registra. Si entre medio cambió la tasa, el modo de precios o un
            // precio del menú, la pantalla y el servidor calculan distinto
            // y el cajero cobra un total que la venta no guarda: sobrante o
            // faltante en el arqueo, sin rastro. Mismo criterio que el cobro
            // por QR, que ya exigía el importe exacto.
            if ($totalEsperado !== null && round($totalEsperado, 2) !== round((float) $venta->total, 2)) {
                throw new RuntimeException(sprintf(
                    'El total cambió mientras cobrabas: la pantalla decía %s y la venta suma %s. Revisa el carrito y vuelve a cobrar.',
                    Config::importe($totalEsperado),
                    Config::importe($venta->total),
                ));
            }

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

    /**
     * El descuento por encima de `descuento_max_cajero` necesita el permiso
     * `ventas.descuento` (O4).
     *
     * Vive aquí, dentro de la transacción y después del primer recálculo, y no
     * en los controladores: así la base del porcentaje es la de la venta que
     * de verdad se registra —el subtotal, o el total si el precio ya trae el
     * impuesto, que es sobre lo que el cliente ve el descuento— y ningún
     * camino que llegue a `registrar()` se lo salta. El mostrador solo avisa.
     */
    private static function exigirAutorizacionDelDescuento(Venta $venta, Usuario $usuario, float $descuento): void
    {
        if ($usuario->tienePermiso('ventas.descuento')) {
            return;
        }

        $base = (int) round((float) ($venta->impuesto_incluido ? $venta->total : $venta->subtotal) * 100);
        $centavos = (int) round($descuento * 100);
        $umbral = (int) Config::get('descuento_max_cajero', '0');

        // En centavos enteros: en coma flotante, 10,89 sobre 108,90 daba
        // 10,000000000000002 % y el descuento de exactamente el máximo se rechazaba.
        if ($centavos * 100 > $umbral * $base) {
            $porcentaje = $base > 0 ? $centavos / $base * 100 : 0;

            throw new RuntimeException('Un descuento del '.round($porcentaje, 1).'% supera el máximo de '.$umbral.
                '% permitido sin autorización. Pide a un administrador que registre el cobro.');
        }
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
     * @param  bool  $desdePedido  las líneas vienen de un pedido ya tomado
     */
    private static function agregarLineas(Venta $venta, array $lineas, bool $desdePedido = false): void
    {
        // Un ítem, una línea. El mostrador ya las agrupa; esto cubre a los
        // demás llamadores (scripts, pruebas), y es lo que exige el índice
        // único `uq_detalle_venta_producto` de la base.
        $productos = array_column($lineas, 'producto_id');

        if (count($productos) !== count(array_unique($productos))) {
            throw new RuntimeException('La venta repite el mismo ítem en dos líneas: júntalas en una sola con la cantidad total.');
        }

        foreach ($lineas as $linea) {
            $producto = Producto::findOrFail($linea['producto_id']);

            // Lo que viene de un pedido ya se validó al pedirlo: volver a
            // cobrarlo (tras anular su venta) no puede fallar porque el plato
            // salió del menú en el medio. El pedido quedaría sin forma de
            // cobrarse y sin forma de cancelarse.
            if (! $producto->activo && ! $desdePedido) {
                throw new RuntimeException("«{$producto->nombre}» ya no está en el menú y no se puede vender.");
            }

            $cantidad = (float) $linea['cantidad'];

            if ($cantidad <= 0) {
                throw new RuntimeException("La cantidad de «{$producto->nombre}» debe ser mayor que cero.");
            }

            // Siempre entera: en un restaurante todo se despacha por porción,
            // y media hamburguesa no se sirve. Antes lo decidía la unidad de
            // medida del producto, que el negocio ya no lleva.
            if (fmod($cantidad, 1.0) !== 0.0) {
                throw new RuntimeException("«{$producto->nombre}» se vende por porción entera.");
            }

            $datos = [
                'venta_id' => $venta->id,
                'producto_id' => $producto->id,
                // Copia histórica: la venta no cambia si mañana cambia el menú.
                'descripcion' => $producto->nombre,
                'cantidad' => $cantidad,
                // `precio_unitario` explícito es para llamadores de confianza
                // (pruebas, scripts internos): PosController, el único que
                // recibe pedidos por HTTP, nunca lo manda —lo quitó de sus
                // reglas de validación a propósito— así que el navegador jamás
                // decide el precio de una venta real.
                'precio_unitario' => $linea['precio_unitario'] ?? $producto->precio_venta,
                // Sin descuento por línea: el descuento es de la venta entera.
            ];

            // Sin triggers en la base, el régimen de impuesto lo copia PHP
            // (ver config/ventas.php).
            ReglasEnPhp::activa()
                ? VentaDetalle::create(ReglasEnPhp::antesDeInsertarLineaVenta($datos))
                : VentaDetalle::create($datos);
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
            } elseif ($recibido !== null && round($recibido - $monto, 2) >= self::billeteMayor()) {
                // Un vuelto igual o mayor al billete más grande quiere decir
                // que sobraba un billete entero: casi siempre es un cero de
                // más al teclear, y el ticket imprimía un vuelto absurdo.
                throw new RuntimeException(sprintf(
                    'El efectivo recibido (%s) deja un vuelto de %s: revisa lo que tecleaste.',
                    Config::importe($recibido),
                    Config::importe($recibido - $monto),
                ));
            }

            $referencia = trim((string) ($pago['referencia'] ?? ''));

            if ($referencia === '' && $metodo->requiereReferencia()) {
                throw new RuntimeException(
                    "Falta el número de operación del pago con {$metodo->nombre}: sin él no se puede conciliar con el banco."
                );
            }

            VentaPago::create([
                'venta_id' => $venta->id,
                'metodo_pago_id' => $metodo->id,
                'monto' => $monto,
                'monto_recibido' => $recibido,
                'referencia' => $referencia !== '' ? $referencia : $cobro?->referencia_bancaria,
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

    /** El billete más grande que circula: un vuelto así ya no tiene sentido. */
    public static function billeteMayor(): float
    {
        return max(1.0, (float) config('ventas.billete_mayor', 200));
    }

    /**
     * La serie a usar: la serie por omisión del tipo de documento que le toca
     * al cliente (`tipos_comprobante.serie_por_omision_id`). Un solo
     * mecanismo para los tres tipos.
     */
    public static function seriePara(?Cliente $cliente): SerieComprobante
    {
        // Sin facturación a la vista no se emiten facturas: todo sale como
        // recibo aunque el cliente tenga NIT. La excepción es la empresa: el
        // recibo es solo para personas naturales, así que a ella le toca la
        // nota de venta, que vale para cualquiera y tampoco lleva impuesto.
        $codigo = match (true) {
            ! Config::facturacionVisible() && (bool) $cliente?->esJuridica() => 'NV',
            Config::facturacionVisible() && (bool) $cliente?->llevaFactura() => 'FAC',
            default => 'REC',
        };

        $tipo = TipoComprobante::with('seriePorOmision.tipo')->where('codigo', $codigo)->first();
        $serie = $tipo?->seriePorOmision;

        if (! $serie?->activo) {
            throw new RuntimeException(sprintf(
                'No hay una serie activa para %s: elígela en Sistema → Configuración.',
                mb_strtolower($tipo->nombre ?? $codigo).($codigo === 'NV' ? ' (la que se le emite a una empresa sin facturación)' : ''),
            ));
        }

        return $serie;
    }

    /**
     * Anular deja el documento anulado, conservando el correlativo. La venta
     * no se borra nunca (RNF6).
     *
     * Si la venta cobró un pedido, el pedido queda para volver a cobrar,
     * con su número y sus platos, para cobrarlo de nuevo o cancelarlo
     * (`Pedidos::reabrirTrasAnular`). Se hace aquí y no en `sp_anular_venta`
     * para que valga igual con y sin los procedimientos de la base.
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
        // anulación que lleguen casi al mismo tiempo para la misma venta se
        // serializan: la segunda espera, y al retomar ya ve el nuevo estado.
        //
        // sp_anular_venta ya escribe su propia entrada en `auditoria`.
        DB::transaction(function () use ($venta, $usuario, $motivo) {
            // El turno bloqueado: una anulación y el cierre de esa caja no
            // pueden cruzarse, y lo que se decidió en pantalla se vuelve a
            // comprobar aquí.
            $turno = SesionCaja::whereKey($venta->sesion_caja_id)->lockForUpdate()->first();

            if ($turno?->estado !== 'ABIERTA') {
                throw new RuntimeException('El turno de caja de esta venta ya cerró, así que no se puede anular: ese dinero ya se contó en su arqueo.');
            }

            ReglasEnPhp::activa()
                ? ReglasEnPhp::anularVenta($venta->id, $usuario->id, $motivo)
                : DB::statement('CALL sp_anular_venta(?, ?, ?)', [$venta->id, $usuario->id, $motivo]);

            Pedidos::reabrirTrasAnular($venta, $usuario, $motivo);
        });

        return $venta->fresh();
    }
}
