#!/bin/sh
# Arranque del contenedor de aplicación en DESARROLLO.
#
# El código llega por bind mount, así que vendor/ o el enlace de storage pueden
# no existir todavía la primera vez. Este script deja el proyecto utilizable sin
# pasos manuales previos.

set -e

log() { echo "[entrypoint] $*"; }

cd /var/www/html

# --- .env -------------------------------------------------------------------
# Las variables que manda docker-compose (env_file: .env.docker) llegan como
# variables de entorno reales y Laravel les da prioridad sobre este fichero,
# porque carga Dotenv en modo inmutable. El .env sigue haciendo falta igualmente:
# artisan aborta si no encuentra ninguno.
if [ ! -f .env ]; then
    log ".env no existe, lo genero a partir de .env.docker"
    cp .env.docker .env
fi

# --- Dependencias PHP -------------------------------------------------------
# El vendor/ del host se instaló desde Windows; aquí vive en un volumen aparte
# (ver docker-compose.yml), así que la primera vez está vacío.
if [ ! -f vendor/autoload.php ]; then
    log "vendor/ vacío, ejecutando composer install (puede tardar unos minutos)"
    composer install --no-interaction --prefer-dist
fi

# --- Espera a MySQL ---------------------------------------------------------
# El healthcheck de compose cubre el caso normal; este bucle protege el arranque
# en frío, cuando MySQL inicializa el datadir y además ejecuta los scripts de
# docs/sql, que tardan bastante más.
#
# Se comprueba con PDO y no con mysqladmin: valida de una vez que el servidor
# responde, que las credenciales sirven y que la base ya existe.
log "esperando a MySQL en ${DB_HOST:-mysql}:${DB_PORT:-3306}"
i=0
until php -r '
    $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s",
        getenv("DB_HOST") ?: "mysql",
        getenv("DB_PORT") ?: "3306",
        getenv("DB_DATABASE"));
    try { new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD")); }
    catch (Throwable $e) { exit(1); }
' 2>/dev/null; do
    i=$((i + 1))
    if [ "$i" -ge 90 ]; then
        log "ERROR: MySQL no aceptó la conexión tras 90 intentos"
        exit 1
    fi
    sleep 2
done
log "MySQL responde"

# --- Directorios escribibles ------------------------------------------------
mkdir -p storage/framework/cache/data \
         storage/framework/sessions \
         storage/framework/views \
         storage/logs \
         bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true

# --- Enlace de storage ------------------------------------------------------
# Es lo que hace visibles las fotos de los productos.
if [ ! -e public/storage ]; then
    log "creando el enlace public/storage"
    php artisan storage:link
fi

# --- Primer acceso -----------------------------------------------------------
# El esquema y los datos los carga MySQL desde docs/sql al crear el volumen, no
# las migraciones de Laravel. Ese volcado deja un hash de ejemplo que no
# corresponde a ninguna contraseña real, así que hace falta el seeder para
# poder entrar la primera vez. Se puede correr en cada arranque sin cuidado:
# el propio seeder solo toca cuentas que todavía no tienen una contraseña
# real puesta, así que una vez que alguien cambia la suya, reiniciar el
# contenedor no se la vuelve a pisar.
log "asegurando el primer acceso"
php artisan db:seed --class=CredencialesSeeder --force

# En desarrollo la configuración NO se cachea, para que un cambio en
# .env.docker o en config/ surta efecto sin limpiar nada a mano. El
# clear-compiled descarta además lo que el host (Windows) haya dejado en
# bootstrap/cache y que llega hasta aquí por el bind mount.
php artisan clear-compiled
php artisan config:clear
php artisan route:clear
php artisan view:clear

log "configuración efectiva: DB=${DB_USERNAME}@${DB_HOST}:${DB_PORT}/${DB_DATABASE} · APP_URL=${APP_URL}"
log "listo — arrancando: $*"

exec "$@"
