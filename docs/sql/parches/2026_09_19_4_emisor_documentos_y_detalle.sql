-- =============================================================================
--  SISTEMA DEL RESTAURANTE
--  Parche - Datos del emisor congelados, tabla de documentos de identidad y
--  sin descuento por línea (2026-09-19, 4 de 4)
--
--  1. El comprobante congela los datos del NEGOCIO al emitir, como ya
--     congelaba los del cliente: `comprobantes.emisor_nombre`,
--     `emisor_documento`, `emisor_direccion` y `emisor_telefono`, que llena
--     `sp_emitir_comprobante` (y su réplica en PHP; la sustitución emite con
--     él). Antes se imprimían los datos ACTUALES de `configuracion`, y un
--     comprobante viejo se reimprimía con el nombre o el NIT de hoy.
--     LOS COMPROBANTES QUE YA EXISTEN SE LLENAN CON LOS DATOS DE HOY: es lo
--     mejor que hay, porque el dato del día en que se emitieron no se guardó
--     en ningún lado. Si el negocio cambió de nombre o de NIT, los viejos
--     van a salir con el nuevo, igual que hasta ahora.
--  2. Fuera `venta_detalle.descuento`: valía siempre 0 (el descuento se aplica
--     al total de la venta, nunca por plato). Se rehacen las tres columnas
--     generadas que lo usaban (`importe`, `impuesto_linea`, `total_linea`) y
--     `ck_detalle_precio`.
--     SALVAGUARDA: si alguna línea tiene un descuento distinto de cero, el
--     parche ABORTA sin tocar nada: quitarlo cambiaría el total de ventas ya
--     cobradas. Hay que revisarlas a mano antes.
--  3. `tipos_documento` (CI, NIT, CE, pasaporte, sin documento), con a quién
--     aplica cada uno. `clientes.tipo_documento` y `empleados.tipo_documento`
--     dejan de ser ENUM y pasan a FK compuestas contra (codigo, aplica_*),
--     con las columnas generadas `tipodoc_natural`, `tipodoc_juridica` y
--     `tipodoc_empleado`. Los códigos NO cambian: siguen guardando 'CI',
--     'NIT'... `ck_clientes_natural` y `ck_clientes_juridica` dejan de repetir
--     la lista de documentos (ahora la tiene la tabla).
--     `comprobantes.cliente_tipo_documento` pasa de ENUM a texto SIN FK: es la
--     foto del código tal como era al emitir.
--     Si algún cliente o empleado tiene un documento que no vale para él según
--     la tabla, el parche ABORTA sin tocar nada (con el ENUM y los CHECK de
--     antes no debería pasar).
--
--  Idempotente. Se aplica después de 2026_09_19_3_configuracion_y_limpieza.sql.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

-- ------------------------------------------------ la guarda: nada se toca si...
DROP PROCEDURE IF EXISTS tmp_verificar_detalle_y_documentos;

DELIMITER $$

CREATE PROCEDURE tmp_verificar_detalle_y_documentos ()
BEGIN
    SET @con_descuento := 0;
    SET @ejemplos := NULL;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalle' AND COLUMN_NAME = 'descuento') THEN
        PREPARE stmt FROM 'SELECT COUNT(*), GROUP_CONCAT(DISTINCT venta_id ORDER BY venta_id SEPARATOR '','') INTO @con_descuento, @ejemplos FROM venta_detalle WHERE descuento <> 0';
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;

    IF @con_descuento > 0 THEN
        SELECT @con_descuento AS 'Líneas con descuento propio', LEFT(@ejemplos, 200) AS 'Ventas';
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Parche NO aplicado: hay líneas de venta con descuento propio; quitarlo cambiaría sus totales. No se cambió nada.';
    END IF;

    -- Los documentos, contra la tabla que se va a crear (solo si todavía no
    -- existen las FK: con ellas, los datos ya están garantizados).
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empleados'
                      AND CONSTRAINT_NAME = 'fk_empleados_tipodoc')
       AND EXISTS (SELECT 1 FROM empleados WHERE tipo_documento NOT IN ('CI', 'CE', 'PAS')) THEN
        SELECT id AS 'Empleado', tipo_documento AS 'Documento que no vale para un empleado'
          FROM empleados WHERE tipo_documento NOT IN ('CI', 'CE', 'PAS');
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Parche NO aplicado: hay empleados con un tipo de documento que no les corresponde. No se cambió nada.';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes'
                      AND CONSTRAINT_NAME = 'fk_clientes_tipodoc_natural')
       AND EXISTS (SELECT 1 FROM clientes
                    WHERE (tipo_persona = 'NATURAL' AND tipo_documento NOT IN ('CI', 'NIT', 'CE', 'PAS', 'SIN'))
                       OR (tipo_persona = 'JURIDICA' AND tipo_documento <> 'NIT')) THEN
        SELECT id AS 'Cliente', tipo_persona AS 'Persona', tipo_documento AS 'Documento que no le corresponde'
          FROM clientes
         WHERE (tipo_persona = 'NATURAL' AND tipo_documento NOT IN ('CI', 'NIT', 'CE', 'PAS', 'SIN'))
            OR (tipo_persona = 'JURIDICA' AND tipo_documento <> 'NIT');
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Parche NO aplicado: hay clientes con un tipo de documento que no les corresponde. No se cambió nada.';
    END IF;
