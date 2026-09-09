#!/usr/bin/env bash
#
# Arma el paquete que se sube a InfinityFree: despliegue-demo/htdocs.zip
#
# Existe porque hacerlo a mano ya falló dos veces, y las dos de la misma
# manera: el archivo estaba, pero no llegaba al servidor en condiciones de
# usarse.
#
#   - Un controlador nuevo copiado sin regenerar el autoload dio 500 en
#     producción. El vendor usa `classmap-authoritative`: una clase que no
#     esté en el mapa NO se busca en el disco, sencillamente no existe.
#   - Un zip armado sin recompilar los assets llevaba código nuevo con
#     JavaScript viejo.
#
# Por eso el orden de abajo no es negociable, y por eso el paso 4 COMPRUEBA
# lo que empaquetó en vez de dar por hecho que salió bien.
#
# Uso, desde la raíz del repositorio:
#     bash scripts/armar-demo.sh
#     SALIDA=/ruta/a/despliegue-demo bash scripts/armar-demo.sh   (worktrees)
set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FUENTE="$RAIZ/sistema-ventas"

# Vive fuera del repositorio: lleva el .env de la demo y las fotos de los
# productos, que no se versionan.
SALIDA="${SALIDA:-$RAIZ/../despliegue-demo}"
BUILD="$SALIDA/build/htdocs"

[ -d "$BUILD" ] || {
    echo "no encuentro $BUILD"
    echo "si trabajas en un worktree, pásale la ruta:"
    echo "    SALIDA=/ruta/a/despliegue-demo bash scripts/armar-demo.sh"
    exit 1
}

echo "== 1/6  compilando los assets =========================================="
# En el contenedor, que es donde los node_modules están completos.
docker exec ventas_vite npm run build >/dev/null 2>&1
echo "   $(ls "$FUENTE/public/build/assets" | wc -l) archivos en public/build/assets"

echo "== 2/6  copiando el código ============================================="
# Lo que NO se toca del paquete, y por qué:
#   .env             -> el de la demo (LOGICA_EN_PHP=true, su clave, su base)
#   .htaccess        -> manda las visitas a public/ (InfinityFree sirve htdocs/)
#   composer.json    -> lleva `platform: php 8.3.13`, que el fuente no tiene
#   composer.lock    -> por eso mismo trae symfony/polyfill-php83 de más
#   vendor/          -> instalado contra ese 8.3
for d in app bootstrap config database resources routes; do
    rm -rf "${BUILD:?}/$d"
    cp -r "$FUENTE/$d" "$BUILD/$d"
done
cp "$FUENTE/artisan" "$FUENTE/README.md" "$BUILD/"

# Rutas y vistas compiladas de ESTA máquina no sirven allá.
rm -rf "$BUILD/bootstrap/cache"
mkdir -p "$BUILD/bootstrap/cache"

# Assets recién compilados. El `hot` NO viaja: si lo hace, la demo intenta
# cargar el JavaScript desde localhost:5174 y queda sin Alpine ni estilos.
rm -rf "$BUILD/public/build"
cp -r "$FUENTE/public/build" "$BUILD/public/build"
rm -f "$BUILD/public/hot"

# Las fotos: en el servidor son una carpeta real, porque allá no hay symlinks.
mkdir -p "$BUILD/public/storage"
cp -r "$FUENTE/storage/app/public/." "$BUILD/public/storage/"

echo "== 3/6  regenerando el autoload ========================================"
( cd "$BUILD" && composer dump-autoload --classmap-authoritative --no-dev --quiet )
echo "   $(grep -c '=> ' "$BUILD/vendor/composer/autoload_classmap.php") clases en el mapa"

echo "== 4/6  comprobando que lo nuevo llegó ================================="
FALTA=0

while read -r archivo; do
    [ -e "$BUILD/$archivo" ] || { echo "   FALTA  $archivo"; FALTA=1; }
done <<'LISTA'
app/Http/Controllers/InventarioController.php
app/Http/Controllers/CobroQrController.php
app/Services/CobrosQr.php
app/Services/Qr/QrSimulado.php
app/Models/CobroQr.php
config/qr.php
resources/js/qr.js
resources/views/inventario/index.blade.php
LISTA

# Estar en el disco no alcanza: la clase tiene que estar en el MAPA, porque
# con `classmap-authoritative` lo que no figura ahí no se busca en el disco.
# Se comprueba cargando el mapa con PHP y no con grep: en el archivo las
# barras van dobles, y escaparlas a través del shell es pedir un falso
# negativo — ya me pasó una vez.
php -r '
$mapa = require $argv[1]."/vendor/composer/autoload_classmap.php";
$faltan = 0;
foreach (array_slice($argv, 2) as $clase) {
    if (! isset($mapa[$clase])) { echo "   NO ESTÁ EN EL AUTOLOAD  $clase
"; $faltan++; }
}
exit($faltan > 0 ? 1 : 0);
' "$BUILD"     'App\Http\Controllers\InventarioController'     'App\Http\Controllers\CobroQrController'     'App\Services\CobrosQr'     'App\Services\Qr\QrSimulado'     'App\Models\CobroQr' || FALTA=1

[ -e "$BUILD/public/hot" ] && {
    echo "   VIAJA public/hot — la demo cargaría el JavaScript de localhost"
    FALTA=1
}

# El manifiesto tiene que nombrar archivos que existan de verdad.
php -r '
$b = $argv[1];
foreach (json_decode(file_get_contents("$b/public/build/manifest.json"), true) as $e) {
    if (! file_exists("$b/public/build/".$e["file"])) {
        echo "   FALTA EL ASSET ".$e["file"]."\n";
        exit(1);
    }
}' "$BUILD" || FALTA=1

if [ "$FALTA" -eq 0 ]; then
    echo "   todo en su sitio"
else
    echo "   paquete incompleto: no se arma el zip"
    exit 1
fi

echo "== 5/6  armando el zip ================================================="
php "$RAIZ/scripts/zipear.php" "$BUILD" "$SALIDA/htdocs.zip"

echo "== 6/6  listo =========================================================="
