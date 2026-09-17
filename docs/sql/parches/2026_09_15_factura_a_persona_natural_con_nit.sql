-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Factura a una persona natural con NIT (2026-09-15)
--
--  Un unipersonal o un profesional independiente tiene NIT a su nombre y pide
--  factura. Antes solo podía recibirla registrado como persona JURÍDICA, con
--  razón social y dirección obligatorias. Ahora una persona natural puede
--  tener tipo de documento NIT, y con él recibe factura.
--
--  Idempotente: el CHECK se reemplaza solo si todavía no admite NIT, y el
--  trigger se vuelve a crear.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.CHECK_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'ck_clientes_natural'
                  AND CHECK_CLAUSE LIKE '%NIT%');
SET @sql := IF(@falta,
    'ALTER TABLE clientes DROP CHECK ck_clientes_natural, ADD CONSTRAINT ck_clientes_natural CHECK (tipo_persona <> ''NATURAL'' OR ( nombres IS NOT NULL AND apellidos IS NOT NULL AND razon_social IS NULL AND tipo_documento IN (''CI'',''CE'',''PAS'',''SIN'',''NIT'') AND (tipo_documento <> ''NIT'' OR documento IS NOT NULL) ))',
    'SELECT ''ck_clientes_natural ya admite NIT'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP TRIGGER IF EXISTS trg_comprobantes_before_insert;

DELIMITER $$

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
END$$

DELIMITER ;

SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
 WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'ck_clientes_natural';
