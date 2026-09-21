-- =============================================================================
--  SISTEMA DEL RESTAURANTE
--  Parche - Configuración con tipos, series con FK, reportes por jornada y
--  limpieza de restos (2026-09-19, 3 de 4)
--
--  1. Las series por omisión dejan de ser texto en `configuracion`:
--     `tipos_comprobante.serie_por_omision_id`, con FK compuesta
--     (serie_por_omision_id, id) → series_comprobante (id, tipo_comprobante_id):
--     la base impide que la serie de un tipo sea de otro. Se llena con
--     `serie_factura` (FAC) y `serie_recibo` (REC); la nota de venta, y
--     cualquier tipo que quede sin serie, con su primera serie activa (lo que
--     antes elegía `Ventas::seriePara`). Después se borran `serie_factura`,
--     `serie_recibo` y `moneda_simbolo` (el símbolo sale del código).
--  2. CHECK por clave en `configuracion` donde el tipo importa: banderas 0/1,
--     tasa entre 0 y 1, hora de corte 0–12, descuento máximo 0–100, días y
--     egreso no negativos, código de moneda ISO en mayúsculas.
--  3. Fuera lo que nadie usa: `pedidos.telefono_cliente`,
--     `comprobantes.archivo_pdf` (la aplicación nunca las llenó), las vistas
--     `v_empleados`, `v_ventas_comprobante`, `v_comprobantes_sustituidos` y
--     `v_comprobantes_emitidos`, y el índice `ix_detalle_venta` (lo cubre
--     `uq_detalle_venta_producto`).
--  4. `trg_venta_detalle_before_insert` copia SIEMPRE la tasa del producto y la
--     configuración: ya no respeta una tasa que venga en el INSERT.
--  5. `v_ventas_por_dia` agrupa por JORNADA (fecha menos la hora de corte),
--     como los reportes y la portada.
--  6. Las FK de `venta_detalle`, `venta_pagos` y `pedido_detalle` hacia su
--     padre pasan de ON DELETE CASCADE a RESTRICT: ventas y pedidos no se
--     borran, y sin triggers nada impedía que un DELETE se llevara el detalle.
--
--  ANTES DE TOCAR NADA comprueba que los valores de `configuracion` cumplan
--  los CHECK nuevos y que `serie_factura` / `serie_recibo` apunten a una serie
--  de su tipo. Si no, muestra qué falla y ABORTA sin cambiar nada.
--
--  Idempotente. Se aplica después de 2026_09_19_2_la_venta_guarda_su_pedido.sql.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

-- ------------------------------------------------ la guarda: nada se toca si...
DROP PROCEDURE IF EXISTS tmp_verificar_configuracion;

DELIMITER $$

