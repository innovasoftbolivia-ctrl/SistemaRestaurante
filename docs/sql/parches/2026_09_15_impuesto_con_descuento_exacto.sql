-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Impuesto con descuento, al centavo (2026-09-15)
--
--  `sp_recalcular_venta` guardaba la proporción del descuento en una variable
--  DECIMAL(12,6) y recién después multiplicaba: con impuesto bruto 0.51, base
--  3.96 y descuento 0.66 calculaba 0.42 en lugar de 0.43. El modo PHP, en coma
--  flotante, fallaba en otros casos (2 × 7.26 con 12.10 de descuento: 0.31 en
--  lugar de 0.32). Los dos calculan ahora la misma cuenta exacta.
--
--  Las ventas ya registradas no se recalculan: su comprobante ya se emitió.
--  Idempotente: reemplaza el procedimiento.
-- =============================================================================

USE ventas_db;

DROP PROCEDURE IF EXISTS sp_recalcular_venta;

DELIMITER $$

CREATE PROCEDURE sp_recalcular_venta (IN p_venta_id BIGINT UNSIGNED)
BEGIN
    DECLARE v_base_total     DECIMAL(12,2);
    DECLARE v_impuesto_bruto DECIMAL(12,2);
    DECLARE v_descuento      DECIMAL(12,2);
    DECLARE v_impuesto       DECIMAL(12,2);

    -- el impuesto por línea ya está calculado y guardado en venta_detalle
    SELECT IFNULL(SUM(importe), 0), IFNULL(SUM(impuesto_linea), 0)
      INTO v_base_total, v_impuesto_bruto
      FROM venta_detalle
     WHERE venta_id = p_venta_id;

    SELECT descuento INTO v_descuento FROM ventas WHERE id = p_venta_id;

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
END$$

DELIMITER ;
