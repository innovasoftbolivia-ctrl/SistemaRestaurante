#!/usr/bin/env bash
#
# Crea (o actualiza) `ventas_app`, la cuenta de MySQL con permisos mínimos con
# la que se conecta la aplicación en producción, en una instalación que ya
# estaba corriendo con root.
#
#   ./scripts/crear-usuario-app.sh
#
# Toma DB_PASSWORD (root) y DB_APP_PASSWORD del .env de la raíz. Después hay
# que poner en sistema-ventas/.env.docker:
#
#   DB_USERNAME=ventas_app
#   DB_PASSWORD=<DB_APP_PASSWORD>
#
# y recrear la aplicación: docker compose -f docker-compose.prod.yml up -d
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

leer() { grep -E "^$1=" .env 2>/dev/null | tail -1 | cut -d= -f2- || true; }

DB_PASSWORD="${DB_PASSWORD:-$(leer DB_PASSWORD)}"
DB_APP_PASSWORD="${DB_APP_PASSWORD:-$(leer DB_APP_PASSWORD)}"

if [ -z "$DB_APP_PASSWORD" ]; then
    echo "Falta DB_APP_PASSWORD en el .env de la raíz." >&2
    exit 1
fi

CONTENEDOR="${VENTAS_MYSQL:-}"
if [ -z "$CONTENEDOR" ]; then
    for c in ventas_mysql_prod ventas_mysql; do
        if docker ps --format '{{.Names}}' | grep -qx "$c"; then CONTENEDOR="$c"; break; fi
    done
fi
if [ -z "$CONTENEDOR" ]; then
    echo "No encuentro el contenedor de MySQL (ventas_mysql_prod ni ventas_mysql)." >&2
    exit 1
fi

case "$DB_APP_PASSWORD" in
    *\'*|*\\*) echo "DB_APP_PASSWORD no puede llevar comillas simples ni barras invertidas." >&2; exit 1 ;;
esac
CLAVE="$DB_APP_PASSWORD"

docker exec -i "$CONTENEDOR" mysql -uroot -p"$DB_PASSWORD" <<SQL
CREATE USER IF NOT EXISTS 'ventas_app'@'%' IDENTIFIED BY '${CLAVE}';
ALTER USER 'ventas_app'@'%' IDENTIFIED BY '${CLAVE}';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE, SHOW VIEW, TRIGGER, LOCK TABLES
    ON ventas_db.* TO 'ventas_app'@'%';
-- Leer el cuerpo de los procedimientos, para que el respaldo desde la
-- aplicación los incluya.
GRANT SHOW_ROUTINE ON *.* TO 'ventas_app'@'%';
SQL

echo "Listo: ventas_app en $CONTENEDOR. Cambia DB_USERNAME/DB_PASSWORD en sistema-ventas/.env.docker y recrea la aplicación."
