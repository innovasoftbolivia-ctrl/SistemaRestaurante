# 4. Entorno de desarrollo con Docker

**Proyecto:** Sistema de Restaurante
**Documento:** 04 — Entorno de desarrollo
**Archivos:** [`docker-compose.restaurante.yml`](../docker-compose.restaurante.yml) ·
[`docker-compose.yml`](../docker-compose.yml) ·
[`sistema-ventas/Dockerfile`](../sistema-ventas/Dockerfile) ·
[`sistema-ventas/.env.docker`](../sistema-ventas/.env.docker) ·
[`scripts/aplicar-parches.sh`](../scripts/aplicar-parches.sh)

---

## 4.1 Dos stacks en el mismo repositorio

El repositorio tiene dos archivos de Docker Compose para desarrollo. **El que corresponde al
restaurante es `docker-compose.restaurante.yml`.**

| | **Restaurante** (el actual) | Punto de venta anterior |
|---|---|---|
| Archivo | `docker-compose.restaurante.yml` | `docker-compose.yml` |
| Proyecto de Compose | `restaurante-dev` | `ventas-dev` |
| Contenedores | `restaurante_app`, `restaurante_nginx`, `restaurante_mysql`, `restaurante_vite`, `restaurante_adminer` | `ventas_app`, `ventas_nginx`, `ventas_mysql`, `ventas_vite`, `ventas_adminer` |
| Aplicación | **http://localhost:8200** | http://localhost:8100 |
| Adminer | **http://localhost:8201** | http://localhost:8101 |
| MySQL desde el host | **127.0.0.1:13311** | 127.0.0.1:13310 |
| Vite (hot-reload) | **5175** | 5174 |
| Volumen de datos | `restaurante-dev_restaurante_data` | `ventas-dev_ventas_data` |
| Pruebas (`phpunit.xml`) | **apuntan aquí** | ya no |

**En qué se diferencian de verdad.** Los dos montan **el mismo código** (`./sistema-ventas`)
y cargan **el mismo esquema** (`docs/sql`) la primera vez que crean su volumen. Lo que los
separa son los puertos, los nombres de los contenedores y, sobre todo, **el volumen de datos**:

- El volumen de `restaurante-dev` nació con el esquema de restaurante (pedidos, cocina) y
  los datos de demostración del restaurante. Si se creó antes del 19/09/2026, le faltan los
  cambios posteriores: se pone al día con los parches del 18/09 y del 19/09 que le falten
  (§4.6) o se recrea con `down -v` (§4.3).
- El volumen de `ventas-dev` guarda la base del **minimarket**, con su inventario, sus
  proveedores y sus devoluciones. Levantar ese stack hoy corre el código nuevo contra la
  base vieja: sirve para comparar o para ensayar la migración con los parches (§4.6), no
  para trabajar en el restaurante.

Los dos pueden estar levantados a la vez: no comparten contenedores, puertos ni volúmenes, y
levantar o borrar uno no toca la base del otro. Sí comparten la carpeta del código, así que
`storage/`, `bootstrap/cache` y el `.env` son los mismos para ambos.

> Hay un tercer archivo, `docker-compose.prod.yml`, para la instalación real en el cliente
> (contenedores `ventas_*_prod`, base sin datos de ejemplo). No es parte del entorno de
> desarrollo.

### Qué levanta el stack del restaurante

| Servicio | Imagen | Puerto | Para qué |
|----------|--------|:------:|----------|
| `nginx` | `nginx:1.27-alpine` | **8200** | Sirve la aplicación y pasa los `.php` a php-fpm |
| `app` | propia (`Dockerfile`, `php:8.4-fpm-alpine`) | — | php-fpm con el código montado desde el host |
| `vite` | `node:22-alpine` | **5175** | Compila CSS/JS con hot-reload; lo consume el navegador |
| `mysql` | `mysql:8.0` | **127.0.0.1:13311** | La base `ventas_db` con el esquema y los datos de ejemplo cargados |
| `adminer` | `adminer:4` | **8201** | Interfaz web para explorar la base. **Solo con `--profile tools`** |