CREATE PROCEDURE tmp_verificar_configuracion ()
BEGIN
    DROP TEMPORARY TABLE IF EXISTS tmp_violaciones;
    CREATE TEMPORARY TABLE tmp_violaciones (
        clave VARCHAR(50)  NOT NULL,
        valor VARCHAR(255) NULL,
        falla VARCHAR(120) NOT NULL
    );

    INSERT INTO tmp_violaciones
    SELECT clave, valor, 'tiene que ser 0 o 1' FROM configuracion
     WHERE clave IN ('precios_incluyen_impuesto', 'exigir_referencia_pago') AND valor NOT IN ('0', '1')
    UNION ALL
    SELECT clave, valor, 'tiene que ser una fracción entre 0 y 1 (0.13 es el 13 %)' FROM configuracion
     WHERE clave = 'tasa_impuesto'
       AND NOT (valor REGEXP '^[0-9]+([.][0-9]+)?$' AND CAST(valor AS DECIMAL(10,4)) <= 1)
    UNION ALL
    SELECT clave, valor, 'tiene que ser una hora entera de 0 a 12' FROM configuracion
     WHERE clave = 'hora_corte_jornada'
       AND NOT (valor REGEXP '^[0-9]{1,2}$' AND CAST(valor AS UNSIGNED) <= 12)
    UNION ALL
    SELECT clave, valor, 'tiene que ser un porcentaje entero de 0 a 100' FROM configuracion
     WHERE clave = 'descuento_max_cajero'
       AND NOT (valor REGEXP '^[0-9]{1,3}$' AND CAST(valor AS UNSIGNED) <= 100)
    UNION ALL
    SELECT clave, valor, 'tiene que ser un número entero de días' FROM configuracion
     WHERE clave = 'dias_max_sustitucion' AND NOT valor REGEXP '^[0-9]{1,3}$'
    UNION ALL
    SELECT clave, valor, 'tiene que ser un importe con hasta dos decimales' FROM configuracion
     WHERE clave = 'egreso_max_cajero' AND NOT valor REGEXP '^[0-9]{1,10}([.][0-9]{1,2})?$'
    UNION ALL
    SELECT clave, valor, 'tiene que ser un código ISO de tres letras mayúsculas' FROM configuracion
     WHERE clave = 'moneda_codigo' AND NOT REGEXP_LIKE(valor, '^[A-Z]{3}$', 'c')
    UNION ALL
    SELECT c.clave, c.valor, 'no es una serie de su tipo de comprobante' FROM configuracion c
     WHERE (c.clave = 'serie_factura' OR c.clave = 'serie_recibo')
       AND NOT EXISTS (SELECT 1 FROM series_comprobante s
                         JOIN tipos_comprobante t ON t.id = s.tipo_comprobante_id
                        WHERE s.id = CAST(c.valor AS UNSIGNED)
                          AND t.codigo = IF(c.clave = 'serie_factura', 'FAC', 'REC'));

    IF EXISTS (SELECT 1 FROM tmp_violaciones) THEN
        SELECT clave AS 'Clave', valor AS 'Valor actual', falla AS 'Qué falla'
          FROM tmp_violaciones ORDER BY clave;
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Parche NO aplicado: hay valores de configuracion que no cumplen (ver la lista). No se cambió nada.';
    END IF;

    DROP TEMPORARY TABLE IF EXISTS tmp_violaciones;
END$$

DELIMITER ;

CALL tmp_verificar_configuracion();
DROP PROCEDURE IF EXISTS tmp_verificar_configuracion;

-- ------------------------------------------------- la serie por omisión
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tipos_comprobante' AND COLUMN_NAME = 'serie_por_omision_id');
SET @sql := IF(@hay, 'SELECT ''tipos_comprobante ya tiene serie_por_omision_id'' AS aviso',
    'ALTER TABLE tipos_comprobante ADD COLUMN serie_por_omision_id SMALLINT UNSIGNED NULL AFTER activo');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Lo que decía la configuración, solo si la serie es de ese tipo (la guarda
-- de arriba ya lo comprobó).
UPDATE tipos_comprobante t
  JOIN configuracion c ON c.clave = IF(t.codigo = 'FAC', 'serie_factura', 'serie_recibo')
  JOIN series_comprobante s ON s.id = CAST(c.valor AS UNSIGNED) AND s.tipo_comprobante_id = t.id
   SET t.serie_por_omision_id = s.id
 WHERE t.codigo IN ('FAC', 'REC')
   AND t.serie_por_omision_id IS NULL;

-- El resto (la nota de venta): su primera serie activa, lo que antes elegía la
-- aplicación con una consulta aparte.
UPDATE tipos_comprobante t
   SET t.serie_por_omision_id = (SELECT MIN(s.id) FROM series_comprobante s
                                  WHERE s.tipo_comprobante_id = t.id AND s.activo = 1)
 WHERE t.serie_por_omision_id IS NULL;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'series_comprobante' AND INDEX_NAME = 'uq_series_id_tipo');
