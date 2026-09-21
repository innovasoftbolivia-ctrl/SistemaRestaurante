-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Fuera el código de barras (2026-09-17)
--
--  Nadie escanea un plato. El código de barras venía del minimarket, donde lo
--  que se vendía llegaba embotellado y con su EAN-13 impreso; en un restaurante
--  la carta la escribe el negocio y no hay etiqueta que leer. El buscador del
--  mostrador y el de la carta se quedan con lo que sí se teclea: el nombre y el
--  código interno.
--
--  - `productos` pierde `codigo_barras` y su índice único `uq_productos_barras`.
--    El índice primero: mientras exista, la columna no se puede borrar.
--
--  `productos.codigo` NO se toca: es el código interno («P-0004»), sirve para
--  llamar a un plato por teclado y se sigue mostrando en el menú.
--
--  Ninguna vista, rutina ni trigger mira `codigo_barras` —a diferencia de
--  `precio_compra`, que obligó a rehacer `v_productos_mas_vendidos` antes de
--  borrarla—, así que no hay nada que reemplazar antes.
--
--  ATENCIÓN: borra datos. El código de barras de cada producto se pierde. Haz
--  un respaldo antes (Sistema > Respaldos o scripts/backup-db.sh).
--
--  Idempotente: cada paso comprueba si queda algo por hacer.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

-- ----------------------------------------------------------------- productos
-- El índice único primero: MySQL no deja borrar una columna indexada sola.
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'
                AND INDEX_NAME = 'uq_productos_barras');
SET @sql := IF(@hay, 'ALTER TABLE productos DROP INDEX uq_productos_barras', 'SELECT ''productos ya no tiene uq_productos_barras'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'
                AND COLUMN_NAME = 'codigo_barras');
SET @sql := IF(@hay, 'ALTER TABLE productos DROP COLUMN codigo_barras', 'SELECT ''productos ya no tiene codigo_barras'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
