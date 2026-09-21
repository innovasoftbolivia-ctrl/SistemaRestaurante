# Parches de la base

Aquí van las correcciones de la base para las bases **ya instaladas**. Una instalación nueva nace
con todo: el esquema completo está en [`../01_schema_mysql.sql`](../01_schema_mysql.sql) y los
datos base en `02_datos_*.sql`.

| Parche | Qué hace |
|---|---|
| `2026_09_21_cocina_entregar.sql` | Permiso `cocina.entregar`: el cajero ve la cocina y entrega lo listo |

## Cómo se agrega uno

Cada cambio del esquema se escribe **dos veces**, y las dos tienen que quedar iguales:

1. Un archivo aquí, con nombre `AAAA_MM_DD_titulo.sql`, para las bases que ya existen.
2. El mismo cambio dentro de `01_schema_mysql.sql`, para las que se creen desde cero, y el
   nombre del archivo agregado a la lista de `parches_aplicados` del final de ese archivo: así
   una base nueva nace con el parche anotado y no se le vuelve a aplicar.

El parche empieza con `USE ventas_db;`, tiene que ser **idempotente** (aplicarlo dos veces no
cambia nada más) y, si toca datos del negocio, **comprobar antes y abortar sin tocar nada**
cuando algo no cumple.

Se aplican con [`scripts/aplicar-parches.sh`](../../../scripts/aplicar-parches.sh), en orden de
nombre, y quedan registrados en la tabla `parches_aplicados` de cada base:

```bash
./scripts/aplicar-parches.sh              # solo muestra qué falta
./scripts/aplicar-parches.sh --aplicar    # aplica lo pendiente
BASE=ventas_db_test ./scripts/aplicar-parches.sh --aplicar
```

Antes de aplicar nada en el servidor: **respaldo** (`php artisan respaldo:crear`).

## Comprobar que los dos caminos dan lo mismo

Una base con el parche aplicado tiene que quedar idéntica a una creada desde cero con el
esquema. Se comparan los dos volcados, sobre bases de descarte:

```bash
docker exec restaurante_mysql mysqldump -uroot -p<clave> --no-data --routines --triggers \
    --skip-comments --skip-dump-date <base>
```

Si hay diferencias, sobra o falta algo en uno de los dos lados.
