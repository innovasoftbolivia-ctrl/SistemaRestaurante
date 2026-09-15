<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Las reglas que normalmente ejecuta la BASE, hechas en PHP.
 *
 * Réplica de los 6 procedimientos almacenados y los 7 triggers de
 * `docs/sql/01_schema_mysql.sql`, para poder correr el sistema en un hosting
 * que no permite crearlos (ver config/ventas.php).
 *
 * Reglas que se respetaron al portarlo, y que conviene no perder de vista si
 * alguien toca esto:
 *
 *   - Cada método hace lo MISMO y en el MISMO ORDEN que su equivalente en SQL.
 *     Donde el procedimiento bloquea una fila con `FOR UPDATE`, aquí se hace
 *     `lockForUpdate()`; donde corta con SIGNAL, aquí se lanza una excepción
 *     con el mismo texto, porque hay pantallas que muestran ese mensaje tal cual.
 *   - Todo esto asume que quien llama ya abrió una transacción. Los servicios
 *     lo hacen.
 *   - Las columnas generadas (importe, impuesto_linea, total, diferencia...)
 *     las sigue calculando la base: no se replican aquí.
 *
 * La equivalencia no se deja a la buena fe: la batería de pruebas completa
 * corre en los dos modos.
 */
class ReglasEnPhp
{
    public static function activa(): bool
    {
        return (bool) config('ventas.logica_en_php', false);
    }

    // =====================================================================
    //  TRIGGERS
    // =====================================================================

    /**
     * trg_venta_detalle_before_insert
     *
     * Copia del producto el régimen de impuesto y la tasa vigente. Si la
     * aplicación mandó una tasa explícita (> 0), esa manda.
     *
     * @param  array<string, mixed>  $linea
     * @return array<string, mixed>
     */
    public static function antesDeInsertarLineaVenta(array $linea): array
    {
        if ((float) ($linea['tasa_impuesto'] ?? 0) != 0) {
            return $linea;
        }

        $afecto = (bool) DB::table('productos')
            ->where('id', $linea['producto_id'])
            ->value('afecto_impuesto');

        $linea['afecto_impuesto'] = $afecto;
        $linea['tasa_impuesto'] = $afecto ? self::tasaImpuesto() : 0;

        return $linea;
    }

    /**
     * trg_venta_detalle_after_insert
     *
     * Valida el stock, lo descuenta y deja el movimiento en el kardex.
     */
    public static function despuesDeInsertarLineaVenta(int $ventaId, int $productoId, float $cantidad): void
    {
        $producto = DB::table('productos')->where('id', $productoId)->lockForUpdate()->first();

        if (! $producto || (float) $producto->stock_actual < $cantidad) {
            throw new RuntimeException('Stock insuficiente para el producto de la venta');
        }

        $anterior = (float) $producto->stock_actual;

        DB::table('productos')->where('id', $productoId)
            ->update(['stock_actual' => DB::raw('stock_actual - '.self::num($cantidad))]);

        DB::table('movimientos_inventario')->insert([
            'producto_id' => $productoId,
            'usuario_id' => DB::table('ventas')->where('id', $ventaId)->value('usuario_id'),
            'tipo' => 'SALIDA',
            'origen' => 'VENTA',
            'venta_id' => $ventaId,
            'cantidad' => $cantidad,
            'stock_anterior' => $anterior,
            'stock_resultante' => $anterior - $cantidad,
            'motivo' => 'Venta de productos',
            'fecha' => now(),
        ]);
    }

    /**
     * trg_devolucion_detalle_before_insert
     *
     * Copia el régimen de impuesto de la línea de venta original: la tasa de
     * hoy puede no ser la de aquel día, y al cliente se le devuelve lo que pagó.
     *
     * @param  array<string, mixed>  $linea
     * @return array<string, mixed>
     */
    public static function antesDeInsertarLineaDevolucion(array $linea): array
    {
        if ((float) ($linea['tasa_impuesto'] ?? 0) != 0) {
            return $linea;
        }

        $original = DB::table('venta_detalle')->where('id', $linea['venta_detalle_id'])->first();

        $afecto = (bool) ($original->afecto_impuesto ?? false);
        $linea['afecto_impuesto'] = $afecto;
        $linea['tasa_impuesto'] = $afecto ? (float) ($original->tasa_impuesto ?? 0) : 0;

        return $linea;
    }

