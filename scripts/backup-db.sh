#!/usr/bin/env bash
#
# Copia de seguridad del Sistema de Restaurante: la base Y las fotos de producto.
#
#   ./scripts/backup-db.sh
#
# Guarda en backups/, con fecha y hora en el nombre, y borra lo que tenga más
# de RETENTION_DAYS (14 por omisión). Pensado para correr desde cron en el
# servidor donde vive `docker compose`:
#
#   # todas las noches a la 1:00
#   0 1 * * *  cd /ruta/al/proyecto && ./scripts/backup-db.sh >> backups/backup.log 2>&1
#
# Genera DOS archivos por corrida:
#   ventas_db_<fecha>.sql.gz     el volcado de la base
#   ventas_fotos_<fecha>.tar.gz  storage/app/public (fotos de producto)
#
# Las fotos van aparte y no dentro del volcado porque no viven en la base:
# la base solo guarda el nombre del archivo. Respaldar únicamente el SQL deja
# un catálogo entero sin imágenes el día que haya que recuperar.
#
# `--routines --triggers --events`: el esquema apoya reglas de negocio en
# procedimientos almacenados (sp_cerrar_caja, sp_recalcular_venta...) y en
# triggers (kardex, comprobantes). Un volcado sin estas tres banderas se ve
# completo pero, al restaurarlo, esa lógica no vuelve — silenciosamente.
#
# `--single-transaction`: MySQL sigue aceptando ventas mientras se hace la
# copia; no hace falta parar el sistema para respaldarlo.
#
# Variables que acepta:
#   RETENTION_DAYS    días que se conservan las copias (14)
#   VENTAS_MYSQL      nombre del contenedor de MySQL (se autodetecta)
#   VENTAS_APP        nombre del contenedor de la aplicación (se autodetecta)
#   DESTINO_EXTERNO   carpeta fuera de este servidor (disco USB, montaje de
#                     red...) donde copiar también cada respaldo. Ver la nota
#                     al final del script: sin esto, la copia muere con el
#                     mismo disco que pretende proteger.

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

BASE="ventas_db"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
DESTINO="backups"

# El stack de producción nombra sus contenedores con el sufijo `_prod` (ver
# docker-compose.prod.yml) y el de desarrollo sin él. Se busca primero el de
# producción: si un servidor tuviera ambos, el que hay que respaldar es ese.
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

if ! CONTENEDOR="$(detectar "${VENTAS_MYSQL:-}" ventas_mysql_prod restaurante_mysql)"; then
    echo "No encuentro el contenedor de MySQL (ventas_mysql_prod ni restaurante_mysql)." >&2
    echo "¿Está levantado \`docker compose\`? Si usa otro nombre: VENTAS_MYSQL=... $0" >&2
    exit 1
fi

# La misma contraseña que usan los compose para MYSQL_ROOT_PASSWORD.
if [ -f .env ]; then
    DB_PASSWORD="$(grep -E '^DB_PASSWORD=' .env | tail -1 | cut -d= -f2-)"
fi
DB_PASSWORD="${DB_PASSWORD:-ventas123}"

mkdir -p "$DESTINO"

MARCA="$(date +%Y%m%d_%H%M%S)"
ARCHIVO="$DESTINO/${BASE}_${MARCA}.sql.gz"
TEMPORAL="${ARCHIVO}.tmp"

docker exec "$CONTENEDOR" \
    mysqldump --single-transaction --routines --triggers --events \
        --default-character-set=utf8mb4 -uroot -p"$DB_PASSWORD" "$BASE" \
    | gzip > "$TEMPORAL"

# --- Comprobación del volcado -------------------------------------------------
# Un respaldo que "existe" pero está truncado es peor que no tener ninguno:
# da tranquilidad hasta el día que hace falta. Se comprueban las dos formas
# realistas de que salga mal —el gzip corrupto y el volcado cortado a la
# mitad (disco lleno, contenedor caído en medio)—: mysqldump cierra siempre
# con la línea «Dump completed», así que si no está, el archivo no sirve.
if ! gzip -t "$TEMPORAL" 2>/dev/null; then
    rm -f "$TEMPORAL"
    echo "ERROR: el volcado salió corrupto (gzip no lo puede leer). No se guardó nada." >&2
    exit 1
fi

if ! gunzip -c "$TEMPORAL" | tail -5 | grep -q "Dump completed"; then
    rm -f "$TEMPORAL"
    echo "ERROR: el volcado está incompleto (falta la marca final de mysqldump)." >&2
    echo "       Suele ser disco lleno o el contenedor cortado a mitad de la copia." >&2
    exit 1
fi

mv "$TEMPORAL" "$ARCHIVO"
echo "Base guardada en $ARCHIVO ($(du -h "$ARCHIVO" | cut -f1))"

# --- Fotos de producto ---------------------------------------------------------
# Viven en storage/app/public: en producción es un volumen de Docker y en
# desarrollo un bind mount, así que se sacan desde dentro del contenedor —
# funciona igual en los dos casos, sin tener que saber cuál es.
FOTOS="$DESTINO/ventas_fotos_${MARCA}.tar.gz"

if APP="$(detectar "${VENTAS_APP:-}" ventas_app_prod restaurante_app ventas_app)"; then
    if docker exec "$APP" tar -czf - -C storage/app public > "${FOTOS}.tmp" 2>/dev/null; then
        mv "${FOTOS}.tmp" "$FOTOS"
        echo "Fotos guardadas en $FOTOS ($(du -h "$FOTOS" | cut -f1))"
    else
        rm -f "${FOTOS}.tmp"
        echo "AVISO: no se pudieron respaldar las fotos de producto." >&2
    fi
else
    echo "AVISO: no encuentro el contenedor de la aplicación; las fotos de producto" >&2
    echo "       NO se respaldaron. La base sí." >&2
fi

# --- Copia fuera de este servidor ----------------------------------------------
# Una copia en el mismo disco que la base no protege del caso más común de
# todos: que ese disco muera. Con DESTINO_EXTERNO apuntando a un disco USB o
# a un montaje de red, cada respaldo se duplica ahí.
if [ -n "${DESTINO_EXTERNO:-}" ]; then
    if mkdir -p "$DESTINO_EXTERNO" 2>/dev/null && cp "$ARCHIVO" "$DESTINO_EXTERNO/" 2>/dev/null; then
        [ -f "$FOTOS" ] && cp "$FOTOS" "$DESTINO_EXTERNO/" 2>/dev/null || true
        echo "Copiado también a $DESTINO_EXTERNO"
    else
        echo "ERROR: no se pudo copiar a DESTINO_EXTERNO ($DESTINO_EXTERNO)." >&2
        echo "       El respaldo local sí quedó, pero sigue en el mismo disco." >&2
        exit 1
    fi
fi

# --- Limpieza -------------------------------------------------------------------
# Solo entra aquí lo que este script generó, por el nombre.
BORRADOS=0
while IFS= read -r -d '' viejo; do
    rm -f "$viejo"
    BORRADOS=$((BORRADOS + 1))
done < <(find "$DESTINO" -maxdepth 1 \( -name "${BASE}_*.sql.gz" -o -name "ventas_fotos_*.tar.gz" \) -mtime "+${RETENTION_DAYS}" -print0)

if [ "$BORRADOS" -gt 0 ]; then
    echo "Se borraron $BORRADOS copias de más de $RETENTION_DAYS días."
fi
