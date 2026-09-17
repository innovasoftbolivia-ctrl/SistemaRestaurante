-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Anular una venta con el mismo producto en dos líneas (2026-09-15)
--
--  `sp_anular_venta` reponía el stock con un UPDATE ... JOIN, y MySQL toca cada
--  fila UNA sola vez: una venta con el mismo producto en dos líneas (10 + 5)
--  reponía 10 y perdía 5. El modo sin procedimientos sí lo hacía bien, así que
--  las dos vías no coincidían. Ahora el procedimiento agrupa por producto.
--
--  Y la regla que el mostrador ya exigía pasa a la base: un producto, una línea
--  por venta. Si alguna base tuviera líneas repetidas, el índice no se crea y
--  el parche avisa en vez de fallar.
--
--  Idempotente: el índice se crea solo si falta y el procedimiento se reemplaza.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

SET @repetidas := (SELECT COUNT(*) FROM (SELECT venta_id FROM venta_detalle
                                          GROUP BY venta_id, producto_id HAVING COUNT(*) > 1) x);
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalle'
                  AND INDEX_NAME = 'uq_detalle_venta_producto');
SET @sql := IF(@repetidas > 0,
    'SELECT ''HAY VENTAS CON EL MISMO PRODUCTO EN VARIAS LINEAS: revisalas antes de crear el indice'' AS aviso',
    IF(@falta,
       'ALTER TABLE venta_detalle ADD UNIQUE KEY uq_detalle_venta_producto (venta_id, producto_id)',
       'SELECT ''venta_detalle ya tiene uq_detalle_venta_producto'' AS aviso'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP PROCEDURE IF EXISTS sp_anular_venta;

DELIMITER $$

CREATE PROCEDURE sp_anular_venta (
    IN p_venta_id   BIGINT UNSIGNED,
    IN p_usuario_id INT UNSIGNED,
    IN p_motivo     VARCHAR(255)
)
BEGIN
    DECLARE v_estado VARCHAR(20);

    SELECT estado INTO v_estado FROM ventas WHERE id = p_venta_id FOR UPDATE;

    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La venta no existe';
    END IF;
    IF v_estado <> 'COMPLETADA' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se puede anular una venta COMPLETADA';
    END IF;

    -- Reingreso de stock + kardex, agrupando por producto: un UPDATE con JOIN
    -- toca cada fila UNA vez, así que con el mismo producto en dos líneas
    -- reponía solo una de las dos. Agrupado, repone la suma aunque el índice
    -- único de arriba falte en una base vieja.
    INSERT INTO movimientos_inventario
        (producto_id, usuario_id, tipo, origen, venta_id,
         cantidad, stock_anterior, stock_resultante, motivo)
    SELECT t.producto_id, p_usuario_id, 'ENTRADA', 'ANULACION', p_venta_id,
           t.cantidad, p.stock_actual, p.stock_actual + t.cantidad,
           CONCAT('Anulación de venta: ', p_motivo)
      FROM (SELECT producto_id, SUM(cantidad) AS cantidad
                   FROM venta_detalle WHERE venta_id = p_venta_id
                  GROUP BY producto_id) t
      JOIN productos p ON p.id = t.producto_id;

    UPDATE productos p
      JOIN (SELECT producto_id, SUM(cantidad) AS cantidad
                   FROM venta_detalle WHERE venta_id = p_venta_id
                  GROUP BY producto_id) t ON t.producto_id = p.id
       SET p.stock_actual = p.stock_actual + t.cantidad;

    UPDATE ventas
       SET estado           = 'ANULADA',
           anulada_en       = NOW(),
           anulada_por      = p_usuario_id,
           motivo_anulacion = p_motivo
     WHERE id = p_venta_id;

    -- el comprobante vigente queda anulado, pero no se borra: el correlativo se conserva.
    -- Los sustituidos previos mantienen su estado: son historial.
    UPDATE comprobantes
       SET estado           = 'ANULADO',
           anulado_en       = NOW(),
           motivo_anulacion = p_motivo
     WHERE venta_id = p_venta_id
       AND estado   = 'EMITIDO';

    INSERT INTO auditoria (usuario_id, accion, entidad, entidad_id, detalle)
    VALUES (p_usuario_id, 'ANULAR_VENTA', 'ventas', p_venta_id,
            JSON_OBJECT('motivo', p_motivo));
END$$

DELIMITER ;

SELECT INDEX_NAME FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalle' AND INDEX_NAME = 'uq_detalle_venta_producto';