    /**
     * trg_devolucion_detalle_after_insert
     *
     * Acumula lo devuelto en la línea original, recalcula el total de la
     * devolución y el acumulado de la venta, mueve el estado de la venta y
     * —si la mercadería vuelve al estante— reingresa el stock con su kardex.
     */
    public static function despuesDeInsertarLineaDevolucion(
        int $devolucionId,
        int $ventaDetalleId,
        int $productoId,
        float $cantidad,
        bool $reingresaStock,
    ): void {
        DB::table('venta_detalle')->where('id', $ventaDetalleId)
            ->update(['cantidad_devuelta' => DB::raw('cantidad_devuelta + '.self::num($cantidad))]);

        $ventaId = (int) DB::table('devoluciones')->where('id', $devolucionId)->value('venta_id');

        // Total de la devolución = suma de su detalle CON impuesto: es el dinero
        // que sale del cajón, comparable con `ventas.total`.
        DB::table('devoluciones')->where('id', $devolucionId)->update([
            'total' => DB::table('devolucion_detalle')
                ->where('devolucion_id', $devolucionId)
                ->sum('total_linea') ?: 0,
        ]);

        DB::table('ventas')->where('id', $ventaId)->update([
            'total_devuelto' => DB::table('devoluciones')->where('venta_id', $ventaId)->sum('total') ?: 0,
        ]);

        // DEVUELTA si ya no queda nada por devolver; si no, parcial. Una venta
        // ANULADA no cambia de estado.
        $pendientes = DB::table('venta_detalle')
            ->where('venta_id', $ventaId)
            ->whereColumn('cantidad_devuelta', '<', 'cantidad')
            ->count();

        DB::table('ventas')
            ->where('id', $ventaId)
            ->whereIn('estado', ['COMPLETADA', 'DEVUELTA_PARCIAL', 'DEVUELTA'])
            ->update(['estado' => $pendientes === 0 ? 'DEVUELTA' : 'DEVUELTA_PARCIAL']);

        if (! $reingresaStock) {
            return;
        }

        $producto = DB::table('productos')->where('id', $productoId)->lockForUpdate()->first();
        $anterior = (float) $producto->stock_actual;

        DB::table('productos')->where('id', $productoId)
            ->update(['stock_actual' => DB::raw('stock_actual + '.self::num($cantidad))]);

        DB::table('movimientos_inventario')->insert([
            'producto_id' => $productoId,
            'usuario_id' => DB::table('devoluciones')->where('id', $devolucionId)->value('usuario_id'),
            'tipo' => 'ENTRADA',
            'origen' => 'DEVOLUCION',
            'devolucion_id' => $devolucionId,
            'cantidad' => $cantidad,
            'stock_anterior' => $anterior,
            'stock_resultante' => $anterior + $cantidad,
            'motivo' => 'Devolución de cliente',
            'fecha' => now(),
        ]);
    }

    /**
     * trg_comprobantes_before_insert
     *
     * El documento tiene que corresponder al tipo de persona del cliente:
     * factura solo a jurídica, recibo solo a natural.
     *
     * @param  array<string, mixed>  $datos
     */
    public static function antesDeInsertarComprobante(array $datos): void
    {
        $tipo = DB::table('series_comprobante as s')
            ->join('tipos_comprobante as tc', 'tc.id', '=', 's.tipo_comprobante_id')
            ->where('s.id', $datos['serie_id'])
            ->select('tc.aplica_persona', 'tc.exige_cliente', 'tc.exige_documento')
            ->first();

        if (! $tipo) {
            throw new RuntimeException('La serie del comprobante no existe');
        }

        if ($tipo->exige_cliente && ($datos['cliente_id'] ?? null) === null) {
            throw new RuntimeException('Este tipo de comprobante exige un cliente registrado');
        }

        if ($tipo->exige_documento && blank($datos['cliente_documento'] ?? null)) {
            throw new RuntimeException('Este tipo de comprobante exige el documento del cliente');
        }

        if ($tipo->aplica_persona !== 'AMBAS'
            && ($datos['cliente_id'] ?? null) !== null
            && ($datos['tipo_persona'] ?? '') !== $tipo->aplica_persona) {
            throw new RuntimeException('El tipo de comprobante no corresponde al tipo de persona del cliente');
        }
    }

    /**
     * trg_empleados_after_update
     *
     * Al cesar o suspender a alguien, sus cuentas dejan de tener acceso.
     */
    public static function despuesDeActualizarEmpleado(int $empleadoId, string $estadoAnterior, string $estadoNuevo): void
    {
        if ($estadoAnterior === 'ACTIVO' && in_array($estadoNuevo, ['CESADO', 'SUSPENDIDO'], true)) {
            DB::table('usuarios')->where('empleado_id', $empleadoId)->update(['activo' => 0]);
        }
    }

