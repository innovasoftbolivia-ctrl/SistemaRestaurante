<?php

namespace App\Services;

use App\Models\Auditoria;
use App\Models\Usuario;
use App\Support\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Los precios del catálogo cuando el negocio cambia cómo trabaja el impuesto.
 *
 * `productos.precio_venta` significa cosas distintas en cada modo: con el
 * impuesto encima es la base y el cliente paga la base más la tasa; con el
 * impuesto incluido es lo que paga el cliente. Cambiar de modo sin convertir
 * los precios le cambia el precio a todo el mostrador de golpe: 13 % más caro
 * o 13 % más barato. Por eso, al cambiar de modo, se ajustan para que el
 * cliente siga pagando lo mismo.
 *
 * Solo los productos afectos: un producto exonerado cuesta lo mismo en los
 * dos modos. Las ventas ya hechas no se tocan: cada una guarda su modo.
 *
 * La conversión redondea al centavo, así que ida y vuelta no siempre deja el
 * precio de antes, y marcar mal la casilla dos veces desviaba el catálogo sin
 * forma de volver atrás. Por eso cada conversión deja en la bitácora el precio
 * anterior de cada producto, y la última se puede deshacer.
 */
class Precios
{
    /**
     * @param  float  $tasaAnterior  la tasa con la que se cobraba hasta ahora
     * @param  float  $tasaNueva  la que rige desde ahora
     * @return array<int, array{0: string, 1: string}> id del producto => [precio anterior, precio nuevo], solo los que cambiaron
     */
    public static function convertirAlModo(bool $incluido, float $tasaAnterior, float $tasaNueva): array
    {
        if ($incluido) {
            // El cliente pagaba base × (1 + tasa): ese pasa a ser el precio.
            // ROUND de MySQL, igual que el precio de estante que ya veía.
            if ($tasaAnterior <= 0) {
                return [];
            }

            $sql = 'ROUND(precio_venta * (1 + ?), 2)';
            $tasa = $tasaAnterior;
        } else {
            // El cliente pagaba el precio: la base es el precio sin la tasa.
            if ($tasaNueva <= 0) {
                return [];
            }

            $sql = 'ROUND(precio_venta / (1 + ?), 2)';
            $tasa = $tasaNueva;
        }

        $tasa = number_format($tasa, 4, '.', '');

        // Los precios de antes, con candado: nadie los edita entre leerlos y
        // convertirlos, y lo que queda en la bitácora es exactamente lo que había.
        $cambios = DB::table('productos')
            ->where('afecto_impuesto', 1)
            ->lockForUpdate()
            ->selectRaw("id, precio_venta AS antes, {$sql} AS despues", [$tasa])
            ->get()
            ->filter(fn ($p) => $p->antes !== $p->despues)
            ->mapWithKeys(fn ($p) => [$p->id => [$p->antes, $p->despues]])
            ->all();

        if ($cambios) {
            DB::update("UPDATE productos SET precio_venta = {$sql} WHERE afecto_impuesto = 1", [$tasa]);
        }

        return $cambios;
    }

    /** La última conversión, si todavía se puede deshacer. */
    public static function ultimaConversion(): ?Auditoria
    {
        $ultima = Auditoria::whereIn('accion', ['PRECIOS_CONVERTIDOS', 'PRECIOS_RESTAURADOS'])
            ->orderByDesc('id')
            ->first();

        if (! $ultima || $ultima->accion !== 'PRECIOS_CONVERTIDOS' || empty($ultima->detalle['precios'])) {
            return null;
        }

        // Si después se cambió de modo otra vez, deshacer esta dejaría los
        // precios en un modo y la configuración en otro.
        $modo = Config::preciosIncluyenImpuesto() ? '1' : '0';

        return ($ultima->detalle['precios_incluyen_impuesto'] ?? null) === $modo ? $ultima : null;
    }

    /**
     * Vuelve el catálogo y el modo a como estaban antes de la última conversión.
     *
     * Solo se restaura el precio de los productos que nadie tocó después: si
     * alguien ya corrigió uno a mano, su precio nuevo vale más que el de antes.
     *
     * @return array{restaurados: int, omitidos: int}
     */
    public static function deshacerUltimaConversion(Usuario $usuario): array
    {
        return DB::transaction(function () use ($usuario) {
            // Candado sobre la fila del modo: dos «deshacer» a la vez no
            // restauran dos veces.
            DB::table('configuracion')->where('clave', 'precios_incluyen_impuesto')->lockForUpdate()->first();

            $conversion = self::ultimaConversion();

            if (! $conversion) {
                throw new RuntimeException('No hay una conversión de precios que deshacer.');
            }

            $restaurados = 0;
            $omitidos = 0;

            foreach ($conversion->detalle['precios'] as $id => [$antes, $despues]) {
                $cambio = DB::table('productos')
                    ->where('id', $id)
                    ->where('precio_venta', $despues)
                    ->update(['precio_venta' => $antes]);

                $cambio ? $restaurados++ : $omitidos++;
            }

            $modoAnterior = $conversion->detalle['precios_incluyen_impuesto'] === '1' ? '0' : '1';
            $tasaAnterior = number_format((float) ($conversion->detalle['tasa_anterior'] ?? 0), 4, '.', '');

            DB::table('configuracion')->updateOrInsert(['clave' => 'precios_incluyen_impuesto'], ['valor' => $modoAnterior]);
            DB::table('configuracion')->updateOrInsert(['clave' => 'tasa_impuesto'], ['valor' => $tasaAnterior]);
            Config::olvidar();

            Auditor::registrar('PRECIOS_RESTAURADOS', 'productos', null, [
                'conversion' => $conversion->id,
                'restaurados' => $restaurados,
                'omitidos' => $omitidos,
                'precios_incluyen_impuesto' => $modoAnterior,
                'tasa_impuesto' => $tasaAnterior,
            ], $usuario->id);

            return ['restaurados' => $restaurados, 'omitidos' => $omitidos];
        });
    }
}