Con esto la aplicación queda funcionando sin instalar PHP ni Node en la máquina.

### Por qué estos puertos

El rango **82xx** porque los otros proyectos del repositorio ya ocupan el 8000 (granja), el
8080 y el 8081 (Aerolínea), el 8090 (asistencia), el 8100 y el 8101 (el stack anterior) y el
8700 (granja-perm).

El **13311** para MySQL porque el 13310 es del stack anterior, y por debajo de 13000 Windows
(WinNAT) reserva rangos de puertos que cambian en cada reinicio: un puerto bajo puede fallar
de un día para otro con *"An attempt was made to access a socket in a way forbidden by its
access permissions"*. Se publica en `127.0.0.1`, así que solo se alcanza desde esta máquina.

El **5175** para Vite porque el 5174 es del stack anterior. Vite tiene que escuchar en el
mismo puerto por dentro y por fuera: ese valor es el que escribe en `public/hot`, y el
navegador pide los assets ahí. Por eso `vite.config.js` lee la variable **`VITE_PUERTO`**
(5174 si no está), y el compose del restaurante la fija en `5175`.

### Dónde vive la configuración

Los servicios `app` y `vite` leen [`.env.docker`](../sistema-ventas/.env.docker), el mismo
archivo para los dos stacks. Las variables llegan al contenedor como variables de entorno
reales y Laravel les da prioridad sobre el fichero. Las líneas esenciales son `DB_HOST=mysql`
y `DB_PORT=3306`: dentro de la red de Compose se llega a MySQL por el nombre del servicio y
por el puerto interno, no por el publicado en el host.

`.env.docker` trae `APP_URL=http://localhost:8100`, el del stack anterior. El compose del
restaurante lo pisa con `APP_URL: http://localhost:8200` en `app` y en `vite`, y agrega
`VITE_PUERTO: "5175"` en `vite`. Nada más cambia.

La contraseña de root de MySQL sale de `DB_PASSWORD` en un `.env` en la raíz del repositorio
(ver `.env.example`); sin ese archivo es `ventas123`.

### Qué hace el contenedor al arrancar

[`docker/php/entrypoint.dev.sh`](../sistema-ventas/docker/php/entrypoint.dev.sh) deja el
proyecto utilizable sin pasos manuales: copia `.env.docker` a `.env` si no hay `.env`, corre
`composer install` si `vendor/` está vacío, espera a que MySQL acepte conexiones, crea el
enlace `public/storage`, pasa el `CredencialesSeeder` —que es idempotente y no pisa una
contraseña que alguien ya cambió— y limpia las cachés de configuración, rutas y vistas.

No ejecuta migraciones: en este proyecto el esquema lo carga MySQL desde `docs/sql` al crear
el volumen, no Laravel.

`vendor/` y `node_modules/` viven en volúmenes de Docker y **no** en el bind mount, porque los
del host se instalaron desde Windows y traen binarios que no valen en Linux.

## 4.2 Arrancar

Desde la raíz del repositorio:

```bash
docker compose -f docker-compose.restaurante.yml up -d --build
```

La primera vez descarga las imágenes, construye la de PHP, crea los volúmenes, **ejecuta
automáticamente** `docs/sql/01_schema_mysql.sql` y `docs/sql/02_datos_iniciales.sql` (el
entrypoint de MySQL toma los `.sql` de esa carpeta en orden alfabético e ignora las
subcarpetas `parches/` y `produccion/`) e instala las dependencias de Composer y npm dentro
de los contenedores. Tarda unos minutos; se sigue con:

```bash
docker compose -f docker-compose.restaurante.yml logs -f app
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
docker compose -f docker-compose.restaurante.yml --profile tools up -d adminer
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
docker compose -f docker-compose.restaurante.yml exec app php artisan route:list
```

```bash
docker compose -f docker-compose.restaurante.yml exec app sh
```

Seguir los logs de la aplicación o de la compilación de assets:

```bash
docker compose -f docker-compose.restaurante.yml logs -f app
```

