<?php

/*
 * Empaqueta una carpeta con barras normales (/) en los nombres.
 *
 * `Compress-Archive` de Windows las escribe invertidas, y al descomprimir en
 * el servidor Linux se convierten en nombres de archivo literales
 * («app\Http\Controllers\…» como UN archivo) en vez de carpetas.
 *
 * Uso:  php scripts/zipear.php <carpeta> <destino.zip>
 */

$origen = rtrim($argv[1] ?? '', "/\\");
$destino = $argv[2] ?? '';

if ($origen === '' || $destino === '' || ! is_dir($origen)) {
    exit("uso: php scripts/zipear.php <carpeta> <destino.zip>\n");
}

@unlink($destino);

$zip = new ZipArchive;

if ($zip->open($destino, ZipArchive::CREATE) !== true) {
    exit("no pude crear el zip\n");
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($origen, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$archivos = 0;

foreach ($it as $ruta => $info) {
    $relativa = str_replace(DIRECTORY_SEPARATOR, '/', substr($ruta, strlen($origen) + 1));

    if ($info->isDir()) {
        $zip->addEmptyDir($relativa);
    } else {
        $zip->addFile($ruta, $relativa);
        $archivos++;
    }
}

$zip->close();

printf("   %d archivos, %.1f MB\n", $archivos, filesize($destino) / 1048576);
