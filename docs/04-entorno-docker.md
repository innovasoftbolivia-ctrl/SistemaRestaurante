# 4. Entorno de desarrollo con Docker

**Proyecto:** Sistema de Venta de Productos
**Documento:** 04 — Entorno de desarrollo
**Archivos:** [`docker-compose.yml`](../docker-compose.yml) ·
[`sistema-ventas/Dockerfile`](../sistema-ventas/Dockerfile) ·
[`sistema-ventas/.env.docker`](../sistema-ventas/.env.docker)

---

## 4.1 Qué levanta

| Servicio | Imagen | Puerto | Para qué |
|----------|--------|:------:|----------|
| `nginx` | `nginx:1.27-alpine` | **8100** | Sirve la aplicación y pasa los `.php` a php-fpm |
| `app` | propia (`Dockerfile`) | — | php-fpm 8.4 con el código montado desde el host |
| `vite` | `node:22-alpine` | **5174** | Compila CSS/JS con hot-reload; lo consume el navegador |
| `mysql` | `mysql:8.0` | **3307** | La base `ventas_db` con el esquema y los datos de ejemplo cargados |
| `adminer` | `adminer:4` | **8101** | Interfaz web para explorar tablas, relaciones y datos sin instalar nada |

Con esto la aplicación queda funcionando sin instalar PHP ni Node en la máquina. La
alternativa —Docker solo para la base, y la aplicación a mano con `php artisan serve` y
`npm run dev`— sigue disponible y se explica en el README.

### Por qué estos puertos

El **3307** en vez del 3306 para convivir con un MySQL instalado en la máquina.

El **8100** y el **8101** porque los otros proyectos del repositorio ya ocupan el 8000
(granja), el 8080 y el 8081 (Aerolinea) y el 8090 (asistencia); y el **5174** porque granja
usa el 5173 y asistencia el 5175. Así se pueden tener varios levantados a la vez.

### Dónde vive la configuración

Los servicios `app` y `vite` leen [`.env.docker`](../sistema-ventas/.env.docker), no el `.env`
del proyecto. Las variables llegan al contenedor como variables de entorno reales y Laravel
—que carga Dotenv en modo inmutable— les da prioridad sobre el fichero. La diferencia esencial
son dos líneas: `DB_HOST=mysql` y `DB_PORT=3306`, porque dentro de la red de compose se llega
al contenedor por su nombre y por el puerto interno, no por el publicado en el host.

El `.env` de siempre se queda como está y sigue siendo el bueno para trabajar sin Docker.

### Qué hace el contenedor al arrancar

[`docker/php/entrypoint.dev.sh`](../sistema-ventas/docker/php/entrypoint.dev.sh) deja el
proyecto utilizable sin pasos manuales: `composer install` si `vendor/` está vacío, espera a
que MySQL acepte conexiones, crea el enlace `public/storage`, pasa el `CredencialesSeeder`
—que es idempotente— y limpia las cachés de configuración, rutas y vistas.

No ejecuta migraciones: en este proyecto el esquema lo carga MySQL desde `docs/sql` al crear
el volumen, no Laravel.

`vendor/` y `node_modules/` viven en volúmenes de Docker y **no** en el bind mount, porque los
del host se instalaron desde Windows y traen binarios que no valen en Linux.

## 4.2 Arrancar

```bash
docker compose up -d --build
```

La primera vez descarga las imágenes, construye la de PHP, crea los volúmenes, **ejecuta
automáticamente** los dos scripts de `docs/sql/` en orden alfabético e instala las
dependencias de Composer y npm dentro de los contenedores. Tarda unos minutos; se sigue con:

```bash
docker compose logs -f app
```

Cuando `app` y `nginx` quedan `healthy`, la aplicación responde en **http://localhost:8100**.

Datos de conexión a la base desde el host (Workbench, DBeaver):

```
host      localhost        base      ventas_db
puerto    3307             usuario   root
                           clave     ventas123
```

Adminer queda en **http://localhost:8101** (el servidor viene preseleccionado como `mysql`).

## 4.3 Comandos del día a día

Abrir una consola SQL:

```bash
docker exec -it ventas_mysql mysql -uroot -pventas123 ventas_db
```

Ejecutar una consulta suelta:

```bash
docker exec ventas_mysql mysql -uroot -pventas123 ventas_db -t -e "SELECT * FROM v_empleados;"
```

