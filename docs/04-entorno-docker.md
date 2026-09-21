# 4. Entorno de desarrollo con Docker

**Proyecto:** Sistema de Restaurante
**Documento:** 04 — Entorno de desarrollo
**Archivos:** [`docker-compose.yml`](../docker-compose.yml) ·
[`docker-compose.yml`](../docker-compose.yml) ·
[`sistema-restaurante/Dockerfile`](../sistema-restaurante/Dockerfile) ·
[`sistema-restaurante/.env.docker`](../sistema-restaurante/.env.docker) ·
[`scripts/aplicar-parches.sh`](../scripts/aplicar-parches.sh)

---

## 4.1 Los dos entornos

El repositorio tiene dos archivos de Docker Compose: `docker-compose.yml` para desarrollo y
`docker-compose.prod.yml` para el servidor. No comparten contenedores ni volúmenes.

| | **Desarrollo** | **Producción** |
|---|---|---|
| Archivo | `docker-compose.yml` | `docker-compose.prod.yml` |
| Proyecto de Compose | `restaurante-dev` | `restaurante-prod` |
| Contenedores | `restaurante_app`, `restaurante_nginx`, `restaurante_mysql`, `restaurante_vite`, `restaurante_adminer` | `restaurante_app_prod`, `restaurante_nginx_prod`, `restaurante_mysql_prod`, `restaurante_programador_prod` |
| Código | montado desde el host (`./sistema-restaurante`) | horneado en la imagen |
| Aplicación | http://localhost:8200 | el dominio del local, por HTTPS |
| MySQL desde el host | 127.0.0.1:13311 | no se publica |
| Pruebas (`phpunit.xml`) | apuntan aquí | — |

## 4.2 Arrancar

Desde la raíz del repositorio:

```bash
docker compose up -d --build
```

La primera vez descarga las imágenes, construye la de PHP, crea los volúmenes, **ejecuta
automáticamente** `docs/sql/01_schema_mysql.sql` y `docs/sql/02_datos_iniciales.sql` (el
entrypoint de MySQL toma los `.sql` de esa carpeta en orden alfabético e ignora las
subcarpetas `parches/` y `produccion/`) e instala las dependencias de Composer y npm dentro
de los contenedores. Tarda unos minutos; se sigue con:

```bash
docker compose logs -f app
```

Cuando `app` y `nginx` quedan `healthy`, la aplicación responde en **http://localhost:8200**.

Cuentas de desarrollo que deja el `CredencialesSeeder`:

| Usuario | Contraseña | Rol | Entra a |
|---------|------------|-----|---------|
| `admin` | `admin123` | Administrador | Inicio |
| `cajero1` | `cajero123` | Cajero | Punto de venta: toma el pedido y lo cobra |
| `cocina1` | `cocina123` | Cocina | Cocina (también la usa quien lleva los platos) |

> Son solo para desarrollo. En producción el seeder genera una contraseña aleatoria para
> `admin`, la muestra una vez en el log del arranque y obliga a cambiarla al entrar.

Datos de conexión a la base desde el host (Workbench, DBeaver):

```
host      127.0.0.1        base      ventas_db
puerto    13311            usuario   root
                           clave     ventas123
```

Adminer no arranca por defecto: es un cliente de base de datos sin autenticación propia y
no tiene por qué estar escuchando todo el tiempo. Cuando haga falta:

```bash
docker compose --profile tools up -d adminer
```

Queda en **http://localhost:8201**, con el servidor `mysql` preseleccionado.

## 4.3 Comandos del día a día

Abrir una consola SQL:

```bash
docker exec -it restaurante_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123 ventas_db
```

Ejecutar una consulta suelta:

```bash
docker exec restaurante_mysql mysql -uroot -pventas123 ventas_db -t -e "SELECT id, jornada, numero_dia, tipo, estado FROM pedidos ORDER BY id DESC LIMIT 10;"
```

Volver a cargar el esquema tras editarlo (**borra y recrea `ventas_db`**):

```bash
docker exec -i restaurante_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123 < docs/sql/01_schema_mysql.sql
```

```bash
docker exec -i restaurante_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123 < docs/sql/02_datos_iniciales.sql
```

> `--default-character-set=utf8mb4` no es opcional: sin él los acentos entran doblemente
> codificados ("Díaz" → "DÃ­az").

Respaldar (incluyendo rutinas y triggers, que son parte del modelo):

```bash
docker exec restaurante_mysql mysqldump -uroot -pventas123 --routines --triggers --single-transaction ventas_db > respaldo.sql
```

Órdenes de artisan y una shell dentro del contenedor de la aplicación:

```bash
docker compose exec app php artisan route:list
```

```bash
docker compose exec app sh
```

Seguir los logs de la aplicación o de la compilación de assets:

```bash
docker compose logs -f app
```

```bash
docker compose logs -f vite
```

Tras cambiar `composer.json` o `package.json` hay que reinstalar dentro del contenedor: las
dependencias viven en volúmenes, no en la carpeta del host.

```bash
docker compose exec app composer install
```

```bash
docker compose exec vite npm install
```

Detener conservando los datos, o borrarlo todo y empezar de cero:

```bash
docker compose down
```

```bash
docker compose down -v
```

> `down -v` elimina el volumen **de este stack**: la próxima vez que arranques, los scripts se
> vuelven a ejecutar desde cero. Es la forma de probar que el esquema carga limpio. La base
> de producción vive en otro stack y no se toca.

## 4.4 Pruebas

La batería de pruebas (578 hoy, en `sistema-restaurante/tests`) corre contra una base
**MySQL real**, `ventas_db_test`, en el MySQL del stack del restaurante. No usa SQLite: el
esquema depende de columnas generadas, `ENUM`, triggers y procedimientos que SQLite no
reproduce.