SET @sql := IF(@hay, 'SELECT ''series_comprobante ya tiene uq_series_id_tipo'' AS aviso',
    'ALTER TABLE series_comprobante ADD UNIQUE KEY uq_series_id_tipo (id, tipo_comprobante_id)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tipos_comprobante'
                AND CONSTRAINT_NAME = 'fk_tipocomp_serie' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql := IF(@hay, 'SELECT ''tipos_comprobante ya tiene fk_tipocomp_serie'' AS aviso',
    'ALTER TABLE tipos_comprobante ADD KEY ix_tipocomp_serie (serie_por_omision_id, id), ADD CONSTRAINT fk_tipocomp_serie FOREIGN KEY (serie_por_omision_id, id) REFERENCES series_comprobante (id, tipo_comprobante_id)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DELETE FROM configuracion WHERE clave IN ('serie_factura', 'serie_recibo', 'moneda_simbolo');

-- --------------------------------------------- CHECK por clave en configuracion
DROP PROCEDURE IF EXISTS tmp_agregar_check;

DELIMITER $$

CREATE PROCEDURE tmp_agregar_check (IN p_tabla VARCHAR(64), IN p_nombre VARCHAR(64), IN p_expresion TEXT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabla
                      AND CONSTRAINT_NAME = p_nombre AND CONSTRAINT_TYPE = 'CHECK') THEN
        SET @sql := CONCAT('ALTER TABLE ', p_tabla, ' ADD CONSTRAINT ', p_nombre, ' CHECK (', p_expresion, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CALL tmp_agregar_check('configuracion', 'ck_config_banderas',
    'clave NOT IN (''precios_incluyen_impuesto'', ''exigir_referencia_pago'') OR valor IN (''0'', ''1'')');
CALL tmp_agregar_check('configuracion', 'ck_config_tasa',
    'clave <> ''tasa_impuesto'' OR (valor REGEXP ''^[0-9]+([.][0-9]+)?$'' AND CAST(valor AS DECIMAL(10,4)) <= 1)');
CALL tmp_agregar_check('configuracion', 'ck_config_hora',
    'clave <> ''hora_corte_jornada'' OR (valor REGEXP ''^[0-9]{1,2}$'' AND CAST(valor AS UNSIGNED) <= 12)');
CALL tmp_agregar_check('configuracion', 'ck_config_descuento',
    'clave <> ''descuento_max_cajero'' OR (valor REGEXP ''^[0-9]{1,3}$'' AND CAST(valor AS UNSIGNED) <= 100)');
CALL tmp_agregar_check('configuracion', 'ck_config_dias',
    'clave <> ''dias_max_sustitucion'' OR valor REGEXP ''^[0-9]{1,3}$''');
CALL tmp_agregar_check('configuracion', 'ck_config_egreso',
    'clave <> ''egreso_max_cajero'' OR valor REGEXP ''^[0-9]{1,10}([.][0-9]{1,2})?$''');
CALL tmp_agregar_check('configuracion', 'ck_config_moneda',
    'clave <> ''moneda_codigo'' OR REGEXP_LIKE(valor, ''^[A-Z]{3}$'', ''c'')');

DROP PROCEDURE IF EXISTS tmp_agregar_check;

-- ------------------------------------------------------ columnas que nadie usa
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'telefono_cliente');
SET @sql := IF(@hay, 'ALTER TABLE pedidos DROP COLUMN telefono_cliente', 'SELECT ''pedidos ya no tiene telefono_cliente'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comprobantes' AND COLUMN_NAME = 'archivo_pdf');
SET @sql := IF(@hay, 'ALTER TABLE comprobantes DROP COLUMN archivo_pdf', 'SELECT ''comprobantes ya no tiene archivo_pdf'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------ la tasa la pone la base
DROP TRIGGER IF EXISTS trg_venta_detalle_before_insert;

DELIMITER $$

CREATE TRIGGER trg_venta_detalle_before_insert
BEFORE INSERT ON venta_detalle
FOR EACH ROW
BEGIN
    DECLARE v_afecto TINYINT(1);

    -- La línea sigue el modo de precio de su venta: todas iguales.
    SET NEW.impuesto_incluido = IFNULL((SELECT impuesto_incluido FROM ventas WHERE id = NEW.venta_id), 0);

    SELECT afecto_impuesto INTO v_afecto FROM productos WHERE id = NEW.producto_id;
    SET NEW.afecto_impuesto = IFNULL(v_afecto, 0);
    -- NULLIF: un valor vacío cuenta como ausente, igual que en PHP.
    SET NEW.tasa_impuesto = IF(NEW.afecto_impuesto = 1,
        IFNULL((SELECT CAST(NULLIF(valor, '') AS DECIMAL(6,4)) FROM configuracion
                 WHERE clave = 'tasa_impuesto'), 0), 0);
END$$

DELIMITER ;

-- ------------------------------------------------------------------ vistas
DROP VIEW IF EXISTS v_empleados;
DROP VIEW IF EXISTS v_ventas_comprobante;
DROP VIEW IF EXISTS v_comprobantes_sustituidos;
DROP VIEW IF EXISTS v_comprobantes_emitidos;

CREATE OR REPLACE VIEW v_ventas_por_dia AS
SELECT j.dia                  AS dia,
       COUNT(*)               AS cantidad_ventas,
       SUM(j.total)           AS monto_total,
       ROUND(AVG(j.total), 2) AS ticket_promedio
  FROM (
        SELECT DATE(v.fecha - INTERVAL IFNULL((SELECT CAST(NULLIF(c.valor, '') AS UNSIGNED)
                                                  FROM configuracion c
                                                 WHERE c.clave = 'hora_corte_jornada'), 5) HOUR) AS dia,
               v.total
          FROM ventas v
         WHERE v.estado <> 'ANULADA'
       ) AS j
 GROUP BY j.dia;

-- --------------------------- el detalle no se borra con su venta ni su pedido
DROP PROCEDURE IF EXISTS tmp_fk_restrict;

DELIMITER $$

CREATE PROCEDURE tmp_fk_restrict (IN p_tabla VARCHAR(64), IN p_nombre VARCHAR(64), IN p_definicion TEXT)
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = p_tabla
                  AND CONSTRAINT_NAME = p_nombre AND DELETE_RULE = 'CASCADE') THEN
        SET @sql := CONCAT('ALTER TABLE ', p_tabla, ' DROP FOREIGN KEY ', p_nombre);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = p_tabla
                      AND CONSTRAINT_NAME = p_nombre) THEN
        SET @sql := CONCAT('ALTER TABLE ', p_tabla, ' ADD CONSTRAINT ', p_nombre, ' ', p_definicion);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

-- La FK primero: el índice redundante no se suelta mientras ella lo usa.
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalle'
                AND CONSTRAINT_NAME = 'fk_detalle_venta' AND DELETE_RULE = 'CASCADE');
SET @sql := IF(@hay, 'ALTER TABLE venta_detalle DROP FOREIGN KEY fk_detalle_venta', 'SELECT ''fk_detalle_venta ya no es en cascada'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalle' AND INDEX_NAME = 'ix_detalle_venta');
SET @sql := IF(@hay, 'ALTER TABLE venta_detalle DROP INDEX ix_detalle_venta', 'SELECT ''venta_detalle ya no tiene ix_detalle_venta'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CALL tmp_fk_restrict('venta_detalle', 'fk_detalle_venta', 'FOREIGN KEY (venta_id) REFERENCES ventas (id) ON DELETE RESTRICT');
CALL tmp_fk_restrict('venta_pagos', 'fk_pagos_venta', 'FOREIGN KEY (venta_id) REFERENCES ventas (id) ON DELETE RESTRICT');
CALL tmp_fk_restrict('pedido_detalle', 'fk_pedidodet_pedido', 'FOREIGN KEY (pedido_id) REFERENCES pedidos (id) ON DELETE RESTRICT');

DROP PROCEDURE IF EXISTS tmp_fk_restrict;
