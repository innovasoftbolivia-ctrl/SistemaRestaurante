<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lee los parámetros del negocio de la tabla `configuracion` (tasa de impuesto,
 * moneda, nombre del local...). Se consulta una vez por petición.
 */
class Config
{
    /** @var array<string, string>|null */
    private static ?array $valores = null;

    public static function get(string $clave, ?string $porDefecto = null): ?string
    {
        self::$valores ??= DB::table('configuracion')->pluck('valor', 'clave')->all();

        return self::$valores[$clave] ?? $porDefecto;
    }

    /**
     * ¿Las pantallas muestran impuesto, IVA y facturas? Mientras el negocio no
     * factura, no: todo sale como recibo y sin desglose, aunque el código
     * siga entero (config/ventas.php, `MOSTRAR_FACTURACION`).
     */
    public static function facturacionVisible(): bool
    {
        return (bool) config('restaurante.mostrar_facturacion', false);
    }

    /** Tasa del impuesto a las ventas como fracción: 0.13 para el IVA boliviano del 13 %. */
    public static function tasaImpuesto(): float
    {
        return (float) self::get('tasa_impuesto', '0');
    }

    /**
     * ¿El precio de venta ya trae el impuesto? En Bolivia es lo habitual: el
     * precio de estante es lo que paga el cliente y el IVA va por dentro.
     */
    public static function preciosIncluyenImpuesto(): bool
    {
        return (string) self::get('precios_incluyen_impuesto', '0') === '1';
    }

    /**
     * El impuesto que lleva adentro un importe con impuesto incluido:
     * ROUND(importe × tasa / (1 + tasa), 2), en centavos enteros, igual que
     * la columna generada `venta_detalle.impuesto_linea`.
     */
    public static function impuestoDentroDe(float $importe, ?float $tasa = null): float
    {
        $centavos = (int) round($importe * 100);
        $t = (int) round(($tasa ?? self::tasaImpuesto()) * 10000);
        $d = 10000 + $t;

        return intdiv(2 * $centavos * $t + $d, 2 * $d) / 100;
    }

    /**
     * El símbolo de la moneda del negocio. Sale del código (`moneda_codigo`):
     * guardar también el símbolo era tener dos datos que podían quedar
     * desparejos.
     */
    public static function moneda(): string
    {
        $codigo = self::get('moneda_codigo');

        return self::simbolo(blank($codigo) ? 'BOB' : $codigo);
    }

    /**
     * Símbolo de una moneda por su código ISO.
     *
     * Un comprobante congela el código con el que se emitió, así que un
     * documento viejo debe seguir mostrando su símbolo aunque el negocio haya
     * cambiado de moneda. Si el código no está en la lista se muestra tal cual:
     * mejor «CLP 1.200» que un símbolo equivocado.
     */
    public static function simbolo(?string $codigo): string
    {
        if (blank($codigo)) {
            return self::moneda();
        }

        return match (mb_strtoupper($codigo)) {
            'BOB' => 'Bs',
            'PEN' => 'S/',
            'USD' => '$',
            'EUR' => '€',
            default => mb_strtoupper($codigo),
        };
    }

    public static function negocio(): string
    {
        return self::get('negocio_nombre', config('app.name'));
    }

    /** Formatea un importe con el símbolo de la moneda configurada. */
    public static function importe(int|float|string|null $valor, int $decimales = 2): string
    {
        return self::moneda().' '.number_format((float) $valor, $decimales, '.', ',');
    }

    /**
     * Cantidades sin ceros de relleno: el esquema guarda tres decimales
     * —vienen de cuando se vendía al peso—, pero «2» se lee mejor que «2.000».
     */
    public static function cantidad(int|float|string|null $valor): string
    {
        $texto = number_format((float) $valor, 3, '.', '');

        return str_contains($texto, '.') ? rtrim(rtrim($texto, '0'), '.') : $texto;
    }

    /** La hora de corte más tardía que se admite: más allá, la jornada ya no es «la noche anterior». */
    public const HORA_CORTE_MAXIMA = 12;

    /**
     * A qué hora empieza la jornada del local (`hora_corte_jornada`, 5 si no
     * está): el restaurante cierra pasada la medianoche, y lo que se pide a la
     * 01:30 es de la noche anterior, no del día nuevo.
     */
    public static function horaCorteJornada(): int
    {
        $hora = (int) self::get('hora_corte_jornada', '5');

        return max(0, min(self::HORA_CORTE_MAXIMA, $hora));
    }

    /**
     * La jornada a la que pertenece un momento: su fecha, menos las horas del
     * corte. Con el corte a las 5, el 19/09 a las 01:30 es de la jornada del
     * 18/09, y el 19/09 a las 05:00 ya es del 19/09.
     *
     * Es lo que numera los pedidos (`pedidos.jornada`) y lo que acota la
     * pantalla de la cocina.
     */
    public static function jornadaDe(DateTimeInterface $momento): string
    {
        return Carbon::instance($momento)
            ->subHours(self::horaCorteJornada())
            ->toDateString();
    }

    /** La jornada en curso. */
    public static function jornadaActual(): string
    {
        return self::jornadaDe(now());
    }

    /**
     * La jornada de una columna de fecha y hora, en SQL: la misma cuenta que
     * `jornadaDe()`, para agrupar por jornada en la base. Es la única copia
     * de la expresión en PHP; la otra es la vista `v_ventas_por_dia`.
     */
    public static function jornadaSql(string $columna): string
    {
        return sprintf('DATE(%s - INTERVAL %d HOUR)', $columna, self::horaCorteJornada());
    }

    /**
     * Desde qué momento y hasta cuál van las jornadas de un rango: de la hora
     * de corte del primer día a un segundo antes de la hora de corte del día
     * siguiente al último. Filtrar con este rango y no con `jornadaSql()`
     * deja que la base use el índice de la fecha.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function momentosDeJornadas(DateTimeInterface|string $desde, DateTimeInterface|string $hasta): array
    {
        $corte = self::horaCorteJornada();

        return [
            Carbon::parse($desde)->startOfDay()->addHours($corte),
            Carbon::parse($hasta)->startOfDay()->addDay()->addHours($corte)->subSecond(),
        ];
    }

    /** Se usa en las pruebas, cuando la configuración cambia dentro del caso. */
    public static function olvidar(): void
    {
        self::$valores = null;
    }
}
