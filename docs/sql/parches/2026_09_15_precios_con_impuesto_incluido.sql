-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Precios con el impuesto incluido (2026-09-15)
--
--  El negocio elige en Configuración si sus precios de venta ya traen el IVA
--  (lo habitual en Bolivia: el precio de estante es lo que paga el cliente) o
--  si el impuesto se suma encima, como hasta ahora.
--
--  Cada venta guarda con qué modo se hizo (`ventas.impuesto_incluido`, copiado
--  a sus líneas), así que las ventas ya registradas no cambian: sus líneas
--  quedan con impuesto_incluido = 0 y las columnas generadas dan exactamente
--  los mismos importes que antes.
--
--  Para una base existente la configuración queda en '0' (impuesto encima):
--  nada cambia hasta que alguien lo decida en Configuración.
--
--  Idempotente: las columnas se agregan solo si faltan; las expresiones, el
--  trigger y el procedimiento se reemplazan.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas' AND COLUMN_NAME = 'impuesto_incluido');
SET @sql := IF(@falta,
    'ALTER TABLE ventas ADD COLUMN impuesto_incluido TINYINT(1) NOT NULL DEFAULT 0 AFTER impuesto',
    'SELECT ''ventas ya tiene impuesto_incluido'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas' AND COLUMN_NAME = 'descuento_precio_final');
SET @sql := IF(@falta,
    'ALTER TABLE ventas ADD COLUMN descuento_precio_final DECIMAL(12,2) NULL AFTER impuesto_incluido',
    'SELECT ''ventas ya tiene descuento_precio_final'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalle' AND COLUMN_NAME = 'impuesto_incluido');
SET @sql := IF(@falta,
    'ALTER TABLE venta_detalle ADD COLUMN impuesto_incluido TINYINT(1) NOT NULL DEFAULT 0 AFTER tasa_impuesto',
    'SELECT ''venta_detalle ya tiene impuesto_incluido'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE venta_detalle
    MODIFY COLUMN importe DECIMAL(12,2) GENERATED ALWAYS AS (ROUND(cantidad * precio_unitario - descuento, 2) - IF(impuesto_incluido = 1, ROUND(ROUND(cantidad * precio_unitario - descuento, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0) / (1 + IF(afecto_impuesto = 1, tasa_impuesto, 0)), 2), 0)) STORED,
    MODIFY COLUMN impuesto_linea DECIMAL(12,2) GENERATED ALWAYS AS (IF(impuesto_incluido = 1, ROUND(ROUND(cantidad * precio_unitario - descuento, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0) / (1 + IF(afecto_impuesto = 1, tasa_impuesto, 0)), 2), ROUND(ROUND(cantidad * precio_unitario - descuento, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0), 2))) STORED,
    MODIFY COLUMN total_linea DECIMAL(12,2) GENERATED ALWAYS AS (ROUND(cantidad * precio_unitario - descuento, 2) + IF(impuesto_incluido = 1, 0, ROUND(ROUND(cantidad * precio_unitario - descuento, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0), 2))) STORED;

INSERT IGNORE INTO configuracion (clave, valor, descripcion) VALUES
    ('precios_incluyen_impuesto', '0', 'Los precios de venta ya incluyen el impuesto (1 = sí; 0 = se suma encima)');

DROP TRIGGER IF EXISTS trg_venta_detalle_before_insert;
DROP PROCEDURE IF EXISTS sp_recalcular_venta;

DELIMITER $$

CREATE TRIGGER trg_venta_detalle_before_insert
BEFORE INSERT ON venta_detalle
FOR EACH ROW
BEGIN
    DECLARE v_afecto TINYINT(1);

    -- La línea sigue el modo de precio de su venta: todas iguales.
    SET NEW.impuesto_incluido = IFNULL((SELECT impuesto_incluido FROM ventas WHERE id = NEW.venta_id), 0);

    IF NEW.tasa_impuesto = 0 THEN
        SELECT afecto_impuesto INTO v_afecto FROM productos WHERE id = NEW.producto_id;
        SET NEW.afecto_impuesto = IFNULL(v_afecto, 0);
        SET NEW.tasa_impuesto = IF(NEW.afecto_impuesto = 1,
            IFNULL((SELECT CAST(valor AS DECIMAL(6,4)) FROM configuracion
                     WHERE clave = 'tasa_impuesto'), 0), 0);
    END IF;
END$$

CREATE PROCEDURE sp_recalcular_venta (IN p_venta_id BIGINT UNSIGNED)
BEGIN
    DECLARE v_base_total     DECIMAL(12,2);
    DECLARE v_impuesto_bruto DECIMAL(12,2);
    DECLARE v_total_lineas   DECIMAL(12,2);
    DECLARE v_descuento      DECIMAL(12,2);
    DECLARE v_impuesto       DECIMAL(12,2);
    DECLARE v_incluido       TINYINT(1);
    DECLARE v_desc_final     DECIMAL(12,2);

    -- el impuesto por línea ya está calculado y guardado en venta_detalle
    SELECT IFNULL(SUM(importe), 0), IFNULL(SUM(impuesto_linea), 0), IFNULL(SUM(total_linea), 0)
      INTO v_base_total, v_impuesto_bruto, v_total_lineas
      FROM venta_detalle
     WHERE venta_id = p_venta_id;

    SELECT descuento, impuesto_incluido, IFNULL(descuento_precio_final, 0)
      INTO v_descuento, v_incluido, v_desc_final
      FROM ventas WHERE id = p_venta_id;

    IF v_incluido = 1 THEN
        IF v_desc_final > v_total_lineas THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'El descuento no puede superar el total de la venta';
        END IF;

        SET v_impuesto = IF(v_total_lineas > 0,
                            ROUND(v_impuesto_bruto * (v_total_lineas - v_desc_final) / v_total_lineas, 2),
                            0);

        UPDATE ventas
           SET subtotal  = v_base_total,
               descuento = v_desc_final - v_impuesto_bruto + v_impuesto,
               impuesto  = v_impuesto
         WHERE id = p_venta_id;
    ELSE
        IF v_descuento > v_base_total THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'El descuento no puede superar el subtotal de la venta';
        END IF;

        -- El impuesto baja en la proporción de la base que deja el descuento de
        -- cabecera. En una sola cuenta, sin guardar antes el factor: con el factor
        -- en una variable de 6 decimales, 0.51 × 3.30 / 3.96 daba 0.42 y no 0.43, y
        -- el procedimiento y el modo PHP no cobraban el mismo impuesto.
        SET v_impuesto = IF(v_base_total > 0,
                            ROUND(v_impuesto_bruto * (v_base_total - v_descuento) / v_base_total, 2),
                            0);

        -- `total` es columna generada: se recalcula sola a partir de estos tres valores
        UPDATE ventas
           SET subtotal = v_base_total,
               impuesto = v_impuesto
         WHERE id = p_venta_id;
    END IF;
END$$

DELIMITER ;

SELECT clave, valor FROM configuracion WHERE clave IN ('tasa_impuesto', 'precios_incluyen_impuesto');
