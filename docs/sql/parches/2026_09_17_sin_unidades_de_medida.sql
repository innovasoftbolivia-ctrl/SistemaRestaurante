-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Fuera las unidades de medida (2026-09-17)
--
--  En un restaurante todo se despacha por porción: no hay nada que se cobre
--  por kilo ni por litro. La unidad de medida solo obligaba a elegir «UND» en
--  cada plato de la carta para no volver a mirarla nunca, y con ella se iba
--  también el único motivo para aceptar cantidades con decimales.
--
--  - `productos` pierde `unidad_medida_id` con su clave ajena.
--  - `venta_detalle` pierde `unidad`, la copia histórica que imprimía el
--    ticket: sin unidades que copiar, la columna se quedaba vacía.
--  - Fuera la tabla `unidades_medida`.
--
--  La cantidad entera ahora la valida la aplicación (`App\Services\Ventas` y
--  `App\Services\Pedidos`), no la unidad del producto. El esquema sigue
--  guardando `DECIMAL(12,3)` en `cantidad`: ahí están las ventas viejas del
--  minimarket, pesadas al gramo, y reescribirlas a entero les cambiaría el
--  importe.
--
--  El «Libro de Ventas IVA» también se retira en esta versión, pero era solo
--  una pantalla sobre las ventas ya registradas: no tenía nada propio en la
--  base y no hay nada que borrar aquí.
--
--  ATENCIÓN: borra datos. La unidad de cada producto y la copia histórica de
--  los tickets se pierden. Haz un respaldo antes (Sistema > Respaldos o
--  scripts/backup-db.sh).
--
--  Idempotente: cada paso comprueba si queda algo por hacer.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

-- ----------------------------------------------------------------- productos
-- La clave ajena primero: sin ella se puede borrar la columna, y sin la
-- columna se puede borrar la tabla.
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'
                AND CONSTRAINT_NAME = 'fk_productos_unidad' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql := IF(@hay, 'ALTER TABLE productos DROP FOREIGN KEY fk_productos_unidad', 'SELECT ''productos ya no tiene fk_productos_unidad'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- La clave ajena deja detrás su índice implícito, que impide borrar la columna.
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'
                AND INDEX_NAME = 'fk_productos_unidad');
SET @sql := IF(@hay, 'ALTER TABLE productos DROP INDEX fk_productos_unidad', 'SELECT ''productos ya no tiene el índice fk_productos_unidad'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'
                AND COLUMN_NAME = 'unidad_medida_id');
SET @sql := IF(@hay, 'ALTER TABLE productos DROP COLUMN unidad_medida_id', 'SELECT ''productos ya no tiene unidad_medida_id'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------- venta_detalle
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalle'
                AND COLUMN_NAME = 'unidad');
SET @sql := IF(@hay, 'ALTER TABLE venta_detalle DROP COLUMN unidad', 'SELECT ''venta_detalle ya no tiene unidad'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------- la tabla
DROP TABLE IF EXISTS unidades_medida;

-- ------------------------------------------------------------------ permisos
-- El texto del permiso nombraba las unidades entre lo que se puede eliminar.
UPDATE permisos
   SET descripcion = 'Eliminar productos, categorías, clientes y personal'
 WHERE codigo = 'registros.eliminar';