END$$

DELIMITER ;

CALL tmp_verificar_detalle_y_documentos();
DROP PROCEDURE IF EXISTS tmp_verificar_detalle_y_documentos;

-- ------------------------------------------------------ 1. el emisor, congelado
DROP PROCEDURE IF EXISTS tmp_emisor_congelado;

DELIMITER $$

CREATE PROCEDURE tmp_emisor_congelado ()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comprobantes' AND COLUMN_NAME = 'emisor_nombre') THEN
        ALTER TABLE comprobantes
            ADD COLUMN emisor_nombre    VARCHAR(120) NULL AFTER fecha_emision,
            ADD COLUMN emisor_documento VARCHAR(20)  NULL AFTER emisor_nombre,
            ADD COLUMN emisor_direccion VARCHAR(200) NULL AFTER emisor_documento,
            ADD COLUMN emisor_telefono  VARCHAR(30)  NULL AFTER emisor_direccion;

        -- Los que ya existen, con los datos de HOY: es lo mejor que hay (ver
        -- la cabecera del parche).
        UPDATE comprobantes
           SET emisor_nombre    = (SELECT NULLIF(valor, '') FROM configuracion WHERE clave = 'negocio_nombre'),
               emisor_documento = (SELECT NULLIF(valor, '') FROM configuracion WHERE clave = 'negocio_documento'),
               emisor_direccion = (SELECT NULLIF(valor, '') FROM configuracion WHERE clave = 'negocio_direccion'),
               emisor_telefono  = (SELECT NULLIF(valor, '') FROM configuracion WHERE clave = 'negocio_telefono');
    END IF;
END$$

DELIMITER ;

CALL tmp_emisor_congelado();
DROP PROCEDURE IF EXISTS tmp_emisor_congelado;

DROP PROCEDURE IF EXISTS sp_emitir_comprobante;

DELIMITER $$

CREATE PROCEDURE sp_emitir_comprobante (
    IN  p_venta_id            BIGINT UNSIGNED,
    IN  p_serie_id            SMALLINT UNSIGNED,
    OUT p_comprobante_id      BIGINT UNSIGNED,
    OUT p_numero_completo     VARCHAR(20)
)
BEGIN
    DECLARE v_estado      VARCHAR(20);
    DECLARE v_cliente_id  INT UNSIGNED;
    DECLARE v_numero      INT UNSIGNED;
    DECLARE v_moneda      VARCHAR(3);
    DECLARE v_generico    VARCHAR(150);
    DECLARE v_emisor_nombre    VARCHAR(120);
    DECLARE v_emisor_documento VARCHAR(20);
    DECLARE v_emisor_direccion VARCHAR(200);
    DECLARE v_emisor_telefono  VARCHAR(30);

    SELECT estado, cliente_id INTO v_estado, v_cliente_id
      FROM ventas WHERE id = p_venta_id FOR UPDATE;

    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La venta no existe';
    END IF;
    IF v_estado <> 'COMPLETADA' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se emite comprobante de una venta COMPLETADA';
    END IF;
    -- solo se bloquea si hay un comprobante VIGENTE; los sustituidos y anulados no cuentan
    IF EXISTS (SELECT 1 FROM comprobantes
                WHERE venta_id = p_venta_id AND estado = 'EMITIDO') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La venta ya tiene un comprobante vigente. Use sp_sustituir_comprobante.';
    END IF;

    -- correlativo con bloqueo de fila
    CALL sp_siguiente_comprobante(p_serie_id, v_numero, p_numero_completo);

    -- NULLIF: un valor vacío cuenta como ausente, igual que en PHP
    -- (`ReglasEnPhp::config`).
    SET v_moneda = IFNULL((SELECT NULLIF(valor, '') FROM configuracion WHERE clave = 'moneda_codigo'), 'BOB');

    -- Venta al paso: sin cliente registrado el documento sale a nombre genérico.
    SET v_generico = IFNULL((SELECT NULLIF(valor, '') FROM configuracion
                              WHERE clave = 'cliente_generico_nombre'), 'Cliente varios');

    -- Los datos del negocio, congelados: el documento se reimprime como se entregó.
    SET v_emisor_nombre    = (SELECT NULLIF(valor, '') FROM configuracion WHERE clave = 'negocio_nombre');
    SET v_emisor_documento = (SELECT NULLIF(valor, '') FROM configuracion WHERE clave = 'negocio_documento');
    SET v_emisor_direccion = (SELECT NULLIF(valor, '') FROM configuracion WHERE clave = 'negocio_direccion');
    SET v_emisor_telefono  = (SELECT NULLIF(valor, '') FROM configuracion WHERE clave = 'negocio_telefono');

    -- El trigger trg_comprobantes_before_insert valida que el tipo de documento
    -- corresponda al tipo de persona del cliente.
    INSERT INTO comprobantes (
        venta_id, serie_id, numero, numero_completo,
        emisor_nombre, emisor_documento, emisor_direccion, emisor_telefono,
        cliente_id, tipo_persona, cliente_nombre, cliente_tipo_documento,
        cliente_documento, cliente_direccion, representante_legal,
        subtotal, descuento, impuesto, moneda, emitido_por
    )
    SELECT v.id, p_serie_id, v_numero, p_numero_completo,
           v_emisor_nombre, v_emisor_documento, v_emisor_direccion, v_emisor_telefono,
           c.id, c.tipo_persona,
           IFNULL(c.nombre, v_generico),
           IFNULL(c.tipo_documento, 'SIN'),
           c.documento, c.direccion, c.representante_legal,
           v.subtotal, v.descuento, v.impuesto, v_moneda, v.usuario_id
      FROM ventas v
      LEFT JOIN clientes c ON c.id = v.cliente_id
     WHERE v.id = p_venta_id;

    SET p_comprobante_id = LAST_INSERT_ID();