Volver a cargar el esquema tras editarlo (borra y recrea `ventas_db`):

```bash
docker exec -i ventas_mysql mysql -uroot -pventas123 < docs/sql/01_schema_mysql.sql
```

```bash
docker exec -i ventas_mysql mysql -uroot -pventas123 < docs/sql/02_datos_iniciales.sql
```

Respaldar (incluyendo rutinas y triggers, que son parte del modelo):

```bash
docker exec ventas_mysql mysqldump -uroot -pventas123 --routines --triggers --single-transaction ventas_db > respaldo.sql
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

> `down -v` elimina el volumen: la próxima vez que arranques, los scripts se vuelven a
> ejecutar desde cero. Es la forma de probar que el esquema carga limpio.

## 4.4 Verificación realizada

El esquema **se ejecutó y se probó** en este entorno (MySQL 8.0.46). Resultado:

> Es el registro de aquella verificación, con los números que dio ENTONCES: el esquema tenía
> 26 tablas y la tasa sembrada era el 18 % del IGV peruano. Hoy son más tablas y la semilla
> usa el IVA boliviano del 13 %; con las bases recalculadas, la misma venta da subtotal
> 23.44 + IVA 3.05 = 26.49. No se reescribieron los resultados de abajo porque no se volvió a
> correr esa verificación a mano: la batería de pruebas es la que la reemplaza.

| Comprobación | Resultado |
|--------------|-----------|
| Carga de ambos scripts desde cero | Sin errores |
| Objetos creados | 26 tablas · 9 vistas · 6 triggers · 6 procedimientos · 40 FK · 18 CHECK · 12 columnas generadas |
| Venta completa (detalle, impuesto, comprobante, pago) | Totales exactos: subtotal 22.45 + IGV 4.04 = **26.49** |
| Descuento de stock y kardex | 120 → 117 y 75 → 73, con el comprobante como documento de origen |
| Vuelto como columna generada | 40.00 − 26.49 = **13.51** |
| Venta al paso sin cliente | Recibo `R001-000002` a nombre de "Cliente varios" |
| Sustitución recibo → factura | `R001-000002` queda SUSTITUIDO, nace `F001-000001`, la venta y el stock intactos |
| Devolución parcial | Stock 117 → 119, venta en `DEVUELTA_PARCIAL`, `total_devuelto` = 7.62 |
| Anulación | Stock revertido, comprobante en `ANULADO`, correlativo conservado |
| Cese de empleado | El trigger desactiva su cuenta de acceso |
| Arqueo de caja | Esperado 126.49 = declarado 126.49, diferencia 0.00 |

Todas las reglas negativas fueron rechazadas por el motor: factura a persona natural, dos
comprobantes vigentes en una venta, cliente jurídico sin RUC, venta sin stock suficiente,
devolver más de lo vendido, ajuste sin motivo, movimiento de kardex con origen incoherente,
empleado cesado sin fecha, dos cuentas para un empleado, dos sesiones abiertas en una caja,
escribir una columna generada y borrar una venta.

## 4.5 Dos hallazgos que solo aparecieron al ejecutar

**1. El detalle de venta no admite `INSERT ... SELECT FROM productos`.**
El trigger que descuenta el stock actualiza `productos`, y MySQL prohíbe que un trigger
modifique una tabla que la sentencia invocante está leyendo (**error 1442**). Hay que
insertar con `VALUES`:

```sql
SELECT id, nombre, precio_venta INTO @p, @n, @pv FROM productos WHERE codigo = 'P-0001';
INSERT INTO venta_detalle (venta_id, producto_id, descripcion, cantidad, precio_unitario)
VALUES (@venta, @p, @n, 3, @pv);
```

No es una limitación real para la aplicación —el carrito ya tiene el precio y el nombre en
memoria— pero sí rompe el atajo que uno escribe por instinto.

**2. El arqueo de caja descontaba el vuelto dos veces.**
`sp_cerrar_caja` sumaba `monto - vuelto` por cada pago, cuando el efectivo que queda en el
cajón es simplemente `monto` (por definición, `monto_recibido - vuelto = monto`). En la
prueba, la caja cerró con una diferencia de 13.51 que era exactamente el vuelto de la única
venta. Corregido en `sp_cerrar_caja` y en la vista `v_ventas_por_metodo_pago`.
