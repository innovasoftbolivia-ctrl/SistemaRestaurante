<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lee el registro de errores de Laravel (storage/logs) para el visor del
 * desarrollador. Solo lee: no borra ni rota nada, de eso se encarga el canal
 * `daily` (un archivo por día, se guardan los últimos 14).
 *
 * Cada entrada empieza con «[fecha] entorno.NIVEL: mensaje»; lo que sigue
 * hasta la próxima es su contexto y la traza. Los errores iguales se juntan
 * con cuántas veces pasaron: el mismo error mil veces es un solo problema.
 */
class RegistroDeErrores
{
    /** Cuánto se lee del final de cada archivo: un log enorme no tumba la página. */
    private const BYTES = 2 * 1024 * 1024;

    public const NIVELES = ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG'];

    /** @return Collection<int, array{archivo: string, fecha: string, bytes: int}> los archivos, el más nuevo primero */
    public static function archivos(): Collection
    {
        return collect(glob(storage_path('logs/*.log')) ?: [])
            ->map(fn (string $ruta) => [
                'archivo' => basename($ruta),
                'fecha' => date('Y-m-d H:i', filemtime($ruta)),
                'bytes' => filesize($ruta),
            ])
            ->sortByDesc('fecha')
            ->values();
    }

    /**
     * Las entradas de un archivo, agrupadas por mensaje, la más reciente
     * primero.
     *
     * @return Collection<int, array{nivel: string, mensaje: string, detalle: string, veces: int, primera: string, ultima: string}>
     */
    public static function entradas(string $archivo, ?string $nivel = null): Collection
    {
        // Solo un nombre de archivo de la carpeta de logs: nada de rutas.
        $ruta = storage_path('logs/'.basename($archivo));

        if (! str_ends_with($ruta, '.log') || ! is_file($ruta)) {
            return collect();
        }

        $tam = filesize($ruta);
        $f = fopen($ruta, 'rb');
        if ($tam > self::BYTES) {
            fseek($f, -self::BYTES, SEEK_END);
        }
        $texto = (string) stream_get_contents($f);
        fclose($f);

        preg_match_all(
            '/^\[(\d{4}-\d{2}-\d{2}[ T][\d:.]+)[^\]]*\] [\w-]+\.([A-Z]+): (.*?)(?=^\[\d{4}-\d{2}-\d{2}[ T][\d:.]+[^\]]*\] [\w-]+\.[A-Z]+: |\z)/ms',
            $texto, $coincidencias, PREG_SET_ORDER,
        );

        return collect($coincidencias)
            ->map(function (array $c) {
                [$primera, $resto] = array_pad(explode("\n", rtrim($c[3]), 2), 2, '');
                // El mensaje sin el contexto JSON del final, para juntar iguales.
                $mensaje = trim(preg_replace('/\s\{.*$/s', '', $primera));

                return [
                    'momento' => Carbon::parse($c[1])->format('Y-m-d H:i:s'),
                    'nivel' => $c[2],
                    'mensaje' => $mensaje !== '' ? $mensaje : trim($primera),
                    'detalle' => trim($primera."\n".$resto),
                ];
            })
            ->when($nivel, fn ($e) => $e->where('nivel', $nivel))
            ->groupBy(fn ($e) => $e['nivel'].'|'.$e['mensaje'])
            ->map(fn (Collection $iguales) => [
                'nivel' => $iguales->first()['nivel'],
                'mensaje' => $iguales->first()['mensaje'],
                // La traza de la última vez: la que sirve para arreglarlo.
                'detalle' => $iguales->last()['detalle'],
                'veces' => $iguales->count(),
                'primera' => $iguales->first()['momento'],
                'ultima' => $iguales->last()['momento'],
            ])
            ->sortByDesc('ultima')
            ->values();
    }
}