    // =====================================================================
    //  PROCEDIMIENTOS
    // =====================================================================

    /**
     * sp_siguiente_comprobante — correlativo con bloqueo de fila.
     *
     * @return array{0: int, 1: string} número y número completo
     */
    public static function siguienteComprobante(int $serieId): array
    {
        $serie = DB::table('series_comprobante')->where('id', $serieId)->lockForUpdate()->first();

        if (! $serie) {
            throw new RuntimeException('La serie del comprobante no existe');
        }

        $numero = (int) $serie->correlativo_actual + 1;

        DB::table('series_comprobante')->where('id', $serieId)
            ->update(['correlativo_actual' => $numero]);

        return [$numero, $serie->serie.'-'.str_pad((string) $numero, (int) $serie->longitud, '0', STR_PAD_LEFT)];
    }

    /**
     * sp_recalcular_venta
     *
     * El precio de venta NO incluye impuesto. El descuento de cabecera se
     * prorratea sobre la base afecta, igual que en el procedimiento.
     */
    public static function recalcularVenta(int $ventaId): void
    {
        $totales = DB::table('venta_detalle')
            ->where('venta_id', $ventaId)
            ->selectRaw('IFNULL(SUM(importe),0) AS base, IFNULL(SUM(impuesto_linea),0) AS impuesto')
            ->first();

        $base = (float) $totales->base;
        $impuestoBruto = (float) $totales->impuesto;
        $descuento = (float) DB::table('ventas')->where('id', $ventaId)->value('descuento');

        if ($descuento > $base) {
            throw new RuntimeException('El descuento no puede superar el subtotal de la venta');
        }

        // En centavos enteros y en una sola cuenta, con redondeo a la mitad
        // hacia arriba: lo mismo que ROUND de MySQL en sp_recalcular_venta. En
        // coma flotante 1.89 × (2.42 / 14.52) daba 0.31 y no 0.32.
        $baseC = (int) round($base * 100);
        $brutoC = (int) round($impuestoBruto * 100);
        $descuentoC = (int) round($descuento * 100);
        $impuestoC = $baseC > 0 ? intdiv(2 * $brutoC * ($baseC - $descuentoC) + $baseC, 2 * $baseC) : 0;

        DB::table('ventas')->where('id', $ventaId)->update([
            'subtotal' => $base,
            'impuesto' => $impuestoC / 100,
        ]);
    }

    /**
     * sp_emitir_comprobante — toma el correlativo y congela los datos del
     * cliente y los importes en `comprobantes`.
     *
     * @return array{0: int, 1: string} id del comprobante y número completo
     */
    public static function emitirComprobante(int $ventaId, int $serieId): array
    {
        $venta = DB::table('ventas')->where('id', $ventaId)->lockForUpdate()->first();

        if (! $venta) {
            throw new RuntimeException('La venta no existe');
        }
        if ($venta->estado !== 'COMPLETADA') {
            throw new RuntimeException('Solo se emite comprobante de una venta COMPLETADA');
        }
        if (DB::table('comprobantes')->where('venta_id', $ventaId)->where('estado', 'EMITIDO')->exists()) {
            throw new RuntimeException('La venta ya tiene un comprobante vigente. Use sp_sustituir_comprobante.');
        }

        [$numero, $numeroCompleto] = self::siguienteComprobante($serieId);

        $cliente = $venta->cliente_id
            ? DB::table('clientes')->where('id', $venta->cliente_id)->first()
            : null;

        $datos = [
            'venta_id' => $ventaId,
            'serie_id' => $serieId,
            'numero' => $numero,
            'numero_completo' => $numeroCompleto,
            'cliente_id' => $cliente?->id,
            'tipo_persona' => $cliente?->tipo_persona,
            'cliente_nombre' => $cliente->nombre ?? self::config('cliente_generico_nombre', 'Cliente varios'),
            'cliente_tipo_documento' => $cliente->tipo_documento ?? 'SIN',
            'cliente_documento' => $cliente?->documento,
            'cliente_direccion' => $cliente?->direccion,
            'representante_legal' => $cliente?->representante_legal,
            'subtotal' => $venta->subtotal,
            'descuento' => $venta->descuento,
            'impuesto' => $venta->impuesto,
            'moneda' => self::config('moneda_codigo', 'BOB'),
            'emitido_por' => $venta->usuario_id,
            'fecha_emision' => now(),
        ];

        self::antesDeInsertarComprobante($datos);

        $id = (int) DB::table('comprobantes')->insertGetId($datos);

        return [$id, $numeroCompleto];
    }

