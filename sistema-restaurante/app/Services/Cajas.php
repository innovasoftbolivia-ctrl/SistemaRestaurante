<?php

namespace App\Services;

use App\Models\ArqueoCaja;
use App\Models\Caja;
use App\Models\CobroQr;
use App\Models\MovimientoCaja;
use App\Models\Pedido;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Support\Config;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Apertura, movimientos y cierre del turno de caja.
 *
 * El arqueo lo calcula `sp_cerrar_caja` en la base: es la misma fórmula para
 * todos y no depende de que la aplicación la recuerde bien.
 */
class Cajas
{
    /** Reintentos ante un deadlock; mismo criterio que `Ventas::REINTENTOS`. */
    private const REINTENTOS = 3;

    /** La sesión abierta del usuario, si la tiene. */
    public static function sesionDe(Usuario $usuario): ?SesionCaja
    {
        return SesionCaja::abiertas()
            ->where('usuario_apertura_id', $usuario->id)
            ->with('caja')
            ->first();
    }

    public static function abrir(Caja $caja, Usuario $usuario, float $montoInicial, ?string $observacion = null): SesionCaja
    {
        if ($montoInicial < 0) {
            throw new RuntimeException('El monto inicial no puede ser negativo.');
        }

        // Estos dos chequeos son solo para dar un mensaje entendible en el
        // caso normal (sin carrera): el candado real que evita dos sesiones
        // abiertas —a la vez en la misma caja, o a la vez para el mismo
        // usuario en dos cajas distintas— son los índices únicos
        // `uq_sesion_caja_abierta` / `uq_sesion_usuario_abierta` de la base.
        // Un SELECT-antes-de-INSERT en PHP, sin más, no cierra la ventana de
        // carrera entre dos peticiones simultáneas.
        if (self::sesionDe($usuario)) {
            throw new RuntimeException('Ya tienes una caja abierta. Ciérrala antes de abrir otra.');
        }

        if ($caja->sesionAbierta()->exists()) {
            throw new RuntimeException("La {$caja->nombre} ya está abierta por otro usuario.");
        }

        // El turno anterior dejó un fondo contado en el cajón. Abrir con otro
        // monto sin decir por qué dejaba un sobrante (o un faltante) que el
        // arqueo de este turno no podía ver.
        $fondo = self::fondoDejadoEn($caja);

        if ($fondo !== null && round($montoInicial, 2) !== round($fondo, 2) && blank($observacion)) {
            throw new RuntimeException(sprintf(
                'El último turno de la %s dejó %s en el cajón. Si empiezas con otro monto, explica en la observación por qué.',
                $caja->nombre,
                Config::importe($fondo),
            ));
        }

        try {
            $sesion = DB::transaction(fn () => SesionCaja::create([
                'caja_id' => $caja->id,
                'usuario_apertura_id' => $usuario->id,
                'fecha_apertura' => now(),
                'monto_inicial' => $montoInicial,
                'estado' => 'ABIERTA',
                'observacion' => $observacion,
            ]));
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'uq_sesion_usuario_abierta')) {
                throw new RuntimeException('Ya tienes una caja abierta. Ciérrala antes de abrir otra.');
            }
            if (str_contains($e->getMessage(), 'uq_sesion_caja_abierta')) {
                throw new RuntimeException("La {$caja->nombre} ya está abierta por otro usuario.");
            }
            throw $e;
        }

        Auditor::registrar('CAJA_ABIERTA', 'sesiones_caja', $sesion->id, [
            'caja' => $caja->nombre,
            'monto_inicial' => $montoInicial,
        ], $usuario->id);

        return $sesion;
    }

    /** Lo que el último turno cerrado de la caja dejó en el cajón, si lo anotó. */
    public static function fondoDejadoEn(Caja $caja): ?float
    {
        // El último cierre que SÍ lo anotó: si el más reciente lo dejó vacío
        // —los cierres viejos, anteriores a que fuera obligatorio— el control
        // seguía sin ejecutarse nunca.
        $fondo = SesionCaja::where('caja_id', $caja->id)
            ->where('estado', 'CERRADA')
            ->whereNotNull('fondo_dejado')
            ->orderByDesc('fecha_cierre')
            ->orderByDesc('id')
            ->value('fondo_dejado');

        return $fondo === null ? null : (float) $fondo;
    }

    public static function movimiento(
        SesionCaja $sesion,
        Usuario $usuario,
        string $tipo,
        string $concepto,
        float $monto,
    ): MovimientoCaja {
        if (! $sesion->estaAbierta()) {
            throw new RuntimeException('La caja ya está cerrada: no admite más movimientos.');
        }

        if ($monto <= 0) {
            throw new RuntimeException('El monto del movimiento debe ser mayor que cero.');
        }

        return DB::transaction(function () use ($sesion, $usuario, $tipo, $concepto, $monto) {
            // El turno bloqueado: dos egresos a la vez no pueden sacar entre
            // los dos más de lo que hay.
            $bloqueada = SesionCaja::whereKey($sesion->id)->lockForUpdate()->firstOrFail();

            if (! $bloqueada->estaAbierta()) {
                throw new RuntimeException('La caja ya está cerrada: no admite más movimientos.');
            }

            if ($tipo === 'EGRESO') {
                self::validarEgreso($bloqueada, $usuario, $monto);
            }

            return self::registrarMovimiento($bloqueada, $usuario, $tipo, $concepto, $monto);
        }, self::REINTENTOS);
    }

    /**
     * Un egreso no puede sacar más de lo que debería haber en el cajón, y el
     * cajero tiene un tope sin autorización.
     *
     * Sin esto, un faltante se tapaba con un egreso por el monto justo —«Compra
     * de hielo, 80»— y el arqueo cerraba en cero. Por encima del tope, el
     * egreso lo registra quien puede cerrar la caja, en el mismo turno.
     */
    private static function validarEgreso(SesionCaja $sesion, Usuario $usuario, float $monto): void
    {
        // El tope del cajero va primero: si no, un egreso enorme servía para
        // que el mensaje de abajo le dijera cuánto efectivo esperado hay, la
        // cifra que se le oculta hasta el arqueo.
        if (! $usuario->tienePermiso('caja.cerrar')) {
            self::validarTopeDelCajero($sesion, $usuario, $monto);
        }

        $disponible = round($sesion->efectivoEsperado(), 2);

        if (round($monto, 2) > $disponible) {
            throw new RuntimeException(self::arquea($usuario)
                ? sprintf(
                    'El egreso (%s) es mayor que el efectivo que debería haber en el cajón (%s).',
                    Config::importe($monto),
                    Config::importe(max(0, $disponible)),
                )
                : sprintf(
                    'El egreso (%s) es mayor que el efectivo que debería haber en el cajón. Revisa el monto o pide a un administrador que lo registre.',
                    Config::importe($monto),
                ));
        }
    }

    /**
     * Quién ve el arqueo: el efectivo esperado y la diferencia. El cajero no:
     * cuenta el cajón sin saber cuánto «debería» haber.
     */
    public static function arquea(?Usuario $usuario): bool
    {
        return $usuario !== null && ($usuario->tienePermiso('caja.cerrar') || $usuario->tienePermiso('reportes.ver'));
    }

    /**
     * Quién puede cerrar este turno: quien tiene `caja.cerrar` (cualquier
     * turno), o quien lo abrió, si el negocio encendió «el cajero cierra su
     * propia caja». Ese cierre es a ciegas: ver `cierraACiegas()`.
     */
    /**
     * El arqueo, limpio: solo las denominaciones que existen, solo las que se
     * contaron, y su suma igual a lo declarado. El servidor rehace la cuenta:
     * lo que sumó el navegador no decide nada.
     *
     * @param  ?array<string, int|string|null>  $arqueo
     * @return array<string, int> denominación («200», «0.5») => cantidad
     */
    public static function arqueoValido(?array $arqueo, float $declarado): array
    {
        $limpio = self::arqueoLimpio($arqueo);
        $suma = self::sumaDelArqueo($limpio);

        if ($limpio && abs(round($suma, 2) - round($declarado, 2)) > 0.001) {
            throw new RuntimeException(sprintf(
                'El arqueo suma %s y el efectivo contado dice %s: vuelve a contar.',
                Config::importe($suma), Config::importe($declarado),
            ));
        }

        return $limpio;
    }

    /**
     * Solo las denominaciones que existen y se contaron, una vez cada una.
     * «0.5» y «0.50» son la misma moneda: dos veces en el mismo envío
     * sumaban dos veces y se guardaban una, y el arqueo impreso no daba lo
     * contado.
     *
     * @param  ?array<string, int|string|null>  $arqueo
     * @return array<string, int>
     */
    public static function arqueoLimpio(?array $arqueo): array
    {
        $validas = collect(ArqueoCaja::denominaciones())->mapWithKeys(fn ($d) => [ArqueoCaja::clave($d) => $d]);
        $limpio = [];
        $vistas = [];

        foreach ($arqueo ?? [] as $clave => $cantidad) {
            $clave = ArqueoCaja::clave((float) $clave);

            if (! $validas->has($clave)) {
                throw new RuntimeException("No existe el billete o la moneda de {$clave}.");
            }

            if (isset($vistas[$clave])) {
                throw new RuntimeException("El arqueo trae dos veces el billete o la moneda de {$clave}.");
            }
            $vistas[$clave] = true;

            $cantidad = (int) $cantidad;

            if ($cantidad < 0) {
                throw new RuntimeException('Las cantidades del arqueo no pueden ser negativas.');
            }

            if ($cantidad > 0) {
                $limpio[$clave] = $cantidad;
            }
        }

        return $limpio;
    }

    /** @param  array<string, int>  $arqueo  ya limpio */
    public static function sumaDelArqueo(array $arqueo): float
    {
        return round(collect($arqueo)->sum(fn ($cantidad, $clave) => (float) $clave * $cantidad), 2);
    }

    public static function puedeCerrar(?Usuario $usuario, SesionCaja $sesion): bool
    {
        if ($usuario === null) {
            return false;
        }

        if ($usuario->tienePermiso('caja.cerrar')) {
            return true;
        }

        return $sesion->usuario_apertura_id === $usuario->id
            && $usuario->tienePermiso('caja.abrir')
            && Config::cajeroCierraSuCaja();
    }

    /**
     * Quien cierra sin ver el esperado. No se le dice si hay diferencia ni de
     * cuánto: si el cierre le contestara «faltan Bs 20, explica», bastaría con
     * volver a escribir el conteo hasta que cuadre y quedarse con el sobrante.
     * La diferencia la ve el administrador en el turno y en los reportes.
     */
    public static function cierraACiegas(?Usuario $usuario): bool
    {
        return ! self::arquea($usuario);
    }

    private static function validarTopeDelCajero(SesionCaja $sesion, Usuario $usuario, float $monto): void
    {
        $tope = (float) Config::get('egreso_max_cajero', '0');

        // Acumulado del turno, no por movimiento: el tope se evadía partiendo
        // el retiro en cuatro egresos «de hasta Bs 200» que el arqueo cuadraba
        // igual, porque cada uno bajaba el efectivo esperado.
        $yaSacado = (float) $sesion->movimientos()
            ->where('tipo', 'EGRESO')
            ->where('usuario_id', $usuario->id)
            ->sum('monto');

        if (round($yaSacado + $monto, 2) > $tope) {
            throw new RuntimeException(sprintf(
                'Tu rol puede sacar del cajón hasta %s por turno y ya registraste %s. Pide a un administrador que registre este egreso en tu turno.',
                Config::importe($tope),
                Config::importe($yaSacado),
            ));
        }
    }

    private static function registrarMovimiento(
        SesionCaja $sesion,
        Usuario $usuario,
        string $tipo,
        string $concepto,
        float $monto,
    ): MovimientoCaja {
        $movimiento = MovimientoCaja::create([
            'sesion_caja_id' => $sesion->id,
            'usuario_id' => $usuario->id,
            'tipo' => $tipo,
            'concepto' => $concepto,
            'monto' => $monto,
            'fecha' => now(),
        ]);

        Auditor::registrar('CAJA_MOVIMIENTO', 'movimientos_caja', $movimiento->id, [
            'tipo' => $tipo,
            'concepto' => $concepto,
            'monto' => $monto,
        ], $usuario->id);

        return $movimiento;
    }

    /**
     * Los pedidos con el cobro anulado que falta volver a cobrar, el más
     * viejo primero.
     *
     * En el local todo se cobra al pedirlo; sin cobrar solo queda un pedido
     * cuya venta se anuló (`Pedidos::reabrirTrasAnular`) y todavía no se volvió
     * a cobrar ni se canceló. Todos y no solo los de este turno: un pedido no
     * pertenece a una caja hasta que se cobra, y quien cierra tiene que saber
     * qué quedó pendiente.
     *
     * @return Collection<int, Pedido>
     */
    public static function cuentasAbiertas(): Collection
    {
        return Pedido::abiertos()
            ->with(['ultimaVenta.cliente:id,nombre', 'detalle'])
            ->orderBy('fecha_apertura')
            ->orderBy('id')
            ->get();
    }

    /**
     * Cierra el turno con el efectivo contado. El procedimiento calcula el
     * esperado y la base deriva la diferencia.
     *
     * Con pedidos de cobro anulado sin volver a cobrar no se cierra a ciegas:
     * hay que confirmarlo. No se prohíbe —el turno siguiente puede cobrarlos—,
     * pero antes se podía cerrar con ellos sin que nadie se enterara, y el
     * turno siguiente no sabía que tenía que cobrarlos.
     *
     * @param  ?float  $fondo  lo que queda en el cajón para el siguiente turno
     * @param  ?string  $huella  `SesionCaja::huella()` de cuando se empezó a contar
     * @param  bool  $conCuentasAbiertas  quien cierra vio los pedidos de cobro anulado y cierra igual
     * @param  ?array<string, int>  $arqueo  cuántos billetes y monedas de cada denominación se contaron
     *                                       («200» => 3, «0.5» => 4); su suma tiene que ser el declarado
     */
    public static function cerrar(
        SesionCaja $sesion,
        Usuario $usuario,
        float $declarado,
        ?string $observacion = null,
        ?float $fondo = null,
        ?string $huella = null,
        bool $conCuentasAbiertas = false,
        ?array $arqueo = null,
    ): SesionCaja {
        $arqueo = self::arqueoValido($arqueo, $declarado);

        if (! $sesion->estaAbierta()) {
            throw new RuntimeException('Esta caja ya fue cerrada.');
        }

        if ($declarado < 0) {
            throw new RuntimeException('El efectivo contado no puede ser negativo.');
        }

        if ($fondo !== null && ($fondo < 0 || round($fondo, 2) > round($declarado, 2))) {
            throw new RuntimeException('Lo que queda en el cajón no puede ser negativo ni más de lo contado.');
        }

        // `sp_cerrar_caja` hace su SELECT ... FOR UPDATE y su UPDATE final en
        // sentencias separadas: sin envolverlo en una transacción de verdad,
        // el autocommit de MySQL libera ese lock apenas termina el SELECT, no
        // al terminar el procedimiento. Dos cierres de la misma sesión que se
        // solapan (doble clic, dos administradores) podían pasar ambos el
        // chequeo de "¿sigue abierta?" y terminar pisándose en silencio —
        // "last write wins" sin ningún error para nadie. Envuelto en
        // `DB::transaction()`, el lock se mantiene hasta el commit: el
        // segundo cierre que llegue queda bloqueado hasta que el primero
        // termine, y al reintentar ya encuentra la sesión `CERRADA` — el
        // propio procedimiento lo rechaza con el SIGNAL que ya tenía.
        //
        // `DB::select` y no `DB::statement`: el procedimiento termina con un
        // SELECT del arqueo, y ese resultado hay que consumirlo o la siguiente
        // consulta de la conexión falla.
        $abiertas = [];

        DB::transaction(function () use ($sesion, $usuario, $declarado, $observacion, $fondo, $huella, $conCuentasAbiertas, $arqueo, &$abiertas) {
            // Primero el turno bloqueado: una venta, un movimiento o una
            // anulación que llegue ahora espera a que el cierre termine y lo
            // encuentra cerrado. Lo que se compara con el conteo es lo que hay
            // en este instante, no lo que había cuando se abrió la pantalla.
            $bloqueada = SesionCaja::whereKey($sesion->id)->lockForUpdate()->first();

            if (! $bloqueada?->estaAbierta()) {
                throw new RuntimeException('Esta caja ya fue cerrada.');
            }

            if ($huella !== null && ! hash_equals($bloqueada->huella(), $huella)) {
                throw new RuntimeException(
                    'Mientras contabas se registraron ventas o movimientos en este turno y el efectivo esperado cambió. '
                    .'Revisa el nuevo esperado y vuelve a confirmar el cierre.'
                );
            }

            // Revisado aquí y no solo en la pantalla: una venta puede anularse
            // mientras se cuenta el cajón, y ese pedido también tiene que verse.
            $abiertas = self::cuentasAbiertas()->map(fn (Pedido $p) => [
                'pedido_id' => $p->id,
                'cuenta' => $p->etiqueta,
                'total' => Pedidos::totalDe($p),
            ])->all();

            if ($abiertas !== [] && ! $conCuentasAbiertas) {
                throw new RuntimeException(sprintf(
                    'Hay %d pedido(s) con el cobro anulado, sin volver a cobrar: %s. Cóbralos o cancélalos antes de cerrar, o marca que cierras sin volver a cobrarlos.',
                    count($abiertas),
                    implode(', ', array_column($abiertas, 'cuenta')),
                ));
            }

            // Una diferencia sin explicación no le sirve a nadie. Se calcula
            // aquí con la misma fórmula que firma el procedimiento. A quien
            // cierra a ciegas no se le pide: sería decirle que hay diferencia.
            $diferencia = round($declarado - $bloqueada->efectivoEsperado(), 2);

            if ($diferencia !== 0.0 && blank($observacion) && ! self::cierraACiegas($usuario)) {
                throw new RuntimeException(sprintf(
                    'El conteo tiene una diferencia de %s%s: escribe en la observación qué pasó.',
                    $diferencia > 0 ? '+' : '−',
                    Config::importe(abs($diferencia)),
                ));
            }

            ReglasEnPhp::activa()
                ? ReglasEnPhp::cerrarCaja($sesion->id, $usuario->id, $declarado, $observacion)
                : DB::select('CALL sp_cerrar_caja(?, ?, ?, ?)', [
                    $sesion->id, $usuario->id, $declarado, $observacion,
                ]);

            if ($fondo !== null) {
                SesionCaja::whereKey($sesion->id)->update(['fondo_dejado' => $fondo]);
            }

            // El detalle del conteo, con el cierre: si el cierre no se guarda,
            // tampoco su arqueo.
            if ($arqueo) {
                DB::table('arqueo_caja')->insert(array_map(fn ($denominacion, $cantidad) => [
                    'sesion_caja_id' => $sesion->id,
                    'denominacion' => $denominacion,
                    'cantidad' => $cantidad,
                ], array_keys($arqueo), $arqueo));
            }
        }, self::REINTENTOS);

        $sesion->refresh();

        // Un QR que quedó esperando pago ya no puede terminar en una venta: la
        // venta exige el mismo turno. Primero se le pregunta al banco —si el
        // cliente alcanzó a pagar, queda PAGADO y a la vista como «QR pagado sin
        // venta»— y solo si sigue pendiente se cancela. Antes se cancelaba sin
        // preguntar, y un pago de segundos antes quedaba como vencido con el
        // dinero en el banco. Si el banco no contesta, lo recoge `qr:vencer`.
        CobroQr::where('sesion_caja_id', $sesion->id)
            ->where('estado', CobroQr::PENDIENTE)
            ->get()
            ->each(function (CobroQr $cobro) {
                try {
                    $cobro = CobrosQr::refrescar($cobro);

                    if ($cobro->estaPendiente()) {
                        CobrosQr::vencer($cobro);
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        Auditor::registrar('CAJA_CERRADA', 'sesiones_caja', $sesion->id, [
            'esperado' => $sesion->monto_esperado,
            'declarado' => $sesion->monto_declarado,
            'diferencia' => $sesion->diferencia,
            'fondo_dejado' => $sesion->fondo_dejado,
        ] + ($abiertas !== [] ? ['cuentas_abiertas' => $abiertas] : [])
          + ($arqueo ? ['arqueo' => $arqueo] : []), $usuario->id);

        return $sesion;
    }
}
