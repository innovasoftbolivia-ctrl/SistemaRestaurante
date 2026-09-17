<?php

namespace App\Support;

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
        return (bool) config('ventas.mostrar_facturacion', false);
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

    public static function moneda(): string
    {
        return self::get('moneda_simbolo', 'Bs');
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
     * Cantidades de stock sin ceros de relleno: el esquema guarda tres
     * decimales, pero «120» se lee mejor que «120.000».
     */
    public static function cantidad(int|float|string|null $valor): string
    {
        $texto = number_format((float) $valor, 3, '.', '');

        return str_contains($texto, '.') ? rtrim(rtrim($texto, '0'), '.') : $texto;
    }

    /** Se usa en las pruebas, cuando la configuración cambia dentro del caso. */
    public static function olvidar(): void
    {
        self::$valores = null;
    }
}