    /**
     * sp_sustituir_comprobante — el anterior queda SUSTITUIDO y el nuevo lo
     * referencia. No se toca la venta ni el stock: solo cambia el documento.
     *
     * @return array{0: int, 1: string}
     */
    public static function sustituirComprobante(
        int $comprobanteId,
        int $serieId,
        ?int $clienteId,
        int $usuarioId,
        ?string $motivo,
    ): array {
        $comprobante = DB::table('comprobantes')->where('id', $comprobanteId)->lockForUpdate()->first();

        if (! $comprobante) {
            throw new RuntimeException('El comprobante no existe');
        }
        if ($comprobante->estado !== 'EMITIDO') {
            throw new RuntimeException('Solo se puede sustituir un comprobante vigente (EMITIDO)');
        }

        $venta = DB::table('ventas')->where('id', $comprobante->venta_id)->lockForUpdate()->first();

        if ($venta->estado !== 'COMPLETADA') {
            throw new RuntimeException('No se sustituye el comprobante de una venta anulada o devuelta');
        }

        $diasMax = (int) self::config('dias_max_sustitucion', '1');

        // Del día de la venta a hoy: al revés, Carbon 3 da días negativos y el
        // plazo nunca vencía en este modo.
        if (Carbon::parse($venta->fecha)->startOfDay()->diffInDays(now()->startOfDay()) > $diasMax) {
            throw new RuntimeException('La venta excede el plazo permitido para sustituir su comprobante');
        }

        if ($clienteId !== null) {
            DB::table('ventas')->where('id', $venta->id)->update(['cliente_id' => $clienteId]);
        }

        DB::table('comprobantes')->where('id', $comprobanteId)->update([
            'estado' => 'SUSTITUIDO',
            'sustituido_en' => now(),
        ]);

        [$nuevoId, $numeroCompleto] = self::emitirComprobante((int) $venta->id, $serieId);

        DB::table('comprobantes')->where('id', $nuevoId)->update([
            'sustituye_a' => $comprobanteId,
            'motivo_emision' => $motivo,
            'emitido_por' => $usuarioId,
        ]);

        DB::table('auditoria')->insert([
            'usuario_id' => $usuarioId,
            'accion' => 'SUSTITUIR_COMPROBANTE',
            'entidad' => 'comprobantes',
            'entidad_id' => $nuevoId,
            'detalle' => json_encode([
                'venta_id' => $venta->id,
                'sustituye_a' => $comprobanteId,
                'anterior' => $comprobante->numero_completo,
                'nuevo' => $numeroCompleto,
                'motivo' => $motivo,
            ], JSON_UNESCAPED_UNICODE),
            'fecha' => now(),
        ]);

        return [$nuevoId, $numeroCompleto];
    }

    /**
     * sp_anular_venta — revierte el stock y marca el estado. La venta no se
     * borra nunca (RNF6).
     */
    public static function anularVenta(int $ventaId, int $usuarioId, string $motivo): void
    {
        $venta = DB::table('ventas')->where('id', $ventaId)->lockForUpdate()->first();

        if (! $venta) {
            throw new RuntimeException('La venta no existe');
        }
        if ($venta->estado !== 'COMPLETADA') {
            throw new RuntimeException('Solo se puede anular una venta COMPLETADA');
        }

        foreach (DB::table('venta_detalle')->where('venta_id', $ventaId)->get() as $linea) {
            $producto = DB::table('productos')->where('id', $linea->producto_id)->lockForUpdate()->first();
            $anterior = (float) $producto->stock_actual;
            $cantidad = (float) $linea->cantidad;

            DB::table('movimientos_inventario')->insert([
                'producto_id' => $linea->producto_id,
                'usuario_id' => $usuarioId,
                'tipo' => 'ENTRADA',
                'origen' => 'ANULACION',
                'venta_id' => $ventaId,
                'cantidad' => $cantidad,
                'stock_anterior' => $anterior,
                'stock_resultante' => $anterior + $cantidad,
                'motivo' => 'Anulación de venta: '.$motivo,
                'fecha' => now(),
            ]);

            DB::table('productos')->where('id', $linea->producto_id)
                ->update(['stock_actual' => DB::raw('stock_actual + '.self::num($cantidad))]);
        }

        DB::table('ventas')->where('id', $ventaId)->update([
            'estado' => 'ANULADA',
            'anulada_en' => now(),
            'anulada_por' => $usuarioId,
            'motivo_anulacion' => $motivo,
        ]);

        // El correlativo se conserva: el documento se anula, no se borra.
        DB::table('comprobantes')->where('venta_id', $ventaId)->where('estado', 'EMITIDO')->update([
            'estado' => 'ANULADO',
            'anulado_en' => now(),
            'motivo_anulacion' => $motivo,
        ]);

        DB::table('auditoria')->insert([
            'usuario_id' => $usuarioId,
            'accion' => 'ANULAR_VENTA',
            'entidad' => 'ventas',
            'entidad_id' => $ventaId,
            'detalle' => json_encode(['motivo' => $motivo], JSON_UNESCAPED_UNICODE),
            'fecha' => now(),
        ]);
    }

