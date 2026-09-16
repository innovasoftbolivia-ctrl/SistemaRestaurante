#!/usr/bin/env bash
#
# Aplica a una base los parches de docs/sql/parches que todavía no tiene, y
# deja constancia de cuáles ya corrieron — para no tener que acordarse a mano
# qué le falta a cada instalación.
#
#   ./scripts/aplicar-parches.sh              # solo muestra qué falta (no toca nada)
#   ./scripts/aplicar-parches.sh --aplicar     # aplica lo pendiente, en orden
#   ./scripts/aplicar-parches.sh --aplicar --forzar   # incluso los que van fuera de orden
#   BASE=ventas_db_cliente2 ./scripts/aplicar-parches.sh --aplicar
#
# Importante — no todos los parches son para todas las instalaciones:
#
#   * Los que CORRIGEN una regla de negocio o el esquema (p. ej. cómo se
#     calcula el efectivo esperado) ya quedan incorporados en
#     docs/sql/01_schema_mysql.sql: una base creada HOY con ese archivo no
#     necesita volver a aplicarlos, y este script los deja marcados como
#     aplicados solos la primera vez que corre contra una base así (no
#     encuentra nada que corregir y el parche está escrito para no fallar
#     en ese caso — son idempotentes).
#
#   * Los que cargan CATÁLOGO (abarrotes_catalogo_real, bebidas_catalogo_real,
#     categoria_cigarrillos, sin_impuesto) son datos concretos de ESTE negocio
#     de referencia. Una instalación para un cliente nuevo casi seguro NO los
#     quiere —le meterían el catálogo de otro negocio—, así que revisa la
#     lista de pendientes antes de aplicar con --aplicar, no lo hagas a ciegas.
#
# El registro de qué se aplicó vive en la propia base, en la tabla
# `parches_aplicados` (se crea sola la primera vez que corre este script).

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

CONTENEDOR="ventas_mysql"
BASE="${BASE:-ventas_db}"
DIRECTORIO="docs/sql/parches"
APLICAR=0
FORZAR=0

for arg in "$@"; do
    [ "$arg" = "--aplicar" ] && APLICAR=1
    [ "$arg" = "--forzar" ] && FORZAR=1
done

if [ -f .env ]; then
    DB_PASSWORD="$(grep -E '^DB_PASSWORD=' .env | tail -1 | cut -d= -f2-)"
fi
DB_PASSWORD="${DB_PASSWORD:-ventas123}"

if ! docker inspect "$CONTENEDOR" >/dev/null 2>&1; then
    echo "No encuentro el contenedor $CONTENEDOR. ¿Está levantado \`docker compose\`?" >&2
    exit 1
fi

mysql() {
    docker exec -i "$CONTENEDOR" mysql --default-character-set=utf8mb4 -uroot -p"$DB_PASSWORD" "$@"
}

mysql "$BASE" <<'SQL'
CREATE TABLE IF NOT EXISTS parches_aplicados (
    archivo     VARCHAR(150) NOT NULL PRIMARY KEY,
    aplicado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
SQL

PENDIENTES=()
for ruta in "$DIRECTORIO"/*.sql; do
    archivo="$(basename "$ruta")"
    ya="$(mysql -N "$BASE" -e "SELECT 1 FROM parches_aplicados WHERE archivo = '${archivo//\'/\'\'}'" 2>/dev/null || true)"
    [ -z "$ya" ] && PENDIENTES+=("$archivo")
done

# Los parches se aplican en orden de nombre, y varios reemplazan el mismo
# procedimiento: aplicar uno con fecha ANTERIOR a la del último ya aplicado deja
# la versión vieja en la base. Pasa en cuanto alguien copia un parche suelto de
# otra rama. Se compara solo la fecha (los 10 primeros caracteres): varios
# parches del mismo día son lo normal y se aplican entre ellos por nombre.
ULTIMO="$(mysql -N "$BASE" -e "SELECT archivo FROM parches_aplicados ORDER BY archivo DESC LIMIT 1" 2>/dev/null || true)"
FUERA_DE_ORDEN=()
if [ -n "$ULTIMO" ]; then
    for archivo in "${PENDIENTES[@]}"; do
        [[ "${archivo:0:10}" < "${ULTIMO:0:10}" ]] && FUERA_DE_ORDEN+=("$archivo")
    done
fi

if [ "${#PENDIENTES[@]}" -eq 0 ]; then
    echo "«$BASE» ya tiene todos los parches registrados."
    exit 0
fi

echo "Pendientes en «$BASE» (${#PENDIENTES[@]}):"
for archivo in "${PENDIENTES[@]}"; do
    echo "  - $archivo"
done

if [ "${#FUERA_DE_ORDEN[@]}" -gt 0 ]; then
    echo
    echo "AVISO: estos parches son anteriores al último aplicado ($ULTIMO):"
    for archivo in "${FUERA_DE_ORDEN[@]}"; do
        echo "  - $archivo"
    done
    echo "Aplicarlos puede reemplazar un procedimiento por una versión vieja."
    echo "Revísalos y, si de verdad hacen falta, corre con --forzar."
fi

if [ "$APLICAR" -eq 0 ]; then
    echo
    echo "Nada se tocó. Corre con --aplicar para aplicarlos, en este orden."
    exit 0
fi

if [ "${#FUERA_DE_ORDEN[@]}" -gt 0 ] && [ "$FORZAR" -eq 0 ]; then
    echo
    echo "No se aplicó nada: hay parches fuera de orden. Añade --forzar si estás seguro." >&2
    exit 1
fi

echo
for archivo in "${PENDIENTES[@]}"; do
    echo "Aplicando $archivo..."
    mysql "$BASE" < "$DIRECTORIO/$archivo"
    mysql "$BASE" -e "INSERT INTO parches_aplicados (archivo) VALUES ('${archivo//\'/\'\'}')"
    echo "  listo."
done

echo "Aplicados ${#PENDIENTES[@]} parche(s) en «$BASE»."