END$$

DELIMITER ;

-- ----------------------------------------------- 2. sin descuento por línea
DROP PROCEDURE IF EXISTS tmp_sin_descuento_por_linea;

DELIMITER $$

CREATE PROCEDURE tmp_sin_descuento_por_linea ()
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalle' AND COLUMN_NAME = 'descuento') THEN
        -- Las columnas generadas primero: mientras usen `descuento`, no se puede
        -- borrar. Con todos los descuentos en 0 (la guarda de arriba), el
        -- recálculo deja cada línea con el mismo importe.
        ALTER TABLE venta_detalle
            MODIFY COLUMN importe DECIMAL(12,2) GENERATED ALWAYS AS
                (ROUND(cantidad * precio_unitario, 2) - IF(impuesto_incluido = 1, ROUND(ROUND(cantidad * precio_unitario, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0) / (1 + IF(afecto_impuesto = 1, tasa_impuesto, 0)), 2), 0)) STORED,
            MODIFY COLUMN impuesto_linea DECIMAL(12,2) GENERATED ALWAYS AS
                (IF(impuesto_incluido = 1, ROUND(ROUND(cantidad * precio_unitario, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0) / (1 + IF(afecto_impuesto = 1, tasa_impuesto, 0)), 2), ROUND(ROUND(cantidad * precio_unitario, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0), 2))) STORED,
            MODIFY COLUMN total_linea DECIMAL(12,2) GENERATED ALWAYS AS
                (ROUND(cantidad * precio_unitario, 2) + IF(impuesto_incluido = 1, 0, ROUND(ROUND(cantidad * precio_unitario, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0), 2))) STORED,
            DROP CHECK ck_detalle_precio;

        ALTER TABLE venta_detalle DROP COLUMN descuento;
        ALTER TABLE venta_detalle ADD CONSTRAINT ck_detalle_precio CHECK (precio_unitario >= 0);
    END IF;
END$$

DELIMITER ;

CALL tmp_sin_descuento_por_linea();
DROP PROCEDURE IF EXISTS tmp_sin_descuento_por_linea;

-- ------------------------------------------------ 3. los documentos de identidad
CREATE TABLE IF NOT EXISTS tipos_documento (
    codigo          VARCHAR(5)   NOT NULL,
    nombre          VARCHAR(60)  NOT NULL,              -- como se ofrece en pantalla
    aplica_natural  TINYINT(1)   NOT NULL DEFAULT 0,    -- cliente persona natural
    aplica_juridica TINYINT(1)   NOT NULL DEFAULT 0,    -- cliente persona jurídica
    aplica_empleado TINYINT(1)   NOT NULL DEFAULT 0,
    orden           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (codigo),
    UNIQUE KEY uq_tipodoc_natural  (codigo, aplica_natural),
    UNIQUE KEY uq_tipodoc_juridica (codigo, aplica_juridica),
    UNIQUE KEY uq_tipodoc_empleado (codigo, aplica_empleado)
) ENGINE=InnoDB;

