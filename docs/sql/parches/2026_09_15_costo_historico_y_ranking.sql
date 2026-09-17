-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Costo histórico en la venta y ranking con descuento (2026-09-15)
--
--  1. `venta_detalle.costo_unitario`: el costo del producto el día que se
--     vendió. La ganancia usaba el `precio_compra` de hoy y los meses pasados
--     cambiaban cada vez que subía un costo. Las ventas existentes se completan
--     con el costo actual —el mejor dato que hay—; las nuevas lo guardan al
--     vender.
--  2. `v_productos_mas_vendidos`: reparte el descuento de la venta entre sus
--     líneas y usa ese costo. Antes una venta de Bs 100 con 10 % aparecía
--     como 100 y con el margen inflado en 10.
--
--  Idempotente: la columna se agrega solo si falta, el relleno solo toca las
--  que están en NULL y la vista se reemplaza.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalle' AND COLUMN_NAME = 'costo_unitario');
SET @sql := IF(@falta,
    'ALTER TABLE venta_detalle ADD COLUMN costo_unitario DECIMAL(12,2) NULL AFTER descuento',
    'SELECT ''venta_detalle ya tiene costo_unitario'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE venta_detalle d
  JOIN productos p ON p.id = d.producto_id
   SET d.costo_unitario = p.precio_compra
 WHERE d.costo_unitario IS NULL;

-- Neto del descuento de la venta (repartido entre sus líneas) y de lo
-- devuelto, y con el costo que tenía el producto el día que se vendió.
CREATE OR REPLACE VIEW v_productos_mas_vendidos AS
SELECT p.id, p.codigo, p.nombre, c.nombre AS categoria,
       SUM(n.unidades_netas)                                     AS unidades_vendidas,
       SUM(n.monto_neto)                                         AS monto_vendido,
       SUM(n.monto_neto - ROUND(n.unidades_netas * n.costo, 2))  AS margen_estimado
  FROM (
        SELECT d.producto_id,
               (d.cantidad - d.cantidad_devuelta) AS unidades_netas,
               ROUND(d.importe
                     * IF(v.subtotal > 0, (v.subtotal - v.descuento) / v.subtotal, 1)
                     * IF(d.cantidad > 0, (d.cantidad - d.cantidad_devuelta) / d.cantidad, 0), 2) AS monto_neto,
               COALESCE(d.costo_unitario, pc.precio_compra) AS costo
          FROM venta_detalle d
          JOIN ventas v ON v.id = d.venta_id AND v.estado <> 'ANULADA'
          JOIN productos pc ON pc.id = d.producto_id
       ) AS n
  JOIN productos  p ON p.id = n.producto_id
  JOIN categorias c ON c.id = p.categoria_id
 GROUP BY p.id, p.codigo, p.nombre, c.nombre;

SELECT COUNT(*) AS lineas_sin_costo FROM venta_detalle WHERE costo_unitario IS NULL;
