-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Reglas que vivían solo en la aplicación, y la bitácora (2026-09-16)
--
--  De los menores de la auditoría de cierre:
--
--  - Una venta solo entra en un turno ABIERTO, y lo pagado suma el total: la
--    aplicación lo exigía, la base no. Ahora también la base.
--  - El kardex no admite movimientos sin responsable, salvo la carga INICIAL.
--    Los que ya existen sin responsable se quedan como están.
--  - La bitácora tiene índices por fecha y por acción.
--  - `parches_aplicados` existe aunque nunca haya corrido aplicar-parches.sh.
--
--  Idempotente: los índices se crean solo si faltan y los triggers se reemplazan.
-- =============================================================================

USE ventas_db;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auditoria' AND INDEX_NAME = 'ix_auditoria_fecha');
SET @sql := IF(@falta, 'ALTER TABLE auditoria ADD KEY ix_auditoria_fecha (fecha, id)', 'SELECT ''auditoria ya tiene ix_auditoria_fecha'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auditoria' AND INDEX_NAME = 'ix_auditoria_accion');
SET @sql := IF(@falta, 'ALTER TABLE auditoria ADD KEY ix_auditoria_accion (accion, fecha)', 'SELECT ''auditoria ya tiene ix_auditoria_accion'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS parches_aplicados (
    archivo     VARCHAR(150) NOT NULL PRIMARY KEY,
    aplicado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

DROP TRIGGER IF EXISTS trg_ventas_before_insert;
DROP TRIGGER IF EXISTS trg_movimientos_inventario_before_insert;
DROP TRIGGER IF EXISTS trg_comprobantes_before_insert;

DELIMITER $$

-- 10.3.bis Una venta solo entra en un turno de caja ABIERTO. La aplicación ya
--      lo comprobaba, pero una venta cargada por script en un turno cerrado
--      cambiaba el arqueo de un cierre que ya se firmó.
CREATE TRIGGER trg_ventas_before_insert
BEFORE INSERT ON ventas
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM sesiones_caja
                    WHERE id = NEW.sesion_caja_id AND estado = 'ABIERTA') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La venta debe registrarse en un turno de caja abierto';
    END IF;
END$$

-- 10.3.ter Todo movimiento del kardex tiene responsable. La única excepción es
--      la carga INICIAL del inventario, que corre un script antes de que
--      exista nadie a quien atribuírsela.
CREATE TRIGGER trg_movimientos_inventario_before_insert
BEFORE INSERT ON movimientos_inventario
FOR EACH ROW
BEGIN
    IF NEW.usuario_id IS NULL AND NEW.origen <> 'INICIAL' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El movimiento de inventario necesita un responsable';
    END IF;
END$$

CREATE TRIGGER trg_comprobantes_before_insert
BEFORE INSERT ON comprobantes
FOR EACH ROW
BEGIN
    DECLARE v_aplica     VARCHAR(10);
    DECLARE v_ex_cliente TINYINT(1);
    DECLARE v_ex_doc     TINYINT(1);

    -- el tipo se deduce de la serie: no hay dos fuentes que puedan contradecirse
    SELECT tc.aplica_persona, tc.exige_cliente, tc.exige_documento
      INTO v_aplica, v_ex_cliente, v_ex_doc
      FROM series_comprobante s
      JOIN tipos_comprobante tc ON tc.id = s.tipo_comprobante_id
     WHERE s.id = NEW.serie_id;

    IF v_aplica IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La serie del comprobante no existe';
    END IF;

    IF v_ex_cliente = 1 AND NEW.cliente_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Este tipo de comprobante exige un cliente registrado';
    END IF;

    IF v_ex_doc = 1 AND (NEW.cliente_documento IS NULL OR NEW.cliente_documento = '') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Este tipo de comprobante exige el documento del cliente';
    END IF;

    -- Si hay cliente identificado, su tipo de persona debe coincidir con el documento.
    -- Sin cliente (venta al paso) solo pasan los tipos que no lo exigen: recibo y nota de venta.
    -- La excepción: una persona natural con NIT (unipersonal) recibe factura.
    IF v_aplica <> 'AMBAS' AND NEW.cliente_id IS NOT NULL
       AND IFNULL(NEW.tipo_persona, '') <> v_aplica
       AND NOT (v_aplica = 'JURIDICA' AND NEW.cliente_tipo_documento = 'NIT') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El tipo de comprobante no corresponde al tipo de persona del cliente';
    END IF;

    -- Lo pagado tiene que sumar el total de la venta. El comprobante es el
    -- último paso de registrar una venta: si llega hasta acá descuadrada, el
    -- arqueo y el comprobante dirían cifras distintas.
    IF (SELECT COALESCE(SUM(monto), 0) FROM venta_pagos WHERE venta_id = NEW.venta_id)
       <> (SELECT total FROM ventas WHERE id = NEW.venta_id) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Lo pagado no coincide con el total de la venta';
    END IF;
END$$

DELIMITER ;