```bash
docker compose -f docker-compose.restaurante.yml logs -f vite
```

Tras cambiar `composer.json` o `package.json` hay que reinstalar dentro del contenedor: las
dependencias viven en volúmenes, no en la carpeta del host.

```bash
docker compose -f docker-compose.restaurante.yml exec app composer install
```

```bash
docker compose -f docker-compose.restaurante.yml exec vite npm install
```

Detener conservando los datos, o borrarlo todo y empezar de cero:

```bash
docker compose -f docker-compose.restaurante.yml down
```

```bash
docker compose -f docker-compose.restaurante.yml down -v
```

> `down -v` elimina el volumen **de este stack**: la próxima vez que arranques, los scripts se
> vuelven a ejecutar desde cero. Es la forma de probar que el esquema carga limpio. La base
> del stack anterior no se toca.

## 4.4 Pruebas

La batería de pruebas (578 hoy, en `sistema-ventas/tests`) corre contra una base
**MySQL real**, `ventas_db_test`, en el MySQL del stack del restaurante. No usa SQLite: el
esquema depende de columnas generadas, `ENUM`, triggers y procedimientos que SQLite no
reproduce.

[`phpunit.xml`](../sistema-ventas/phpunit.xml) fija la conexión con `force="true"`:

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

**2. Correr las pruebas con el PHP del host**, desde `sistema-ventas/`:

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
- **Apuntan al stack del restaurante.** Con solo el stack anterior levantado (13310), las
  pruebas no conectan.
- **La contraseña está fija en `ventas123`.** Si el `.env` de la raíz cambia `DB_PASSWORD`,
  hay que ajustar también `phpunit.xml`.
- **`memory_limit=2G`** porque la suite completa necesita más memoria que el límite por
  omisión de PHP.

Las pruebas también cubren la vía `LOGICA_EN_PHP=true` (las reglas de la base ejecutadas en
PHP; ver [03-base-datos-mysql.md](03-base-datos-mysql.md) §3.6), para que las dos vías no se
separen. Con esa vía se saltan 4 —las que prueban triggers, rutinas o vistas que en ella no
existen— y pasan las demás.

## 4.5 El stack anterior (`docker-compose.yml`)

Se levanta igual, sin `-f`:

```bash
docker compose up -d --build
```

Aplicación en http://localhost:8100, MySQL en 127.0.0.1:13310, Vite en 5174 y Adminer en
8101 (también con `--profile tools`). Sus contenedores se llaman `ventas_*`.

Úsalo solo para dos cosas:

1. **Consultar la base del minimarket** tal como quedó.
2. **Ensayar la migración** al modelo de restaurante con los parches (§4.6), sobre una copia
   que se puede tirar.

Si su volumen se borra (`docker compose down -v`) y se vuelve a levantar, carga el esquema
**actual** de `docs/sql`, es decir, el del restaurante: a partir de ahí los dos stacks solo
se diferencian en los puertos.

## 4.6 Migrar una base del sistema anterior (parches)

Una base creada con el `01_schema_mysql.sql` actual ya es el modelo de restaurante de
mostrador: **no necesita parches**. Una base del punto de venta anterior se lleva al modelo
nuevo con los seis parches del 17/09/2026, los seis del 18/09/2026, los seis del
19/09/2026 y el del 20/09/2026 de `docs/sql/parches/`; una del restaurante creada con el
esquema del 17/09, con los del 18/09 en adelante; una creada con el del 18/09, con los del
19/09 y el del 20/09; una creada con el del 19/09, solo con el del 20/09. Qué
hace cada uno está en [03-base-datos-mysql.md](03-base-datos-mysql.md) §3.11; cuatro de ellos
**borran datos** que no se recuperan (inventario, devoluciones, códigos de barras, costos y
unidades), y tres de los del 19/09 y el del 20/09 **abortan sin tocar nada** si algún dato viola lo que
agregan: muestran qué falla, se corrige a mano y se vuelven a aplicar.

