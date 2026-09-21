-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Fuera el precio de compra (2026-09-17)
--
--  En un restaurante el producto se hace ahí: no se compra para revenderlo, así
--  que no hay precio de compra que anotar. El plato tiene un solo precio, el
--  que pone el usuario, y con el costo se va también todo lo que se calculaba
--  a partir de él: la ganancia del reporte de ventas y el margen por plato.
--  Reportes sigue diciendo cuánto se vendió; ya no cuánto se ganó.
--
--  - `v_productos_mas_vendidos` pierde `margen_estimado` y deja de mirar el
--    costo. Se reemplaza ANTES de borrar las columnas: una vista que apunta a
--    una columna que ya no existe revienta al consultarla.
--  - `productos` pierde `precio_compra`, y con ella el CHECK que la nombraba:
--    `ck_productos_precios` se vuelve a crear mirando solo `precio_venta`.
--  - `venta_detalle` pierde `costo_unitario`, la copia del costo del día de la
--    venta: sin costo que congelar, la columna se quedaba vacía.
--
--  ATENCIÓN: borra datos. El costo de cada producto y el costo histórico de
--  cada línea vendida se pierden. Haz un respaldo antes (Sistema > Respaldos o
--  scripts/backup-db.sh).
--
--  Idempotente: cada paso comprueba si queda algo por hacer.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

-- --------------------------------------------------------------------- vistas
-- Primero la vista, que es la única que lee las dos columnas. Si se borraran
-- antes, la vista quedaría apuntando a columnas inexistentes y cualquier
-- SELECT sobre ella fallaría con el error 1356.
CREATE OR REPLACE VIEW v_productos_mas_vendidos AS
SELECT p.id, p.codigo, p.nombre, c.nombre AS categoria,
       SUM(n.unidades_netas)  AS unidades_vendidas,
       SUM(n.monto_neto)      AS monto_vendido
  FROM (
        SELECT d.producto_id,
               d.cantidad AS unidades_netas,
               ROUND(d.importe
                     * IF(v.subtotal > 0, (v.subtotal - v.descuento) / v.subtotal, 1), 2) AS monto_neto
          FROM venta_detalle d
          JOIN ventas v ON v.id = d.venta_id AND v.estado <> 'ANULADA'
       ) AS n
  JOIN productos  p ON p.id = n.producto_id
  JOIN categorias c ON c.id = p.categoria_id
 GROUP BY p.id, p.codigo, p.nombre, c.nombre;

-- ----------------------------------------------------------------- productos
-- La restricción antes que la columna: MySQL no deja borrar una columna que
-- usa un CHECK. Solo se retira mientras `precio_compra` siga existiendo, para
-- no tirar abajo la versión nueva del CHECK en una segunda pasada.
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'
                AND CONSTRAINT_NAME = 'ck_productos_precios' AND CONSTRAINT_TYPE = 'CHECK')
         AND (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'
                 AND COLUMN_NAME = 'precio_compra');
SET @sql := IF(@hay, 'ALTER TABLE productos DROP CHECK ck_productos_precios', 'SELECT ''productos ya no tiene el CHECK con precio_compra'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'
                AND COLUMN_NAME = 'precio_compra');
SET @sql := IF(@hay, 'ALTER TABLE productos DROP COLUMN precio_compra', 'SELECT ''productos ya no tiene precio_compra'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- El mismo nombre de siempre, ahora sobre el único precio que queda.
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'
                  AND CONSTRAINT_NAME = 'ck_productos_precios' AND CONSTRAINT_TYPE = 'CHECK');
SET @sql := IF(@falta, 'ALTER TABLE productos ADD CONSTRAINT ck_productos_precios CHECK (precio_venta >= 0)', 'SELECT ''productos ya tiene ck_productos_precios'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------- venta_detalle
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalle'
                AND COLUMN_NAME = 'costo_unitario');
SET @sql := IF(@hay, 'ALTER TABLE venta_detalle DROP COLUMN costo_unitario', 'SELECT ''venta_detalle ya no tiene costo_unitario'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
