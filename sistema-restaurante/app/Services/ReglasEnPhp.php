<?php

namespace App\Services;

use App\Models\SesionCaja;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Las reglas que normalmente ejecuta la BASE, hechas en PHP.
 *
 * Réplica de los 6 procedimientos almacenados y los 7 triggers de
 * `docs/sql/01_schema_mysql.sql`, para poder correr el sistema en un hosting
 * que no permite crearlos (ver config/restaurante.php).
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
        return (bool) config('restaurante.logica_en_php', false);
    }

    // =====================================================================
    //  TRIGGERS
    // =====================================================================

    /**
     * trg_venta_detalle_before_insert
     *
     * Copia del producto el régimen de impuesto y la tasa vigente. Siempre:
     * una tasa que venga en la línea no manda (igual que el trigger).
     *
     * @param  array<string, mixed>  $linea
     * @return array<string, mixed>
     */
    public static function antesDeInsertarLineaVenta(array $linea): array
    {
        // La línea sigue el modo de precio de su venta: todas iguales.
        $linea['impuesto_incluido'] = (int) DB::table('ventas')->where('id', $linea['venta_id'])->value('impuesto_incluido');

        $afecto = (bool) DB::table('productos')
            ->where('id', $linea['producto_id'])
            ->value('afecto_impuesto');

        $linea['afecto_impuesto'] = $afecto;
        $linea['tasa_impuesto'] = $afecto ? self::tasaImpuesto() : 0;

        return $linea;
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

        // La excepción: una persona natural con NIT (unipersonal) recibe factura.
        if ($tipo->aplica_persona !== 'AMBAS'
            && ($datos['cliente_id'] ?? null) !== null
            && ($datos['tipo_persona'] ?? '') !== $tipo->aplica_persona
            && ! ($tipo->aplica_persona === 'JURIDICA' && ($datos['cliente_tipo_documento'] ?? null) === 'NIT')) {
            throw new RuntimeException('El tipo de comprobante no corresponde al tipo de persona del cliente');
        }

        // Lo pagado tiene que sumar el total de la venta: el comprobante es el
        // último paso de registrarla.
        $pagado = round((float) DB::table('venta_pagos')->where('venta_id', $datos['venta_id'])->sum('monto'), 2);
        $total = round((float) DB::table('ventas')->where('id', $datos['venta_id'])->value('total'), 2);

        if ($pagado !== $total) {
            throw new RuntimeException('Lo pagado no coincide con el total de la venta');
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
            ->selectRaw('IFNULL(SUM(importe),0) AS base, IFNULL(SUM(impuesto_linea),0) AS impuesto, IFNULL(SUM(total_linea),0) AS cobrado')
            ->first();

        $base = (float) $totales->base;
        $impuestoBruto = (float) $totales->impuesto;
        $venta = DB::table('ventas')->where('id', $ventaId)->first(['descuento', 'impuesto_incluido', 'descuento_precio_final']);
        $descuento = (float) $venta->descuento;

        // Con el impuesto incluido, el descuento lo vio el cliente sobre el
        // precio final: el total es lo cobrado menos ese descuento, el
        // impuesto baja en la misma proporción y `descuento` guarda la parte
        // que corresponde a la base. Igual que sp_recalcular_venta.
        if ($venta->impuesto_incluido) {
            $cobradoC = (int) round((float) $totales->cobrado * 100);
            $finalC = (int) round((float) $venta->descuento_precio_final * 100);
            $brutoC = (int) round($impuestoBruto * 100);

            if ($finalC > $cobradoC) {
                throw new RuntimeException('El descuento no puede superar el total de la venta');
            }

            $impuestoC = $cobradoC > 0 ? intdiv(2 * $brutoC * ($cobradoC - $finalC) + $cobradoC, 2 * $cobradoC) : 0;

            DB::table('ventas')->where('id', $ventaId)->update([
                'subtotal' => $base,
                'descuento' => ($finalC - $brutoC + $impuestoC) / 100,
                'impuesto' => $impuestoC / 100,
            ]);

            return;
        }

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
     * negocio, del cliente y los importes en `comprobantes`.
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
            // Los datos del negocio, congelados: el documento se reimprime como se entregó.
            'emisor_nombre' => self::configONulo('negocio_nombre'),
            'emisor_documento' => self::configONulo('negocio_documento'),
            'emisor_direccion' => self::configONulo('negocio_direccion'),
            'emisor_telefono' => self::configONulo('negocio_telefono'),
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
            throw new RuntimeException('No se sustituye el comprobante de una venta anulada');
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
     * sp_anular_venta — marca el estado y anula el comprobante, conservando el
     * correlativo. La venta no se borra nunca (RNF6).
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

        $turno = DB::table('sesiones_caja')->where('id', $venta->sesion_caja_id)->sharedLock()->value('estado');

        if ($turno !== 'ABIERTA') {
            throw new RuntimeException('El turno de caja de esta venta ya cerró: no se anula, su dinero ya se contó en el arqueo');
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
     * afecta caja y los movimientos. Lo cobrado en una venta anulada no cuenta.
     *
     * La cuenta es `SesionCaja::desgloseDelEfectivo()`, la misma que enseña la
     * pantalla de cierre: en PHP la fórmula del arqueo tiene una sola copia
     * (la otra vía es el procedimiento).
     */
    public static function cerrarCaja(int $sesionId, int $usuarioId, float $declarado, ?string $observacion): void
    {
        $sesion = SesionCaja::whereKey($sesionId)->where('estado', 'ABIERTA')
            ->lockForUpdate()->first();

        if (! $sesion) {
            throw new RuntimeException('La sesión de caja no existe o ya está cerrada');
        }

        $esperado = $sesion->desgloseDelEfectivo()['esperado'];

        // `diferencia` es columna generada: sale sola de esperado y declarado.
        DB::table('sesiones_caja')->where('id', $sesionId)->update([
            'fecha_cierre' => now(),
            'usuario_cierre_id' => $usuarioId,
            'monto_esperado' => $esperado,
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

    /** Como `config()`, pero sin valor por omisión: ausente o vacío es NULL (NULLIF en SQL). */
    private static function configONulo(string $clave): ?string
    {
        $valor = DB::table('configuracion')->where('clave', $clave)->value('valor');

        return $valor !== null && $valor !== '' ? (string) $valor : null;
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