El script [`scripts/aplicar-parches.sh`](../scripts/aplicar-parches.sh) compara
`docs/sql/parches/` con la tabla `parches_aplicados` de la base, aplica lo que falta en orden
alfabético y lo registra. Deja fuera **siempre** los parches de catálogo del minimarket
(abarrotes, bebidas, cigarrillos, sin impuesto: `--catalogo` ya no existe), y se niega a
aplicar un parche con fecha anterior al último aplicado (salvo `--forzar`), porque podría
reemplazar un procedimiento por una versión vieja.

El contenedor de MySQL **lo encuentra solo**: prueba `ventas_mysql_prod`, `restaurante_mysql`
y `ventas_mysql`, en ese orden, y se queda con el primero que esté corriendo (si ninguno
corre, con el primero que exista). Con el stack del restaurante levantado no hay que
indicarle nada. `scripts/backup-db.sh`, `scripts/restore-db.sh`,
`scripts/crear-usuario-app.sh` y `scripts/revisar-salud.sh` buscan igual. Solo si el
contenedor se llama de otra forma hace falta `VENTAS_MYSQL=...`.

La contraseña de root la toma de `DB_PASSWORD` en el `.env` de la raíz (o `ventas123`).

**1. Respaldar** (los parches borran datos):

```bash
./scripts/backup-db.sh
```

**2. Ver qué falta**, sin tocar nada:

```bash
./scripts/aplicar-parches.sh
```

Contra una base del minimarket deberían aparecer como pendientes, al menos, los diecinueve
parches del 17/09, del 18/09, del 19/09 y del 20/09:

```
2026_09_17_eliminar_inventario_y_devoluciones.sql
2026_09_17_mesas_y_pedidos.sql
2026_09_17_numero_diario_de_pedido.sql
2026_09_17_sin_codigo_de_barras.sql
2026_09_17_sin_costo_de_compra.sql
2026_09_17_sin_unidades_de_medida.sql
2026_09_18_comanda.sql
2026_09_18_jornada_del_pedido.sql
2026_09_18_pasa_por_cocina.sql
2026_09_18_permiso_del_menu.sql
2026_09_18_sin_mesas.sql
2026_09_18_sin_mozos.sql
2026_09_19_1_logica_igual_en_las_dos_vias.sql
2026_09_19_2_la_venta_guarda_su_pedido.sql
2026_09_19_3_configuracion_y_limpieza.sql
2026_09_19_4_emisor_documentos_y_detalle.sql
2026_09_19_5_identificadores_sin_tope.sql
2026_09_19_6_sin_pantalla_de_respaldos.sql
2026_09_20_1_lo_cerrado_no_se_toca.sql
```

Contra la base de desarrollo del restaurante creada antes del 18/09, solo los trece últimos;
creada el 18/09, solo los seis del 19/09 y el del 20/09.

**3. Aplicar**, en ese orden:

```bash
./scripts/aplicar-parches.sh --aplicar
```

Ejemplos con otra base u otro contenedor:

```bash
BASE=ventas_db_cliente2 ./scripts/aplicar-parches.sh --aplicar
```

```bash
VENTAS_MYSQL=otro_contenedor ./scripts/aplicar-parches.sh
```

Con `BASE=otra`, el script cambia el `USE ventas_db;` con el que empieza cada parche por la
base pedida: el parche se aplica y se registra en la misma base. (`backup-db.sh` y
`restore-db.sh` trabajan siempre sobre `ventas_db`.)

Los parches son **idempotentes**: una segunda pasada no rompe nada, y sobre una base que ya
tiene el cambio solo informa que no hay nada que hacer. Sin el script, cada uno se puede
aplicar a mano en el mismo orden:

```bash
docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123 ventas_db < docs/sql/parches/2026_09_17_eliminar_inventario_y_devoluciones.sql
```

(y registrarlo después en `parches_aplicados`, para que el script no lo vuelva a ofrecer).

Tras migrar, las cuentas que tenían el rol Almacenero quedan como **Cajero**, que además
cobra y abre caja: revisa sus roles desde *Seguridad → Usuarios*.

## 4.7 Dos hallazgos que siguen vigentes

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
