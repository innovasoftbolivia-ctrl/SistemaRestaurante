#!/usr/bin/env bash
#
# Restaura una copia de seguridad generada por backup-db.sh.
#
#   ./scripts/restore-db.sh backups/ventas_db_20260901_010000.sql.gz
#
# SOBRESCRIBE la base actual por completo. Pide confirmación escrita antes de
# tocar nada; con CONFIRMAR=si se salta la pregunta (útil para automatizar
# una restauración en un servidor nuevo, no para el uso normal).
#
# Si al lado del volcado hay un `ventas_fotos_<misma fecha>.tar.gz`, también
# repone las fotos de producto. Con SIN_FOTOS=si se restaura solo la base.
#
# Variables que acepta:
#   CONFIRMAR=si    no preguntar
#   SIN_FOTOS=si    restaurar solo la base
#   RESTAURANTE_MYSQL    nombre del contenedor de MySQL (se autodetecta)
#   RESTAURANTE_APP      nombre del contenedor de la aplicación (se autodetecta)

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

BASE="ventas_db"
ARCHIVO="${1:-}"

if [ -z "$ARCHIVO" ] || [ ! -f "$ARCHIVO" ]; then
    echo "Uso: $0 <archivo-de-copia.sql.gz>" >&2
    echo "Copias disponibles en backups/:" >&2
    ls -1 backups/*.sql.gz 2>/dev/null >&2 || echo "  (ninguna)" >&2
    exit 1
fi

# Mismo criterio que backup-db.sh: producción primero.
detectar() {
    local explicito="$1"; shift
    if [ -n "$explicito" ]; then echo "$explicito"; return 0; fi
    # Primero uno que esté CORRIENDO, en el orden de la lista: `docker inspect`
    # también responde por un contenedor detenido, y en una máquina con los
    # dos stacks de desarrollo el de ventas parado le ganaba al de restaurante
    # levantado. Si ninguno corre, el primero que exista, para que el error
    # siguiente diga de cuál se trata.
    for nombre in "$@"; do
        if [ "$(docker inspect -f '{{.State.Running}}' "$nombre" 2>/dev/null)" = "true" ]; then echo "$nombre"; return 0; fi
    done
    for nombre in "$@"; do
        if docker inspect "$nombre" >/dev/null 2>&1; then echo "$nombre"; return 0; fi
    done
    return 1
}

if ! CONTENEDOR="$(detectar "${RESTAURANTE_MYSQL:-}" restaurante_mysql_prod restaurante_mysql)"; then
    echo "No encuentro el contenedor de MySQL (restaurante_mysql_prod ni restaurante_mysql)." >&2
    echo "¿Está levantado \`docker compose\`? Si usa otro nombre: RESTAURANTE_MYSQL=... $0" >&2
    exit 1
fi

if [ -f .env ]; then
    DB_PASSWORD="$(grep -E '^DB_PASSWORD=' .env | tail -1 | cut -d= -f2-)"
fi
DB_PASSWORD="${DB_PASSWORD:-ventas123}"

# La copia de fotos que corresponde a este volcado, si está: mismo sufijo de
# fecha y hora, que es justamente para lo que backup-db.sh lo pone.
FOTOS=""
if [ "${SIN_FOTOS:-}" != "si" ]; then
    CANDIDATA="$(dirname "$ARCHIVO")/$(basename "$ARCHIVO" | sed -E 's/^ventas_db_(.+)\.sql\.gz$/ventas_fotos_\1.tar.gz/')"
    [ -f "$CANDIDATA" ] && FOTOS="$CANDIDATA"
fi

if [ "${CONFIRMAR:-}" != "si" ]; then
    echo "Esto BORRA y reemplaza todo el contenido de «$BASE» con «$ARCHIVO»."
    [ -n "$FOTOS" ] && echo "También repone las fotos de producto desde «$FOTOS»."
    read -r -p "Escribe SI (en mayúsculas) para continuar: " respuesta
    if [ "$respuesta" != "SI" ]; then
        echo "Cancelado. No se tocó la base."
        exit 1
    fi
fi

# El volcado de mysqldump no trae `CREATE DATABASE` ni `USE`: da por hecho
# que la base ya existe y que se la eligió al invocar `mysql`. Eso vale para
# reponer datos sobre una instalación que sigue en pie, pero NO para el caso
# que de verdad importa —el servidor se perdió y hay que levantar todo de
# cero—, donde el MySQL nuevo está vacío y la restauración moría con
# «Unknown database 'ventas_db'». Crearla aquí es lo que convierte la copia
# en algo que realmente se puede recuperar.
#
# El juego de caracteres y la colación se fijan iguales a los de
# docker-compose: una base creada con los valores por omisión del servidor
# ordenaría y compararía los acentos de otra manera.
docker exec "$CONTENEDOR" mysql -uroot -p"$DB_PASSWORD" \
    -e "CREATE DATABASE IF NOT EXISTS \`$BASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"

gunzip -c "$ARCHIVO" | docker exec -i "$CONTENEDOR" \
    mysql --default-character-set=utf8mb4 -uroot -p"$DB_PASSWORD" "$BASE"

echo "Restaurado «$BASE» desde $ARCHIVO."

if [ -n "$FOTOS" ]; then
    if APP="$(detectar "${RESTAURANTE_APP:-}" restaurante_app_prod restaurante_app ventas_app)"; then
        gunzip -c "$FOTOS" | docker exec -i "$APP" tar -xf - -C storage/app
        echo "Repuestas las fotos de producto desde $FOTOS."
    else
        echo "AVISO: no encuentro el contenedor de la aplicación; las fotos NO se" >&2
        echo "       repusieron. La base sí. Archivo pendiente: $FOTOS" >&2
    fi
fi
