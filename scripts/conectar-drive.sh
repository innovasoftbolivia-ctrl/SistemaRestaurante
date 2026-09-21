#!/usr/bin/env bash
#
# Conecta los respaldos con una carpeta de Google Drive. Se corre UNA vez por
# servidor (y de nuevo solo si Google retira el permiso: cambio de contraseña
# de la cuenta, acceso revocado, o seis meses sin usarse).
#
#   ./scripts/conectar-drive.sh                  # pide el permiso y lo prueba
#   CARPETA=<id o enlace> ./scripts/conectar-drive.sh
#   VENTAS_APP=otro_contenedor ./scripts/conectar-drive.sh
#   (solo, busca ventas_app_prod y restaurante_app, en ese orden, y prefiere
#   el que esté corriendo)
#
# Cómo funciona. Los respaldos de cada noche (`respaldo:crear`) se suben con
# rclone al remoto «drive», que este script deja configurado con la carpeta de
# Drive como raíz: nada fuera de esa carpeta se toca. El permiso lo da la
# cuenta de Google dueña de la carpeta, en su propio navegador; aquí solo se
# pega el resultado.
#
# Paso previo, en una computadora con navegador (puede ser otra, no el
# servidor):
#
#   1. Instalar rclone: https://rclone.org/downloads/
#      (en Windows: `winget install Rclone.Rclone`).
#   2. Correr `rclone authorize "drive"`, entrar con la cuenta de Google dueña
#      de la carpeta y aceptar.
#   3. Copiar lo que imprime entre las líneas de flechas: una sola línea que
#      empieza con {"access_token": ... Eso es lo que este script pide.
#
# Ese texto da acceso al Drive de la cuenta: no se manda por chat ni por
# correo. Este script lo guarda solo dentro del volumen de la aplicación
# (storage/app/rclone, que no va a git ni a los respaldos).
#
# Al terminar, en sistema-ventas/.env.docker:
#
#   RESPALDOS_NUBE=drive:
#
# y `docker compose -f docker-compose.prod.yml up -d` para que la aplicación
# y el programador lo lean.

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

# La carpeta de Drive de los respaldos del restaurante. Vale el id o el enlace
# entero que da Drive al compartir («.../folders/<id>?usp=...»).
CARPETA="${CARPETA:-188Ea6vghtlX7okMwwkg4B_FZTQ4uRz8E}"
CARPETA="${CARPETA##*/folders/}"
CARPETA="${CARPETA%%\?*}"

REMOTO="drive"
CONFIG="/var/www/html/storage/app/rclone/rclone.conf"

detectar() {
    local explicito="$1"; shift
    if [ -n "$explicito" ]; then echo "$explicito"; return 0; fi
    for nombre in "$@"; do
        if [ "$(docker inspect -f '{{.State.Running}}' "$nombre" 2>/dev/null)" = "true" ]; then echo "$nombre"; return 0; fi
    done
    return 1
}

if ! APP="$(detectar "${VENTAS_APP:-}" ventas_app_prod restaurante_app)"; then
    echo "No encuentro el contenedor de la aplicación corriendo (ventas_app_prod ni restaurante_app)." >&2
    echo "Levanta el stack primero. Si usa otro nombre: VENTAS_APP=... $0" >&2
    exit 1
fi

# Como www-data: es quien corre el programador, y rclone reescribe el archivo
# cada vez que renueva el permiso.
rclone_app() {
    docker exec -u www-data -e RCLONE_CONFIG="$CONFIG" "$APP" rclone "$@"
}

if ! docker exec "$APP" sh -c 'command -v rclone' >/dev/null 2>&1; then
    echo "El contenedor $APP no tiene rclone: reconstruye la imagen (docker compose ... up -d --build)." >&2
    exit 1
fi

echo "Carpeta de Drive: $CARPETA"
echo "Contenedor:       $APP"
echo
echo "Pega lo que imprimió «rclone authorize \"drive\"» (la línea que empieza con {\"access_token\")"
echo "y pulsa Enter. No se muestra en pantalla."
read -rs TOKEN
echo

case "$TOKEN" in
    \{*access_token*\}) ;;
    *)
        echo "Eso no parece el permiso de rclone: tiene que ser la línea completa, de { a }." >&2
        exit 1
        ;;
esac

docker exec -u www-data "$APP" sh -c "mkdir -p '$(dirname "$CONFIG")' && chmod 700 '$(dirname "$CONFIG")'"

# scope=drive y no drive.file: con drive.file rclone solo ve lo que él mismo
# creó, y la carpeta la creó una persona.
rclone_app config create "$REMOTO" drive \
    scope=drive \
    root_folder_id="$CARPETA" \
    token="$TOKEN" \
    --non-interactive >/dev/null
unset TOKEN

echo "Probando: subir, leer y borrar un archivo de prueba..."
PRUEBA="conexion-prueba-$(date +%Y%m%d%H%M%S).txt"
docker exec -u www-data "$APP" sh -c "echo 'Prueba de la conexion de los respaldos. Se borra sola.' > /tmp/$PRUEBA"
if ! rclone_app copyto "/tmp/$PRUEBA" "$REMOTO:$PRUEBA"; then
    echo >&2
    echo "No se pudo escribir en la carpeta. Revisa que la cuenta con la que diste el permiso" >&2
    echo "sea la dueña de la carpeta o tenga permiso de EDITOR en ella." >&2
    exit 1
fi
if [ -z "$(rclone_app lsf "$REMOTO:" --include "$PRUEBA")" ]; then
    echo "El archivo de prueba subió pero no aparece en la carpeta: revisa el id de la carpeta ($CARPETA)." >&2
    exit 1
fi
rclone_app deletefile "$REMOTO:$PRUEBA"
docker exec -u www-data "$APP" rm -f "/tmp/$PRUEBA"

echo
echo "Listo: Drive conectado. Falta, si todavía no está, en sistema-ventas/.env.docker:"
echo
echo "    RESPALDOS_NUBE=${REMOTO}:"
echo
echo "y volver a levantar la aplicación para que lo lea. Para probar un respaldo ya:"
echo
echo "    docker exec -u www-data $APP php artisan respaldo:crear"