-- Los mismos cinco del ENUM, con las reglas que ya tenían (una segunda pasada
-- no pisa lo que el negocio haya cambiado después).
INSERT IGNORE INTO tipos_documento (codigo, nombre, aplica_natural, aplica_juridica, aplica_empleado, orden) VALUES
    ('CI',  'CI (cédula de identidad)', 1, 0, 1, 1),
    ('NIT', 'NIT',                      1, 1, 0, 2),
    ('CE',  'Carné de extranjería',     1, 0, 1, 3),
    ('PAS', 'Pasaporte',                1, 0, 1, 4),
    ('SIN', 'Sin documento',            1, 0, 0, 5);

DROP PROCEDURE IF EXISTS tmp_documentos_a_tabla;

DELIMITER $$

CREATE PROCEDURE tmp_documentos_a_tabla ()
BEGIN
    -- ---- empleados
    IF (SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empleados' AND COLUMN_NAME = 'tipo_documento') = 'enum' THEN
        ALTER TABLE empleados MODIFY COLUMN tipo_documento VARCHAR(5) NOT NULL DEFAULT 'CI';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empleados' AND COLUMN_NAME = 'tipodoc_empleado') THEN
        ALTER TABLE empleados ADD COLUMN tipodoc_empleado TINYINT(1) GENERATED ALWAYS AS (1) STORED;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empleados' AND CONSTRAINT_NAME = 'fk_empleados_tipodoc') THEN
        ALTER TABLE empleados
            ADD KEY ix_empleados_tipodoc (tipo_documento, tipodoc_empleado),
            ADD CONSTRAINT fk_empleados_tipodoc FOREIGN KEY (tipo_documento, tipodoc_empleado)
                REFERENCES tipos_documento (codigo, aplica_empleado);
    END IF;

    -- ---- clientes: los CHECK que repetían la lista, fuera antes de tocar la columna
    IF EXISTS (SELECT 1 FROM information_schema.CHECK_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'ck_clientes_natural'
                  AND CHECK_CLAUSE LIKE '%tipo_documento% in %') THEN
        ALTER TABLE clientes DROP CHECK ck_clientes_natural;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.CHECK_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'ck_clientes_juridica'
                  AND CHECK_CLAUSE LIKE '%tipo_documento%') THEN
        ALTER TABLE clientes DROP CHECK ck_clientes_juridica;
    END IF;

    IF (SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'tipo_documento') = 'enum' THEN
        ALTER TABLE clientes MODIFY COLUMN tipo_documento VARCHAR(5) NOT NULL DEFAULT 'CI';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'tipodoc_natural') THEN
        ALTER TABLE clientes
            ADD COLUMN tipodoc_natural  TINYINT(1) GENERATED ALWAYS AS (IF(tipo_persona = 'NATURAL', 1, NULL)) STORED,
            ADD COLUMN tipodoc_juridica TINYINT(1) GENERATED ALWAYS AS (IF(tipo_persona = 'JURIDICA', 1, NULL)) STORED;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND CONSTRAINT_NAME = 'fk_clientes_tipodoc_natural') THEN
        ALTER TABLE clientes
            ADD KEY ix_clientes_tipodoc_natural  (tipo_documento, tipodoc_natural),
            ADD KEY ix_clientes_tipodoc_juridica (tipo_documento, tipodoc_juridica),
            ADD CONSTRAINT fk_clientes_tipodoc_natural  FOREIGN KEY (tipo_documento, tipodoc_natural)
                REFERENCES tipos_documento (codigo, aplica_natural),
            ADD CONSTRAINT fk_clientes_tipodoc_juridica FOREIGN KEY (tipo_documento, tipodoc_juridica)
                REFERENCES tipos_documento (codigo, aplica_juridica);
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.CHECK_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'ck_clientes_natural') THEN
        ALTER TABLE clientes ADD CONSTRAINT ck_clientes_natural CHECK (
            tipo_persona <> 'NATURAL' OR (
                nombres   IS NOT NULL AND
                apellidos IS NOT NULL AND
                razon_social IS NULL  AND
                (tipo_documento <> 'NIT' OR documento IS NOT NULL)
            )
        );
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.CHECK_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'ck_clientes_juridica') THEN
        ALTER TABLE clientes ADD CONSTRAINT ck_clientes_juridica CHECK (
            tipo_persona <> 'JURIDICA' OR (
                razon_social IS NOT NULL AND
                documento    IS NOT NULL AND
                direccion    IS NOT NULL AND
                nombres      IS NULL     AND
                apellidos    IS NULL
            )
        );
    END IF;

    -- ---- comprobantes: una foto, no una referencia
    IF (SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comprobantes' AND COLUMN_NAME = 'cliente_tipo_documento') = 'enum' THEN
        ALTER TABLE comprobantes MODIFY COLUMN cliente_tipo_documento VARCHAR(5) NOT NULL DEFAULT 'SIN';
    END IF;
END$$

DELIMITER ;

CALL tmp_documentos_a_tabla();
DROP PROCEDURE IF EXISTS tmp_documentos_a_tabla;
