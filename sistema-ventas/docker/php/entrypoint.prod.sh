#!/bin/sh
# Arranque del contenedor de aplicación en PRODUCCIÓN.
#
# A diferencia de entrypoint.dev.sh: no instala Composer (vendor/ ya viene
# horneado en la imagen), no copia .env.docker a .env (las credenciales
# llegan como variables de entorno reales vía `env_file`, y Laravel las lee
# igual sin necesitar el fichero — `Dotenv::...->safeLoad()` no exige que
# exista), y sí cachea config/rutas/vistas, porque aquí el código no cambia
# bajo los pies entre una petición y la siguiente.

set -e

log() { echo "[entrypoint] $*"; }

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
    log "ERROR: vendor/autoload.php no existe. Esta imagen se construye con"
    log "       las dependencias ya instaladas (ver Dockerfile.prod) — si"
    log "       falta, la imagen no se armó bien. No se corre 'composer"
    log "       install' aquí: en producción no hay por qué tocar la red"
    log "       para poder arrancar."
    exit 1
fi

# --- Dueño de lo que llega por volumen ---------------------------------------
# `storage/app/public` (fotos de producto) y `storage/logs` viven en
# volúmenes aparte (ver docker-compose.prod.yml), no horneados en la imagen:
# un volumen nuevo hereda el dueño de lo que había ahí en la imagen, pero uno
# ya existente de una instalación vieja puede seguir siendo de root. Se
# corrige aquí, mientras el entrypoint todavía corre como root, para que los
# workers de php-fpm (que corren como www-data) puedan escribir en ellos.
chown -R www-data:www-data storage/app/public storage/logs 2>/dev/null || true

# --- Espera a MySQL ---------------------------------------------------------
# El healthcheck de compose cubre el caso normal; este bucle protege el
# arranque en frío, cuando MySQL inicializa el datadir y además ejecuta los
# scripts de docs/sql, que tardan bastante más.
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

# --- Enlace de storage --------------------------------------------------------
# Lo que hace visibles las fotos de los productos dentro de este contenedor.
# nginx sirve `public/` desde el disco del host por su cuenta (ver
# docker-compose.prod.yml y el comentario en Dockerfile.prod): este enlace
# es para PHP, que sí corre sobre el `public/` horneado en la imagen.
if [ ! -e public/storage ]; then
    log "creando el enlace public/storage"
    su-exec www-data php artisan storage:link
fi

# --- Primer acceso -------------------------------------------------------------
# El esquema y los datos los carga MySQL desde docs/sql al crear el volumen,
# no las migraciones de Laravel. Ese volcado deja un hash de ejemplo que no
# corresponde a ninguna contraseña real, así que hace falta el seeder para
# poder entrar la primera vez. Se puede correr en cada arranque sin cuidado:
# el propio seeder solo toca cuentas que todavía no tienen una contraseña
# real puesta, así que una vez que alguien cambia la suya, reiniciar el
# contenedor no se la vuelve a pisar.
log "asegurando el primer acceso"
su-exec www-data php artisan db:seed --class=CredencialesSeeder --force

# --- Cachés --------------------------------------------------------------------
# Aquí sí, a diferencia de desarrollo: el código no cambia entre una
# petición y la siguiente, así que cachear config/rutas/vistas ahorra
# recorrer config/ y routes/ en cada una. Se hace en el arranque del
# contenedor —con las variables de entorno reales ya cargadas— y NO en la
# construcción de la imagen: cachear en el build horquillaría en la caché
# secretos que en ese momento ni existen todavía (APP_KEY, DB_PASSWORD, ...
# llegan recién al arrancar el contenedor, vía `env_file`).
log "cacheando configuración, rutas y vistas"
su-exec www-data php artisan config:cache
su-exec www-data php artisan route:cache
su-exec www-data php artisan view:cache

if [ "${APP_ENV:-}" = "production" ] && [ "${DB_USERNAME:-}" = "root" ]; then
    log "AVISO: la aplicación se está conectando a MySQL como root. Crea la cuenta con permisos"
    log "       mínimos (scripts/crear-usuario-app.sh) y pon DB_USERNAME=ventas_app en .env.docker."
fi

case "${APP_URL:-}" in
    http://*)
        if [ "${APP_ENV:-}" = "production" ]; then
            log "AVISO: APP_URL usa http://. Con HTTPS delante, ponlo en https:// para que la cookie"
            log "       de sesión no viaje en claro."
        fi
        ;;
esac

log "configuración efectiva: DB=${DB_USERNAME}@${DB_HOST}:${DB_PORT}/${DB_DATABASE} · APP_URL=${APP_URL}"
log "listo — arrancando: $*"

# Sin su-exec aquí, a propósito: el master de php-fpm necesita seguir siendo
# root para poder levantar cada worker como www-data (el usuario del pool, ver
# php-fpm.d/www.conf). El código de la aplicación lo ejecutan esos workers,
# nunca el master.
exec "$@"
