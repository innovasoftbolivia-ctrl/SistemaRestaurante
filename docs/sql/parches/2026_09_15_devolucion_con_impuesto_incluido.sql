-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - La devolución hereda el modo de precio de la venta (2026-09-15)
--
--  Con el impuesto incluido en el precio, la devolución seguía calculándolo
--  «por fuera»: 30 unidades de Bs 1,00 se cobraban 30,00 y se devolvían 30,17,
--  y esos 17 centavos salían del cajón. Ahora `devolucion_detalle` copia
--  `impuesto_incluido` de la línea de venta y separa el impuesto igual que ella.
--
--  Las devoluciones ya registradas no cambian: quedan con impuesto_incluido = 0
--  y sus columnas generadas dan exactamente los mismos importes que antes.
--
--  Idempotente: la columna se agrega solo si falta; expresiones y trigger se
--  reemplazan.
-- =============================================================================

USE ventas_db;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devolucion_detalle'
                  AND COLUMN_NAME = 'impuesto_incluido');
SET @sql := IF(@falta,
    'ALTER TABLE devolucion_detalle ADD COLUMN impuesto_incluido TINYINT(1) NOT NULL DEFAULT 0 AFTER tasa_impuesto',
    'SELECT ''devolucion_detalle ya tiene impuesto_incluido'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE devolucion_detalle
    MODIFY COLUMN importe DECIMAL(12,2) GENERATED ALWAYS AS (ROUND(cantidad * precio_unitario, 2) - IF(impuesto_incluido = 1, ROUND(ROUND(cantidad * precio_unitario, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0) / (1 + IF(afecto_impuesto = 1, tasa_impuesto, 0)), 2), 0)) STORED,
    MODIFY COLUMN impuesto_linea DECIMAL(12,2) GENERATED ALWAYS AS (IF(impuesto_incluido = 1, ROUND(ROUND(cantidad * precio_unitario, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0) / (1 + IF(afecto_impuesto = 1, tasa_impuesto, 0)), 2), ROUND(ROUND(cantidad * precio_unitario, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0), 2))) STORED,
    MODIFY COLUMN total_linea DECIMAL(12,2) GENERATED ALWAYS AS (ROUND(cantidad * precio_unitario, 2) + IF(impuesto_incluido = 1, 0, ROUND(ROUND(cantidad * precio_unitario, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0), 2))) STORED;

DROP TRIGGER IF EXISTS trg_devolucion_detalle_before_insert;

DELIMITER $$

CREATE TRIGGER trg_devolucion_detalle_before_insert
BEFORE INSERT ON devolucion_detalle
FOR EACH ROW
BEGIN
    DECLARE v_afecto TINYINT(1);
    DECLARE v_tasa   DECIMAL(6,4);

    -- El modo de precio de la venta, siempre: si el precio llevaba el impuesto
    -- adentro, la devolución lo separa igual y devuelve lo mismo que se cobró.
    SET NEW.impuesto_incluido = IFNULL((SELECT impuesto_incluido FROM venta_detalle
                                         WHERE id = NEW.venta_detalle_id), 0);

    IF NEW.tasa_impuesto = 0 THEN
        SELECT afecto_impuesto, tasa_impuesto
          INTO v_afecto, v_tasa
          FROM venta_detalle WHERE id = NEW.venta_detalle_id;

        SET NEW.afecto_impuesto = IFNULL(v_afecto, 0);
        SET NEW.tasa_impuesto   = IF(NEW.afecto_impuesto = 1, IFNULL(v_tasa, 0), 0);
    END IF;
END$$

DELIMITER ;

SELECT COLUMN_NAME FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devolucion_detalle' AND COLUMN_NAME = 'impuesto_incluido';
