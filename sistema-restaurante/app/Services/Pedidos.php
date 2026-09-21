<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Support\Config;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Los pedidos: la venta del mostrador (tomar el pedido y cobrarlo en el mismo
 * acto), su paso por la cocina, y la corrección —el pedido que se reabre al
 * anular su venta, para cobrarlo de nuevo con su número o cancelarlo—.
 *
 * Cobrar NO es un circuito de dinero aparte. El pedido se traduce a una venta
 * normal con `Ventas::registrar()`, que ya resuelve pagos mixtos, cobro por
 * QR, descuento, comprobante, arqueo y auditoría por sus dos vías
 * (procedimientos de la base o `ReglasEnPhp`). Duplicar eso aquí habría creado
 * una segunda fuente de verdad para el dinero, que es justo lo que el proyecto
 * evita.
 */
class Pedidos
{
    /**
     * Intentos antes de darse por vencido cuando dos cajas se pelean por el
     * mismo número del día. Mismo criterio —y mismo número— que
     * `Ventas::REINTENTOS` ante un deadlock.
     */
    private const REINTENTOS = 3;

    /**
     * Abre un pedido, para comer aquí o para llevar, con el número que le toca
     * en la jornada.
     *
     * Quien garantiza que dos pedidos abiertos en el mismo segundo no saquen
     * el mismo «pedido 7» es `uq_pedido_numero_dia`, no el SELECT que busca el
     * siguiente.
     *
     * Lo usa `venderEnMostrador()`, que lo cobra en el mismo acto: no hay
     * pantalla que abra un pedido para cobrarlo después.
     *
     * No lleva cliente: el cliente es de la venta. Al pedido le basta un
     * nombre para llamarlo.
     */
    public static function abrir(
        string $tipo,
        Usuario $usuario,
        ?string $nombreCliente = null,
        ?string $observacion = null,
    ): Pedido {
        if (! in_array($tipo, Pedido::TIPOS, true)) {
            throw new RuntimeException('Elige si el pedido es para comer aquí o para llevar.');
        }

        $apertura = now();
        // La jornada y no el día de calendario: el local cierra pasada la
        // medianoche, y el pedido de la 01:30 sigue la numeración de la noche
        // (ver `Config::jornadaDe`). Se calcula una vez: el reintento de abajo
        // tiene que caer en la misma jornada que el primer intento.
        $jornada = Config::jornadaDe($apertura);

        // El número y la fila se graban en la misma transacción: entre tomar
        // el siguiente y escribirlo no puede colarse nadie. Si aun así choca
        // —el bloqueo lo impide, pero el índice es la última palabra— se
        // vuelve a intentar con el número que corresponda ahora, igual que
        // `Ventas::registrar()` reintenta ante un deadlock.
        for ($intento = 1; ; $intento++) {
            try {
                $pedido = DB::transaction(fn () => Pedido::create([
                    'tipo' => $tipo,
                    'jornada' => $jornada,
                    'numero_dia' => self::siguienteNumeroDelDia($jornada),
                    'usuario_id' => $usuario->id,
                    'nombre_cliente' => $nombreCliente,
                    'estado' => Pedido::ABIERTO,
                    'observacion' => $observacion,
                    'fecha_apertura' => $apertura,
                ]), self::REINTENTOS);

                break;
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'uq_pedido_numero_dia') && $intento < self::REINTENTOS) {
                    continue;
                }

                throw $e;
            }
        }

        Auditor::registrar('PEDIDO_ABIERTO', 'pedidos', $pedido->id, [
            'tipo' => $tipo,
            'numero' => $pedido->numero_dia,
        ], $usuario->id);

        return $pedido;
    }

    /**
     * El número que le toca al próximo pedido de esa jornada: 1 el primero, y
     * vuelta a empezar en la jornada siguiente.
     *
     * `FOR UPDATE` y no un SELECT a secas: es el mismo criterio de
     * `sp_siguiente_comprobante`, que toma el correlativo con la fila
     * bloqueada. El bloqueo cae sobre el tramo de `uq_pedido_numero_dia` que
     * corresponde a esa jornada —el índice empieza por `jornada`—, así que el
     * segundo pedido espera a que el primero termine y lee un máximo que ya lo
     * incluye. Tiene que correr DENTRO de una transacción, o el bloqueo se
     * suelta en la misma sentencia y no sirve de nada.
     *
     * Un solo contador para toda la jornada, para comer aquí o para llevar: el
     * ticket ya dice cuál es cuál, y dos numeraciones en paralelo harían que
     * en el mismo turno convivan dos «pedido 7».
     */
    private static function siguienteNumeroDelDia(string $jornada): int
    {
        return (int) DB::selectOne(
            'SELECT COALESCE(MAX(numero_dia), 0) + 1 AS siguiente FROM pedidos WHERE jornada = ? FOR UPDATE',
            [$jornada],
            // Sin la réplica de lectura: un bloqueo sobre una copia no bloquea
            // nada. Dentro de la transacción Laravel ya usaría la conexión de
            // escritura, pero esto no depende de ese detalle.
            false,
        )->siguiente;
    }

    /**
     * Agrega un plato al pedido.
     *
     * El precio se copia ahora: si el menú sube a media tarde, el cliente
     * paga lo que se le cantó al pedir. Y también si pasa por la cocina, que
     * sale de su categoría (`categorias.pasa_por_cocina`): lo que ya se pidió
     * no aparece ni desaparece de la cocina porque alguien cambie después la
     * categoría.
     *
     * `$auditar` en falso es para la venta de mostrador, que graba todas las
     * líneas de una vez y deja su rastro en la venta y en el pedido cobrado:
     * una fila de bitácora por plato, además, solo tapaba las que importan.
     */
    public static function agregarLinea(
        Pedido $pedido,
        Producto $producto,
        float $cantidad,
        ?string $nota,
        Usuario $usuario,
        bool $auditar = true,
    ): PedidoDetalle {
        if (! $producto->activo) {
            throw new RuntimeException("«{$producto->nombre}» no está en el menú.");
        }

        if ($cantidad <= 0) {
            throw new RuntimeException("La cantidad de «{$producto->nombre}» debe ser mayor que cero.");
        }

        // Todo se despacha por porción: media hamburguesa no se sirve ni se
        // cobra. Antes lo decidía la unidad de medida del producto, que el
        // restaurante ya no lleva.
        if (fmod($cantidad, 1.0) !== 0.0) {
            throw new RuntimeException("«{$producto->nombre}» se pide por porción entera.");
        }

        // Con el pedido bloqueado: entre leer su estado y grabar la línea cabe
        // su cobro, y el plato entraría en un pedido ya cobrado.
        // El trigger de la base rechaza el INSERT igualmente, pero con
        // LOGICA_EN_PHP=true ese trigger no existe y esto es la única defensa.
        return DB::transaction(function () use ($pedido, $producto, $cantidad, $nota, $usuario, $auditar) {
            $actual = Pedido::whereKey($pedido->id)->lockForUpdate()->first();

            if (! $actual?->estaAbierto()) {
                throw new RuntimeException('Ese pedido ya no está abierto: no admite más platos.');
            }

            $linea = PedidoDetalle::create([
                'pedido_id' => $actual->id,
                'producto_id' => $producto->id,
                // Copia histórica del nombre, igual que en `venta_detalle`.
                'descripcion' => $producto->nombre,
                'cantidad' => $cantidad,
                'precio_unitario' => $producto->precio_venta,
                'nota' => blank($nota) ? null : trim($nota),
                // Una bebida sale de la heladera, no de la cocina: no va a su
                // pantalla ni a la comanda, pero se cobra igual.
                'pasa_por_cocina' => $producto->categoria?->pasa_por_cocina ?? true,
                'estado_cocina' => PedidoDetalle::PENDIENTE,
                'usuario_id' => $usuario->id,
            ]);

            if ($auditar) {
                Auditor::registrar('PEDIDO_LINEA_AGREGADA', 'pedido_detalle', $linea->id, [
                    'pedido_id' => $actual->id,
                    'plato' => $producto->nombre,
                    'cantidad' => $cantidad,
                ], $usuario->id);
            }

            return $linea;
        });
    }

    /**
     * Mueve una línea por la cocina, respetando `PedidoDetalle::TRANSICIONES`.
     *
     * No se admite volver atrás: si la cocina se adelantó, se cancela el plato
     * y se pide de nuevo, y así queda escrito lo que pasó.
     *
     * Cobrar no es servir: el pedido del mostrador se cobra al pedirlo, así
     * que la cocina sigue avanzando los platos de un pedido cobrado. Lo que ya
     * no se puede es cancelarlos —el dinero ya entró, y cancelar no lo
     * devuelve—: eso es anular la venta, que deja el pedido para volver a cobrar.
     *
     * Cancelar no es un paso de la cocina: la cocina solo avanza. Cancelar un
     * plato es dejarlo sin cobrar, así que pide `pedidos.registrar`; y si la
     * cocina ya lo empezó, además `ventas.anular` y un motivo, el mismo
     * criterio que `cancelar()` para el pedido entero. Sin esto, el cajero
     * cancelaba primero el plato empezado —que así dejaba de contar en
     * `Pedido::platosEmpezados()`— y después el pedido, sin administrador.
     */
    public static function actualizarEstadoLinea(PedidoDetalle $linea, string $estado, Usuario $usuario, ?string $motivo = null): PedidoDetalle
    {
        if (! array_key_exists($estado, PedidoDetalle::TRANSICIONES)) {
            throw new RuntimeException('Ese estado de cocina no existe.');
        }

        $motivo = blank($motivo) ? null : trim($motivo);

        return DB::transaction(function () use ($linea, $estado, $usuario, $motivo) {
            // El pedido con candado compartido: la cocina y la caja pueden
            // mover platos a la vez, pero un cobro en curso los hace esperar.
            // Sin esto, un plato cancelado en el mismo instante del cobro
            // quedaba cancelado y cobrado.
            $pedido = Pedido::whereKey($linea->pedido_id)->sharedLock()->first();
            // La línea, con candado propio y leída de nuevo: `fresh()` sin
            // candado devolvía, en REPEATABLE READ, la foto del inicio de la
            // transacción. La caja cancelaba un plato «pendiente» que la cocina
            // acababa de empezar, sin administrador ni motivo, y un avance de
            // la cocina revivía un plato recién cancelado.
            $linea = PedidoDetalle::whereKey($linea->id)->lockForUpdate()->first() ?? $linea;

            if (! $pedido || $pedido->estado === Pedido::CANCELADO) {
                throw new RuntimeException('Ese pedido se canceló: su preparación ya no se puede cambiar.');
            }

            if ($estado === $linea->estado_cocina) {
                return $linea;
            }

            if ($estado === PedidoDetalle::CANCELADO && ! $pedido->estaAbierto()) {
                throw new RuntimeException(sprintf(
                    'Ese pedido ya se cobró: «%s» no se cancela, porque el cliente ya lo pagó. Si no se va a servir, hay que anular la venta.',
                    $linea->descripcion,
                ));
            }

            if (! $linea->puedePasarA($estado)) {
                throw new RuntimeException(sprintf(
                    '«%s» está %s y no puede pasar a %s.',
                    $linea->descripcion,
                    mb_strtolower($linea->estado_visible),
                    mb_strtolower(self::enPalabras($estado)),
                ));
            }

            // Con la línea releída bajo el candado: la cocina puede estar
            // empezándola justo ahora.
            if ($estado === PedidoDetalle::CANCELADO) {
                self::autorizarCancelacion($linea, $usuario, $motivo);
            }

            $anterior = $linea->estado_cocina;

            $linea->update(['estado_cocina' => $estado, 'actualizado_por' => $usuario->id]);

            Auditor::registrar('PEDIDO_LINEA_ESTADO', 'pedido_detalle', $linea->id, [
                'pedido_id' => $linea->pedido_id,
                'plato' => $linea->descripcion,
                'de' => $anterior,
                'a' => $estado,
                'pedido' => $pedido->estado,
            ] + ($motivo !== null ? ['motivo' => $motivo] : []), $usuario->id);

            return $linea->fresh();
        });
    }

    /**
     * Quién puede cancelar un plato: la caja si la cocina no lo tocó; si ya lo
     * está preparando, solo quien puede anular una venta, y diciendo por qué.
     */
    private static function autorizarCancelacion(PedidoDetalle $linea, Usuario $usuario, ?string $motivo): void
    {
        if (! $usuario->tienePermiso('pedidos.registrar')) {
            throw new RuntimeException('Cancelar un plato se hace desde la caja: la cocina solo avanza la preparación.');
        }

        if ($linea->estado_cocina !== PedidoDetalle::EN_PREPARACION) {
            return;
        }

        if (! $usuario->tienePermiso('ventas.anular')) {
            throw new RuntimeException(sprintf(
                'La cocina ya está preparando «%s»: cancelarlo es dejar sin cobrar lo que ya empezó, así que solo lo cancela un administrador, con su motivo.',
                $linea->descripcion,
            ));
        }

        if ($motivo === null) {
            throw new RuntimeException(sprintf(
                'La cocina ya está preparando «%s»: escribe por qué se cancela.',
                $linea->descripcion,
            ));
        }
    }

    /**
     * Entrega de un toque todo lo que la cocina dejó LISTO en un pedido: quien
     * lleva los platos canta el número, el cliente muestra su ticket y se lo
     * lleva entero. Cada plato pasa por `actualizarEstadoLinea`, con sus
     * reglas y su rastro, como si se hubiera tocado uno por uno.
     *
     * @return int cuántos platos se entregaron
     */
    public static function entregarLoListo(Pedido $pedido, Usuario $usuario): int
    {
        return self::avanzarTodo($pedido, PedidoDetalle::ENTREGADO, $usuario);
    }

    /**
     * El botón del pedido en la pantalla de la cocina: lleva de un toque todos
     * sus platos al estado indicado, en vez de tocarlos uno por uno.
     *
     *   EN_PREPARACION  «Empezar»: lo que estaba pendiente.
     *   LISTO           «Listo»: lo pendiente y lo que se estaba preparando
     *                   (un plato rápido pasa de pendiente a listo sin más).
     *   ENTREGADO       «Entregado»: lo que estaba listo.
     *
     * Solo lo que pasa por la cocina, y cada plato por `actualizarEstadoLinea`,
     * con sus reglas y su rastro: es lo mismo que tocarlos uno por uno.
     *
     * @return int cuántos platos avanzaron
     */
    public static function avanzarTodo(Pedido $pedido, string $estado, Usuario $usuario): int
    {
        $desde = match ($estado) {
            PedidoDetalle::EN_PREPARACION => [PedidoDetalle::PENDIENTE],
            PedidoDetalle::LISTO => [PedidoDetalle::PENDIENTE, PedidoDetalle::EN_PREPARACION],
            PedidoDetalle::ENTREGADO => [PedidoDetalle::LISTO],
            default => throw new RuntimeException('La cocina solo avanza la preparación: cancelar un plato se hace desde la caja.'),
        };

        return DB::transaction(function () use ($pedido, $estado, $desde, $usuario) {
            // Mismo orden de candados que `actualizarEstadoLinea` (el pedido,
            // después sus líneas), y las líneas leídas con candado: lo que la
            // caja canceló mientras tanto ya no está en la lista y no revive.
            Pedido::whereKey($pedido->id)->sharedLock()->first();

            $lineas = $pedido->detalle()
                ->paraLaCocina()
                ->whereIn('estado_cocina', $desde)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($lineas as $linea) {
                self::actualizarEstadoLinea($linea, $estado, $usuario);
            }

            return $lineas->count();
        });
    }

    /**
     * Cancela un pedido con el cobro anulado —uno que se reabrió al anular su venta—,
     * con su motivo: el cliente se fue, o ya no lo quiere.
     *
     * Si la cocina ya empezó, terminó o sirvió algo, cancelar es dejar sin
     * cobrar lo consumido: la misma pérdida que anular una venta ya cobrada, y
     * por eso pide el mismo permiso (`ventas.anular`). Sin esto, la regla por
     * plato —lo empezado no se cancela sin más— se saltaba cancelando el
     * pedido entero (C1).
     */
    public static function cancelar(Pedido $pedido, Usuario $usuario, string $motivo): Pedido
    {
        if (blank($motivo)) {
            throw new RuntimeException('Explica por qué se cancela el pedido.');
        }

        return DB::transaction(function () use ($pedido, $usuario, $motivo) {
            $actual = Pedido::whereKey($pedido->id)->lockForUpdate()->first();

            if (! $actual?->estaAbierto()) {
                throw new RuntimeException('Ese pedido ya no está abierto.');
            }

            // Contado con el pedido bloqueado: la cocina puede estar
            // empezando un plato justo ahora.
            $empezados = $actual->platosEmpezados();

            if ($empezados > 0 && ! $usuario->tienePermiso('ventas.anular')) {
                throw new RuntimeException(
                    'Este pedido tiene platos que la cocina ya empezó o que ya se entregaron: cancelarlo es dejarlos sin cobrar, '
                    .'así que solo lo cancela un administrador. Cóbralo, o pídele que lo cancele con su motivo.'
                );
            }

            $actual->update([
                'estado' => Pedido::CANCELADO,
                'motivo_cancelacion' => $motivo,
                // Cancelado también es cerrado: la base exige que un pedido
                // que no está ABIERTO tenga fecha de cierre.
                'fecha_cierre' => now(),
                'cerrado_por' => $usuario->id,
            ]);

            Auditor::registrar('PEDIDO_CANCELADO', 'pedidos', $actual->id, [
                'motivo' => $motivo,
                'numero' => $actual->numero_dia,
                'platos_empezados' => $empezados,
            ], $usuario->id);

            return $actual->fresh();
        });
    }

    /**
     * La venta del mostrador: toma el pedido y lo cobra en el mismo acto.
     *
     * Es el flujo principal del local —el cliente pide y paga en la caja,
     * recibe su ticket con el número y espera su plato—, y por eso NO registra
     * la venta suelta con `Ventas::registrar()`: abre un pedido con su número
     * de la jornada, le carga las líneas y lo cobra con `cobrar()`, que a su
     * vez usa `Ventas::registrar()`. Así la cocina ve los platos, el ticket
     * lleva el número que se canta al entregar, y el dinero sigue pasando por
     * un solo camino: descuento, pagos mixtos, cobro por QR, el control del
     * total esperado y el comprobante son los de siempre.
     *
     * Todo en UNA transacción: o queda el pedido cobrado con su venta y su
     * comprobante, o no queda nada —ni un pedido abierto huérfano, ni un
     * número de la jornada gastado—. Ante un deadlock se reintenta entero,
     * igual que `Ventas::registrar()` (dentro de esta transacción la suya ya
     * no reintenta: el reintento es este).
     *
     * @param  array<int, array{producto_id: int|string, cantidad: float|int|string, nota?: string|null}>  $lineas
     * @param  array<int, array{metodo_pago_id: int, monto?: float|null, monto_recibido?: float|null, referencia?: string|null, cobro_qr_id?: int|null}>  $pagos
     */
    public static function venderEnMostrador(
        SesionCaja $sesion,
        Usuario $usuario,
        array $lineas,
        array $pagos,
        string $tipo = Pedido::LOCAL,
        ?Cliente $cliente = null,
        ?string $nombreCliente = null,
        float $descuento = 0,
        ?string $observacion = null,
        ?float $totalEsperado = null,
    ): Venta {
        if ($lineas === []) {
            throw new RuntimeException('La venta no tiene nada que cobrar.');
        }

        if (! in_array($tipo, Pedido::TIPOS, true)) {
            throw new RuntimeException('Elige si el pedido es para comer aquí o para llevar.');
        }

        if (! $sesion->estaAbierta()) {
            throw new RuntimeException('No hay una caja abierta para registrar la venta.');
        }

        return DB::transaction(function () use ($sesion, $usuario, $lineas, $pagos, $tipo, $cliente, $nombreCliente, $descuento, $observacion, $totalEsperado) {
            $pedido = self::abrir(
                tipo: $tipo,
                usuario: $usuario,
                nombreCliente: blank($nombreCliente) ? null : trim($nombreCliente),
                observacion: $observacion,
            );

            $productos = Producto::with('categoria')
                ->whereIn('id', array_column($lineas, 'producto_id'))
                ->get()
                ->keyBy('id');

            foreach ($lineas as $linea) {
                $producto = $productos[(int) $linea['producto_id']] ?? null;

                if (! $producto) {
                    throw new RuntimeException('Uno de los platos ya no existe en el menú.');
                }

                self::agregarLinea(
                    pedido: $pedido,
                    producto: $producto,
                    cantidad: (float) $linea['cantidad'],
                    nota: $linea['nota'] ?? null,
                    usuario: $usuario,
                    auditar: false,
                );
            }

            return self::cobrar(
                pedido: $pedido,
                sesion: $sesion,
                usuario: $usuario,
                pagos: $pagos,
                descuento: $descuento,
                observacion: $observacion,
                totalEsperado: $totalEsperado,
                cliente: $cliente,
            );
        }, self::REINTENTOS);
    }

    /**
     * Cobra el pedido: lo traduce a una venta normal y lo cierra.
     *
     * Lo usan la venta del mostrador, en el mismo acto en que se toma el
     * pedido, y la corrección: el pedido que se reabrió al anular su venta se
     * vuelve a cobrar aquí, con su mismo número.
     *
     * Las líneas se agrupan por producto antes de pasarlas a
     * `Ventas::registrar()`, que exige una línea por producto (índice único
     * `uq_detalle_venta_producto`). El precio de cada agrupado sale de lo que
     * de verdad se sirvió —`SUM(importe) / SUM(cantidad)`— y no del menú:
     * si dos tandas del mismo plato se pidieron a precios distintos, el total
     * cobrado sigue siendo exactamente la suma de las líneas.
     *
     * El cliente del comprobante es el que se indique; si no se indica y el
     * pedido ya se cobró antes —se reabrió al anular su venta—, el de esa
     * última venta anulada (`clienteDelCobroAnulado`). Así una venta que se
     * pasó a factura por sustitución, anulada y vuelta a cobrar, sale otra vez
     * a nombre de la empresa y no como recibo al cliente de la primera vez.
     *
     * @param  array<int, array{metodo_pago_id: int, monto?: float|null, monto_recibido?: float|null, referencia?: string|null, cobro_qr_id?: int|null}>  $pagos
     */
    public static function cobrar(
        Pedido $pedido,
        SesionCaja $sesion,
        Usuario $usuario,
        array $pagos,
        float $descuento = 0,
        ?string $observacion = null,
        ?float $totalEsperado = null,
        ?Cliente $cliente = null,
    ): Venta {
        if (! $sesion->estaAbierta()) {
            throw new RuntimeException('No hay una caja abierta para cobrar el pedido.');
        }

        // Reintenta ante un deadlock, como la venta suelta, solo si es la
        // transacción de más afuera: dentro de otra, reintentar no sirve.
        return DB::transaction(function () use ($pedido, $sesion, $usuario, $pagos, $descuento, $observacion, $totalEsperado, $cliente) {
            // El pedido bloqueado y su estado revisado DENTRO de la
            // transacción: es lo que cierra la carrera con alguien que agrega
            // un plato justo mientras el cajero cobra. Sin esto, la línea
            // entraba después de leído el detalle y se servía sin cobrarse.
            $actual = Pedido::whereKey($pedido->id)->lockForUpdate()->first();

            if (! $actual) {
                throw new RuntimeException('Ese pedido ya no existe.');
            }

            if (! $actual->estaAbierto()) {
                throw new RuntimeException($actual->estado === Pedido::CERRADO
                    ? 'Ese pedido ya se cobró.'
                    : 'Ese pedido está cancelado: no se puede cobrar.');
            }

            $lineas = self::lineasAgrupadas($actual);

            // La venta graba su pedido, y el índice único de
            // `ventas.pedido_cobrado_uk` remata la guarda: dos cajeros que
            // pulsen a la vez no pueden dejar dos ventas vigentes del mismo pedido.
            $venta = Ventas::registrar(
                sesion: $sesion,
                usuario: $usuario,
                lineas: $lineas,
                pagos: $pagos,
                cliente: $cliente ?? self::clienteDelCobroAnulado($actual),
                desdePedido: true,
                descuento: $descuento,
                observacion: $observacion ?? $actual->observacion,
                totalEsperado: $totalEsperado,
                pedido: $actual,
            );

            // En la misma transacción que la venta: CERRADO es tener una venta
            // COMPLETADA, y eso ya no lo puede exigir un CHECK (son dos tablas).
            $actual->update([
                'estado' => Pedido::CERRADO,
                'fecha_cierre' => now(),
                'cerrado_por' => $usuario->id,
            ]);

            // Lo que no pasa por la cocina —la gaseosa, el agua— se entrega en
            // el mostrador con el ticket: queda ENTREGADO, y no «pendiente»
            // para siempre. Hasta el cobro se quedó PENDIENTE a propósito: en
            // un pedido abierto todavía se puede cancelar sin permiso especial
            // y no cuenta como consumido (`Pedido::platosEmpezados`). Salta de
            // PENDIENTE a ENTREGADO sin pasar por `TRANSICIONES`, que es el
            // camino de la cocina, y esto nunca pasó por ella.
            $entregados = PedidoDetalle::where('pedido_id', $actual->id)
                ->where('pasa_por_cocina', false)
                ->where('estado_cocina', PedidoDetalle::PENDIENTE)
                ->update(['estado_cocina' => PedidoDetalle::ENTREGADO, 'actualizado_por' => $usuario->id]);

            Auditor::registrar('PEDIDO_COBRADO', 'pedidos', $actual->id, [
                'venta_id' => $venta->id,
                'total' => $venta->fresh()->total,
                'lineas' => count($lineas),
                'entregado_sin_cocina' => $entregados,
                'numero' => $actual->numero_dia,
            ], $usuario->id);

            return $venta;
        }, DB::transactionLevel() === 0 ? self::REINTENTOS : 1);
    }

    /**
     * Deja para volver a cobrar el pedido de una venta que se acaba de anular.
     *
     * Es el camino de corrección, y por eso se queda aunque ya no haya cuentas
     * que se cobren después: el cajero cobró el pedido 7 con la forma de pago
     * equivocada y anula para rehacerlo. En ese momento el cliente ya tiene su
     * ticket con el 7 y la cocina ya está cocinando el 7. Si la anulación
     * cancelara el pedido y hubiera que cargarlo de nuevo en el mostrador,
     * saldría un «pedido 8» con los platos repetidos en la cocina. Así, en
     * cambio, vuelve a quedar ABIERTO con su número y sus líneas tal cual
     * —también lo que la cocina ya empezó o entregó, que sigue sin pagarse—,
     * sigue en la cocina, y se vuelve a cobrar (`cobrar()`) desde «Pedidos por
     * cobrar» del punto de venta, o se cancela (`cancelar()`, con C1).
     *
     * Corre dentro de la transacción de `Ventas::anular()`, con la venta ya
     * anulada. Se hace aquí y no en `sp_anular_venta` para que valga igual
     * con y sin los procedimientos de la base.
     *
     * @return bool|null null si la venta no salió de un pedido; true si se reabrió.
     */
    public static function reabrirTrasAnular(Venta $venta, Usuario $usuario, string $motivo): ?bool
    {
        // La venta anulada conserva su `pedido_id`: de qué pedido era sigue
        // escrito. Lo único que cambia es el estado del pedido.
        $pedido = $venta->pedido_id
            ? Pedido::whereKey($venta->pedido_id)->lockForUpdate()->first()
            : null;

        if (! $pedido) {
            return null;
        }

        $pedido->update([
            // Todo junto, por el CHECK de la tabla: abierto es no tener fecha de cierre.
            'estado' => Pedido::ABIERTO,
            'fecha_cierre' => null,
            'cerrado_por' => null,
        ]);

        Auditor::registrar('PEDIDO_REABIERTO', 'pedidos', $pedido->id, [
            'venta_anulada' => $venta->id,
            'motivo' => $motivo,
            'numero' => $pedido->numero_dia,
        ], $usuario->id);

        return true;
    }

    /**
     * A nombre de quién sale el comprobante al volver a cobrar un pedido: el
     * cliente de su última venta anulada, que es el que tenía el documento que
     * se anuló (si se sustituyó por una factura, el de la factura). Un pedido
     * que nunca se cobró no tiene ninguno.
     */
    public static function clienteDelCobroAnulado(Pedido $pedido): ?Cliente
    {
        return $pedido->ventas()
            ->where('estado', 'ANULADA')
            ->orderByDesc('id')
            ->first()
            ?->cliente;
    }

    /**
     * Lo que se va a cobrar, con las tandas del mismo plato ya juntas.
     *
     * Lo cancelado queda fuera: se le pidió a la cocina, puede estar a medio
     * hacer, pero al cliente no se le cobra.
     *
     * @return array<int, array{producto_id: int, cantidad: float, precio_unitario: float}>
     */
    public static function lineasAgrupadas(Pedido $pedido): array
    {
        $lineas = self::agrupar($pedido);

        if ($lineas === []) {
            throw new RuntimeException($pedido->detalle()->exists()
                ? 'Todos los platos de este pedido están cancelados: no hay nada que cobrar. Cancela el pedido.'
                : 'Este pedido está vacío: no hay nada que cobrar.');
        }

        return $lineas;
    }

    /**
     * Lo mismo, pero sin la negativa: un pedido sin nada devuelve un arreglo
     * vacío.
     *
     * La diferencia importa porque las dos preguntas son distintas. Cobrar un
     * pedido vacío es un error y tiene que doler; mostrarlo en una lista —«Volver
     * a cobrar», el cierre de caja—, no: vale cero, y hacer saltar la
     * excepción ahí dejaba la pantalla entera en blanco por un pedido sin platos.
     *
     * @return array<int, array{producto_id: int, cantidad: float, precio_unitario: float}>
     */
    private static function agrupar(Pedido $pedido): array
    {
        // Con el detalle ya cargado (las listas lo traen de antemano) no se
        // vuelve a consultar: era una consulta por pedido en el mostrador y en
        // el cierre de caja.
        $detalle = $pedido->relationLoaded('detalle') ? $pedido->detalle : $pedido->detalle()->get();

        return $detalle
            ->where('estado_cocina', '<>', PedidoDetalle::CANCELADO)
            ->groupBy('producto_id')
            ->map(fn ($grupo, $productoId) => [
                'producto_id' => (int) $productoId,
                'cantidad' => (float) $grupo->sum('cantidad'),
                // El precio que resulta de lo servido, no el del menú de
                // ahora: así el total de la venta cuadra al céntimo con la
                // suma de las líneas del pedido.
                'precio_unitario' => round(
                    (float) $grupo->sum('importe') / (float) $grupo->sum('cantidad'),
                    2,
                ),
            ])
            ->values()
            ->all();
    }

    /**
     * Lo que el pedido va a sumar, para mostrarlo antes de cobrar.
     *
     * Es una previsión, no la verdad: la cifra que vale es la que calcula
     * `sp_recalcular_venta` (o su réplica) al registrar la venta. Se calcula
     * línea por línea y con la misma redondeo que la columna generada
     * `venta_detalle.impuesto_linea`, así la pantalla y la venta coinciden.
     *
     * @return array{subtotal: float, impuesto: float, total: float}
     */
    public static function totalesDe(Pedido $pedido): array
    {
        $incluido = Config::preciosIncluyenImpuesto();
        $tasa = Config::tasaImpuesto();
        $lineas = self::agrupar($pedido);
        $afectos = self::afectos();

        $subtotal = 0.0;
        $impuesto = 0.0;

        foreach ($lineas as $linea) {
            $importe = round($linea['cantidad'] * $linea['precio_unitario'], 2);
            $subtotal += $importe;

            if (! ($afectos[$linea['producto_id']] ?? false)) {
                continue;
            }

            $impuesto += $incluido
                ? Config::impuestoDentroDe($importe, $tasa)
                : Config::impuestoDe($importe, $tasa);
        }

        return [
            'subtotal' => round($subtotal, 2),
            'impuesto' => round($impuesto, 2),
            'total' => round($incluido ? $subtotal : $subtotal + $impuesto, 2),
        ];
    }

    /**
     * El pedido como lo lee el cliente: cada línea y el total, con el impuesto
     * ya puesto.
     *
     * Hace falta porque las dos cifras de la pantalla vienen de sitios
     * distintos: el menú muestra el precio de estante —lo que el cliente
     * paga— y `pedido_detalle` guarda el precio base, que es el que
     * `Ventas::registrar()` espera al cobrar. Sin esta traducción, el cajero
     * veía un plato a Bs 10.90 en el menú y una cuenta de Bs 19.30 por dos.
     *
     * El total NO se suma de estos importes sino de `totalesDe()`, que agrupa
     * por plato igual que el cobro: así el redondeo del impuesto es el mismo
     * que el de la venta y la pantalla no promete un céntimo que después cambia.
     *
     * @return array{importes: array<int, float>, total: float}
     */
    public static function cuentaDe(Pedido $pedido): array
    {
        $incluido = Config::preciosIncluyenImpuesto();
        $tasa = Config::tasaImpuesto();
        $lineas = $pedido->detalle;
        $afectos = self::afectos();

        $importes = [];

        foreach ($lineas as $linea) {
            $base = (float) $linea->importe;
            $afecto = (bool) ($afectos[$linea->producto_id] ?? false);

            $importes[$linea->id] = $incluido || ! $afecto
                ? round($base, 2)
                : round($base + Config::impuestoDe($base, $tasa), 2);
        }

        return [
            'importes' => $importes,
            'total' => self::totalesDe($pedido)['total'],
        ];
    }

    /** El total del pedido con el impuesto puesto, para las listas de pedidos que se vuelven a cobrar. */
    public static function totalDe(Pedido $pedido): float
    {
        return self::totalesDe($pedido)['total'];
    }

    /**
     * Qué platos llevan impuesto, leído una sola vez por petición.
     *
     * El menú de un restaurante son decenas de filas, no miles, y las listas de
     * pedidos preguntan esto por cada uno: una consulta por pedido para releer
     * la misma tabla no tiene sentido. Mismo criterio que `App\Support\Config`.
     *
     * @return array<int, bool>
     */
    private static function afectos(): array
    {
        return self::$afectos ??= Producto::pluck('afecto_impuesto', 'id')
            ->map(fn ($afecto) => (bool) $afecto)
            ->all();
    }

    /** @var array<int, bool>|null */
    private static ?array $afectos = null;

    /**
     * Vuelve a leer qué platos llevan impuesto la próxima vez que se pregunte.
     *
     * En una petición normal no hace falta: la memoria muere con ella. En las
     * pruebas dura el proceso entero, y una que cambia el impuesto de un plato
     * le dejaba la lista vieja a la siguiente: el resultado dependía del orden.
     * Mismo criterio que `Config::olvidar()`.
     */
    public static function olvidar(): void
    {
        self::$afectos = null;
    }

    private static function enPalabras(string $estado): string
    {
        return match ($estado) {
            PedidoDetalle::PENDIENTE => 'Pendiente',
            PedidoDetalle::EN_PREPARACION => 'En preparación',
            PedidoDetalle::LISTO => 'Listo',
            PedidoDetalle::ENTREGADO => 'Entregado',
            PedidoDetalle::CANCELADO => 'Cancelado',
            default => $estado,
        };
    }
}
