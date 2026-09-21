# syntax=docker/dockerfile:1.7
#
# Imagen de DESARROLLO del Sistema de Restaurante: php-fpm con el código montado
# desde el host. No hay stage de producción porque este entorno solo cubre el
# desarrollo local; el despliegue no está contemplado todavía.
#
#   docker compose up -d --build     (desde la raíz del repositorio)

FROM php:8.4-fpm-alpine

# install-php-extensions resuelve por su cuenta las librerías del sistema que
# necesita cada extensión, y evita una lista larga y frágil de `apk add`.
COPY --from=mlocati/php-extension-installer:latest \
     /usr/bin/install-php-extensions /usr/local/bin/

# pdo_mysql -> la base ventas_db
# mbstring  -> nombres con acentos en productos, clientes y comprobantes
# bcmath    -> importes y totales sin errores de coma flotante
# intl      -> formato de fechas y números en es-PE (APP_LOCALE=es)
# gd        -> fotos de producto
# zip       -> lo usa Composer para instalar desde caché
# opcache   -> arranque razonable en cada petición
RUN install-php-extensions \
        pdo_mysql \
        mbstring \
        bcmath \
        intl \
        gd \
        zip \
        opcache

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# El cliente de MySQL permite recargar el esquema desde dentro del contenedor
# (`docker compose exec app mysql ...`) sin depender de tenerlo en el host.
# mariadb-connector-c trae el plugin caching_sha2_password, el método de
# autenticación por defecto de MySQL 8; sin él la conexión muere al saludar.
# rclone sube cada respaldo a Google Drive (ver App\Services\Respaldos).
RUN apk add --no-cache mariadb-client mariadb-connector-c rclone

WORKDIR /var/www/html

COPY docker/php/php.dev.ini /usr/local/etc/php/conf.d/zz-ventas.ini
COPY docker/php/entrypoint.dev.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Sin COPY del código: docker-compose.yml lo monta como bind mount para que los
# cambios se vean sin reconstruir la imagen.
#
# El contenedor corre como root a propósito: así los ficheros que Laravel genere
# dentro del bind mount (logs, vistas compiladas) no chocan con los permisos del
# host. En desarrollo local el riesgo es asumible.

EXPOSE 9000

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