    /**
     * sp_cerrar_caja — calcula el efectivo esperado y cierra el turno.
     *
     * Del cajón solo sale y entra lo que pasó por él: los pagos con método que
     * afecta caja, los movimientos, y la parte en efectivo de lo devuelto.
     */
    public static function cerrarCaja(int $sesionId, int $usuarioId, float $declarado, ?string $observacion): void
    {
        $sesion = DB::table('sesiones_caja')
            ->where('id', $sesionId)->where('estado', 'ABIERTA')
            ->lockForUpdate()->first();

        if (! $sesion) {
            throw new RuntimeException('La sesión de caja no existe o ya está cerrada');
        }

        $ventas = (float) DB::table('venta_pagos as vp')
            ->join('ventas as v', 'v.id', '=', 'vp.venta_id')
            ->join('metodos_pago as mp', 'mp.id', '=', 'vp.metodo_pago_id')
            ->where('v.sesion_caja_id', $sesionId)
            ->where('v.estado', '<>', 'ANULADA')
            ->where('mp.afecta_caja', 1)
            ->sum('vp.monto');

        $movimientos = DB::table('movimientos_caja')
            ->where('sesion_caja_id', $sesionId)
            ->selectRaw("IFNULL(SUM(IF(tipo='INGRESO', monto, 0)),0) AS ingresos")
            ->selectRaw("IFNULL(SUM(IF(tipo='EGRESO', monto, 0)),0) AS egresos")
            ->first();

        // De cada devolución sale del cajón solo la fracción que en su día
        // entró en efectivo: lo cobrado con tarjeta se reembolsa por su medio.
        $devuelto = (float) DB::table('devoluciones as d')
            ->join('ventas as v', 'v.id', '=', 'd.venta_id')
            ->where('d.sesion_caja_id', $sesionId)
            // `efectivo` lo guarda la devolución desde el 14/09/2026; las
            // anteriores siguen con la proporción, igual que sp_cerrar_caja.
            ->selectRaw('IFNULL(SUM(IFNULL(d.efectivo, ROUND(d.total * IFNULL((
                    SELECT SUM(vp.monto) FROM venta_pagos vp
                      JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id
                     WHERE vp.venta_id = d.venta_id AND mp.afecta_caja = 1
                 ) / NULLIF(v.total, 0), 0), 2))), 0) AS devuelto')
            ->value('devuelto');

        $esperado = (float) $sesion->monto_inicial
            + $ventas
            + (float) $movimientos->ingresos
            - (float) $movimientos->egresos
            - $devuelto;

        // `diferencia` es columna generada: sale sola de esperado y declarado.
        DB::table('sesiones_caja')->where('id', $sesionId)->update([
            'fecha_cierre' => now(),
            'usuario_cierre_id' => $usuarioId,
            'monto_esperado' => round($esperado, 2),
            'monto_declarado' => $declarado,
            'estado' => 'CERRADA',
            'observacion_cierre' => $observacion,
        ]);
    }

    // =====================================================================
    //  apoyo
    // =====================================================================

    private static function config(string $clave, string $porOmision): string
    {
        $valor = DB::table('configuracion')->where('clave', $clave)->value('valor');

        return $valor !== null && $valor !== '' ? (string) $valor : $porOmision;
    }

    private static function tasaImpuesto(): float
    {
        return (float) self::config('tasa_impuesto', '0');
    }

    /** Number para interpolar en DB::raw sin arrastrar notación científica ni locale. */
    private static function num(float $n): string
    {
        return number_format($n, 3, '.', '');
    }
}
