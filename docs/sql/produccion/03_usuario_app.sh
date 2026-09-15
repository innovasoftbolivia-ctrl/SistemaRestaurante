#!/bin/bash
# =============================================================================
#  Cuenta de MySQL con permisos mínimos para la aplicación (producción)
#
#  Lo corre el contenedor de MySQL una sola vez, al crear la base, después del
#  esquema y los datos base. La aplicación no necesita crear ni borrar tablas:
#  lee y escribe datos, llama a los procedimientos y lee su definición para
#  los respaldos. Conectada como root, un fallo de seguridad en la aplicación
#  tendría el servidor de base de datos entero.
#
#  Los parches (`scripts/aplicar-parches.sh`), el respaldo y la restauración
#  por consola siguen usando root desde el propio contenedor.
#
#  Para una instalación que ya existía: scripts/crear-usuario-app.sh
#
#  Sin `set -e` ni `exit`, a propósito: si el archivo llega sin permiso de
#  ejecución, el contenedor de MySQL lo carga dentro de su propio script de
#  arranque, y un `exit` cortaría la inicialización de la base entera.
# =============================================================================

if [ -z "${DB_APP_PASSWORD:-}" ]; then
    echo "AVISO: DB_APP_PASSWORD vacía; no se crea ventas_app y la aplicación seguirá con root." >&2
elif [[ "$DB_APP_PASSWORD" == *"'"* || "$DB_APP_PASSWORD" == *\\* ]]; then
    echo "ERROR: DB_APP_PASSWORD no puede llevar comillas simples ni barras invertidas; no se crea ventas_app." >&2
else
    mysql --protocol=socket -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
CREATE USER IF NOT EXISTS 'ventas_app'@'%' IDENTIFIED BY '${DB_APP_PASSWORD}';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE, SHOW VIEW, TRIGGER, LOCK TABLES
    ON ventas_db.* TO 'ventas_app'@'%';
-- Leer el cuerpo de los procedimientos, para que el respaldo desde la
-- aplicación los incluya.
GRANT SHOW_ROUTINE ON *.* TO 'ventas_app'@'%';
SQL
    echo "Cuenta ventas_app creada con permisos mínimos sobre ventas_db."
fi