[`phpunit.xml`](../sistema-restaurante/phpunit.xml) fija la conexión con `force="true"`:

```
DB_HOST=127.0.0.1   DB_PORT=13311   DB_DATABASE=ventas_db_test
DB_USERNAME=root    DB_PASSWORD=ventas123
```

**1. Crear la base de pruebas** (una vez, y de nuevo cada vez que cambie el esquema), desde la
raíz del repositorio:

```bash
sed 's/ventas_db/ventas_db_test/g' docs/sql/01_schema_mysql.sql | docker exec -i restaurante_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123
```

```bash
sed 's/ventas_db/ventas_db_test/g' docs/sql/02_datos_iniciales.sql | docker exec -i restaurante_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123
```

**2. Correr las pruebas con el PHP del host**, desde `sistema-restaurante/`:

```bash
php -d memory_limit=2G vendor/bin/phpunit
```

Cada prueba corre dentro de una transacción que se revierte al terminar, así que los datos
de la base de pruebas quedan intactos.

Cuatro cosas que conviene saber:

- **Se corren desde el host, no desde el contenedor.** Dentro de `restaurante_app`,
  `127.0.0.1:13311` no es alcanzable y las pruebas fallan con un error de conexión. Es a
  propósito: antes del `force="true"`, el contenedor imponía sus propias variables
  (`DB_HOST=mysql`, `DB_DATABASE=ventas_db`) y las pruebas terminaban escribiendo sobre la
  base de desarrollo sin avisar.
- **Apuntan al stack de desarrollo** (127.0.0.1:13311). Sin él levantado, las pruebas no
  conectan.
- **La contraseña está fija en `ventas123`.** Si el `.env` de la raíz cambia `DB_PASSWORD`,
  hay que ajustar también `phpunit.xml`.
- **`memory_limit=2G`** porque la suite completa necesita más memoria que el límite por
  omisión de PHP.

Las pruebas también cubren la vía `LOGICA_EN_PHP=true` (las reglas de la base ejecutadas en
PHP; ver [03-base-datos-mysql.md](03-base-datos-mysql.md) §3.6), para que las dos vías no se
separen. Con esa vía se saltan 4 —las que prueban triggers, rutinas o vistas que en ella no
existen— y pasan las demás.

## 4.5 Parches de la base

Una base creada con `docs/sql/01_schema_mysql.sql` nace completa: **no necesita parches**.
Los parches corrigen el esquema de una instalación que ya está en marcha, y hoy no hay
ninguno pendiente. Cómo se escribe uno está en
[`docs/sql/parches/LEEME.md`](sql/parches/LEEME.md).

El script [`scripts/aplicar-parches.sh`](../scripts/aplicar-parches.sh) compara
`docs/sql/parches/` con la tabla `parches_aplicados` de la base, aplica lo que falta en orden
alfabético y lo registra. Se niega a aplicar un parche con fecha anterior al último aplicado
(salvo `--forzar`), porque podría reemplazar un procedimiento por una versión vieja.

El contenedor de MySQL **lo encuentra solo**: prueba `restaurante_mysql_prod` y
`restaurante_mysql`, en ese orden, y se queda con el primero que esté corriendo (si ninguno
corre, con el primero que exista). `scripts/backup-db.sh`, `scripts/restore-db.sh`,
`scripts/crear-usuario-app.sh` y `scripts/revisar-salud.sh` buscan igual. Solo si el
contenedor se llama de otra forma hace falta `RESTAURANTE_MYSQL=...`. La contraseña de root la
toma de `DB_PASSWORD` en el `.env` de la raíz (o `ventas123`).

**1. Respaldar**, siempre, antes de tocar el esquema de una base con datos:

```bash
./scripts/backup-db.sh
```

**2. Ver qué falta**, sin tocar nada:

```bash
./scripts/aplicar-parches.sh
```

**3. Aplicar** lo pendiente:

```bash
./scripts/aplicar-parches.sh --aplicar
```

Ejemplos con otra base u otro contenedor:

```bash
BASE=ventas_db_test ./scripts/aplicar-parches.sh --aplicar
```

```bash
RESTAURANTE_MYSQL=otro_contenedor ./scripts/aplicar-parches.sh
```

Con `BASE=otra`, el script cambia el `USE ventas_db;` con el que empieza cada parche por la
base pedida: el parche se aplica y se registra en la misma base. (`backup-db.sh` y
`restore-db.sh` trabajan siempre sobre `ventas_db`.)

Los parches son **idempotentes**: una segunda pasada no rompe nada, y sobre una base que ya
tiene el cambio solo informa que no hay nada que hacer.

## 4.6 Dos hallazgos que siguen vigentes

**1. El arqueo de caja descontaba el vuelto dos veces.**
`sp_cerrar_caja` sumaba `monto - vuelto` por cada pago, cuando el efectivo que queda en el
cajón es simplemente `monto` (por definición, `monto_recibido - vuelto = monto`). En la
prueba, la caja cerró con una diferencia que era exactamente el vuelto de la única venta.
Corregido en `sp_cerrar_caja` y en `v_ventas_por_metodo_pago`: el esperado suma `monto`.

**2. El orden de la venta importa.**
`trg_comprobantes_before_insert` exige que lo pagado ya sume el total de la venta. Por eso los
pagos se insertan **antes** de llamar a `sp_emitir_comprobante`, no después: un script que
emite primero y cobra después falla con *"Lo pagado no coincide con el total de la venta"*.
Los ejemplos comentados de la última parte de `02_datos_iniciales.sql` siguen ese orden.
