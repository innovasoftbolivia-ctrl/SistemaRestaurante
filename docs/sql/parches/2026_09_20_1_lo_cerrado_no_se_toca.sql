-- =============================================================================
--  SISTEMA DEL RESTAURANTE
--  Parche - Lo cerrado no se toca, y reglas que la base todavía no exigía
--  (2026-09-20, 1)
--
--  Hallazgos de la auditoría del 20/09. La aplicación ya respetaba todo esto;
--  lo que faltaba es que la base lo exigiera también para lo que entra por
--  fuera de ella (un script, phpMyAdmin, una aplicación con un error).
--
--  1. Nada documentado se borra. Solo `ventas` y `pedidos` tenían trigger que
--     impidiera el DELETE; el RESTRICT de las FK solo protege la cabecera.
--     Ahora tampoco se borran `comprobantes` (quedaba un hueco en el
--     correlativo), `venta_detalle`, `venta_pagos` ni `pedido_detalle`.
--  2. A una venta anulada, o que ya tiene su comprobante, no se le agregan
--     líneas ni pagos: el subtotal guardado y el comprobante emitido dejaban
--     de coincidir con el detalle. El comprobante es el último paso de la
--     venta, así que el flujo normal no cambia.
--  3. En un turno de caja cerrado no entran movimientos ni cobros QR: el
--     arqueo ya firmado dejaba de poder recalcularse. Y el efectivo contado
--     al cerrar no puede ser negativo (`ck_sesion_declarado`).
--  4. CHECK nuevos:
--       pedidos        ck_pedidos_cerrador     cerrado ⇔ tiene quién lo cerró
--       clientes       ck_clientes_sin_doc     «Sin documento» no lleva número
--       series         ck_series_longitud/serie/tope: el número impreso nunca
--                      se corta (LPAD truncaba el 1000000 a 100000)
--       comprobantes   ck_comprobante_persona  cliente ⇔ tipo de persona
--       configuracion  ck_config_largo         los datos del negocio caben en
--                      el comprobante (si no, emitir abortaba y no se vendía)
--     `comprobantes.emitido_por` pasa a NOT NULL: siempre se llena.
--  5. `uq_comprobante_sustituye`: un comprobante se sustituye una sola vez
--     (reemplaza al índice simple `ix_comprobante_sustituye`).
--  6. `ix_ventas_usuario` pasa a (usuario_id, fecha): la portada y «mis
--     ventas» filtran por las dos.
--  7. `metodos_pago.id` pasa de TINYINT a INT, por lo mismo que el parche
--     2026_09_19_5 (el AUTO_INCREMENT no vuelve atrás).
--  8. `v_ventas_por_metodo_pago` agrupa por JORNADA, como `v_ventas_por_dia`
--     y los reportes: una venta de las 02:00 era de un día en una vista y del
--     siguiente en la otra.
--
--  Los triggers de 1 a 3 no tienen réplica en `ReglasEnPhp`: impiden algo que
--  la aplicación nunca hace, el mismo criterio que `trg_ventas_before_delete`.
--  Los CHECK valen igual en las dos vías.
--
--  ANTES DE TOCAR NADA comprueba que los datos cumplan los CHECK nuevos. Si
--  alguno no, muestra cuáles y ABORTA sin cambiar nada.
--
--  Idempotente. Se aplica después de 2026_09_19_6_sin_pantalla_de_respaldos.sql.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

-- ------------------------------------------------------- antes de tocar nada

DROP PROCEDURE IF EXISTS tmp_verificar_reglas;

DELIMITER $$

CREATE PROCEDURE tmp_verificar_reglas ()
BEGIN
    DROP TEMPORARY TABLE IF EXISTS tmp_violaciones;
    CREATE TEMPORARY TABLE tmp_violaciones (
        regla   VARCHAR(40)  NOT NULL,
        filas   INT UNSIGNED NOT NULL,
        ejemplo VARCHAR(255) NULL
    );

    INSERT INTO tmp_violaciones
    SELECT 'ck_pedidos_cerrador', COUNT(*), GROUP_CONCAT(id ORDER BY id SEPARATOR ',')
      FROM pedidos WHERE NOT ((estado = 'ABIERTO') = (cerrado_por IS NULL))
    UNION ALL
    SELECT 'ck_sesion_declarado', COUNT(*), GROUP_CONCAT(id ORDER BY id SEPARATOR ',')
      FROM sesiones_caja WHERE monto_declarado < 0
    UNION ALL
    SELECT 'ck_clientes_sin_doc', COUNT(*), GROUP_CONCAT(id ORDER BY id SEPARATOR ',')
      FROM clientes WHERE tipo_documento = 'SIN' AND documento IS NOT NULL
    UNION ALL
    SELECT 'ck_series', COUNT(*), GROUP_CONCAT(id ORDER BY id SEPARATOR ',')
      FROM series_comprobante
     WHERE NOT (longitud BETWEEN 1 AND 13 AND CHAR_LENGTH(serie) BETWEEN 1 AND 6
                AND correlativo_actual < POW(10, longitud))
    UNION ALL
    SELECT 'ck_comprobante_persona', COUNT(*), GROUP_CONCAT(id ORDER BY id SEPARATOR ',')
      FROM comprobantes WHERE NOT ((cliente_id IS NULL) = (tipo_persona IS NULL))
    UNION ALL
    SELECT 'emitido_por', COUNT(*), GROUP_CONCAT(id ORDER BY id SEPARATOR ',')
      FROM comprobantes WHERE emitido_por IS NULL
    UNION ALL
    SELECT 'uq_comprobante_sustituye', COUNT(*), GROUP_CONCAT(sustituye_a ORDER BY sustituye_a SEPARATOR ',')
      FROM (SELECT sustituye_a FROM comprobantes WHERE sustituye_a IS NOT NULL
             GROUP BY sustituye_a HAVING COUNT(*) > 1) d
    UNION ALL
    SELECT 'ck_config_largo', COUNT(*), GROUP_CONCAT(clave ORDER BY clave SEPARATOR ',')
      FROM configuracion
     WHERE CHAR_LENGTH(valor) > CASE clave
               WHEN 'negocio_nombre' THEN 120 WHEN 'negocio_documento' THEN 20
               WHEN 'negocio_direccion' THEN 200 WHEN 'negocio_telefono' THEN 30
               WHEN 'cliente_generico_nombre' THEN 150 ELSE 255 END;

    DELETE FROM tmp_violaciones WHERE filas = 0;

    IF EXISTS (SELECT 1 FROM tmp_violaciones) THEN
        SELECT * FROM tmp_violaciones;
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Hay datos que no cumplen las reglas nuevas (ver la tabla de arriba). No se cambió nada: corrígelos y vuelve a aplicar.';
    END IF;

    DROP TEMPORARY TABLE IF EXISTS tmp_violaciones;
END$$

DELIMITER ;

CALL tmp_verificar_reglas();
DROP PROCEDURE IF EXISTS tmp_verificar_reglas;

-- ------------------------------------------------------------ CHECK nuevos

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

CALL tmp_agregar_check('pedidos', 'ck_pedidos_cerrador', '(estado = ''ABIERTO'') = (cerrado_por IS NULL)');
CALL tmp_agregar_check('sesiones_caja', 'ck_sesion_declarado', 'monto_declarado IS NULL OR monto_declarado >= 0');
CALL tmp_agregar_check('clientes', 'ck_clientes_sin_doc', 'tipo_documento <> ''SIN'' OR documento IS NULL');
CALL tmp_agregar_check('series_comprobante', 'ck_series_longitud', 'longitud BETWEEN 1 AND 13');
CALL tmp_agregar_check('series_comprobante', 'ck_series_serie', 'CHAR_LENGTH(serie) BETWEEN 1 AND 6');
CALL tmp_agregar_check('series_comprobante', 'ck_series_tope', 'correlativo_actual < POW(10, longitud)');
CALL tmp_agregar_check('comprobantes', 'ck_comprobante_persona', '(cliente_id IS NULL) = (tipo_persona IS NULL)');
CALL tmp_agregar_check('configuracion', 'ck_config_largo',
    'CHAR_LENGTH(valor) <= CASE clave WHEN ''negocio_nombre'' THEN 120 WHEN ''negocio_documento'' THEN 20 WHEN ''negocio_direccion'' THEN 200 WHEN ''negocio_telefono'' THEN 30 WHEN ''cliente_generico_nombre'' THEN 150 ELSE 255 END');

DROP PROCEDURE IF EXISTS tmp_agregar_check;

-- ------------------------------------------- columnas, índices e identificadores

DROP PROCEDURE IF EXISTS tmp_ajustar_columnas;

DELIMITER $$

CREATE PROCEDURE tmp_ajustar_columnas ()
BEGIN
    IF (SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comprobantes' AND COLUMN_NAME = 'emitido_por') = 'YES' THEN
        -- La FK se suelta un momento: MySQL no cambia una columna que la usa.
        ALTER TABLE comprobantes DROP FOREIGN KEY fk_comprobante_usuario;
        ALTER TABLE comprobantes MODIFY emitido_por INT UNSIGNED NOT NULL;
        ALTER TABLE comprobantes
            ADD CONSTRAINT fk_comprobante_usuario FOREIGN KEY (emitido_por) REFERENCES usuarios (id);
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comprobantes'
                  AND INDEX_NAME = 'ix_comprobante_sustituye') THEN
        ALTER TABLE comprobantes
            ADD UNIQUE KEY uq_comprobante_sustituye (sustituye_a),
            DROP INDEX ix_comprobante_sustituye;
    END IF;

    IF (SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas'
           AND INDEX_NAME = 'ix_ventas_usuario') = 1 THEN
        ALTER TABLE ventas
            ADD KEY ix_ventas_usuario_fecha (usuario_id, fecha),
            DROP INDEX ix_ventas_usuario;
        ALTER TABLE ventas RENAME INDEX ix_ventas_usuario_fecha TO ix_ventas_usuario;
    END IF;

    IF (SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'metodos_pago' AND COLUMN_NAME = 'id') = 'tinyint' THEN
        ALTER TABLE venta_pagos DROP FOREIGN KEY fk_pagos_metodo;
        ALTER TABLE metodos_pago MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT;
        ALTER TABLE venta_pagos MODIFY metodo_pago_id INT UNSIGNED NOT NULL;
        ALTER TABLE venta_pagos
            ADD CONSTRAINT fk_pagos_metodo FOREIGN KEY (metodo_pago_id) REFERENCES metodos_pago (id);
    END IF;
END$$

DELIMITER ;

CALL tmp_ajustar_columnas();
DROP PROCEDURE IF EXISTS tmp_ajustar_columnas;

-- ---------------------------------------------------------------- triggers

DROP TRIGGER IF EXISTS trg_comprobantes_before_delete;
DROP TRIGGER IF EXISTS trg_venta_detalle_before_delete;
DROP TRIGGER IF EXISTS trg_venta_pagos_before_delete;
DROP TRIGGER IF EXISTS trg_pedido_detalle_before_delete;
DROP TRIGGER IF EXISTS trg_venta_detalle_before_insert;
DROP TRIGGER IF EXISTS trg_venta_pagos_before_insert;
DROP TRIGGER IF EXISTS trg_movimientos_caja_before_insert;
DROP TRIGGER IF EXISTS trg_cobros_qr_before_insert;

DELIMITER $$

CREATE TRIGGER trg_comprobantes_before_delete
BEFORE DELETE ON comprobantes
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Los comprobantes no se eliminan: se anulan o se sustituyen, conservando su número.';
END$$

CREATE TRIGGER trg_venta_detalle_before_delete
BEFORE DELETE ON venta_detalle
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El detalle de una venta no se elimina. Use la anulación (RNF6).';
END$$

CREATE TRIGGER trg_venta_pagos_before_delete
BEFORE DELETE ON venta_pagos
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Los pagos de una venta no se eliminan. Use la anulación (RNF6).';
END$$

CREATE TRIGGER trg_pedido_detalle_before_delete
BEFORE DELETE ON pedido_detalle
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Los platos de un pedido no se eliminan: se cancelan, con su motivo.';
END$$

CREATE TRIGGER trg_venta_detalle_before_insert
BEFORE INSERT ON venta_detalle
FOR EACH ROW
BEGIN
    DECLARE v_afecto TINYINT(1);

    -- Una venta anulada, o que ya tiene su comprobante, está cerrada: una
    -- línea más descuadraría el subtotal guardado y lo impreso.
    IF (SELECT estado FROM ventas WHERE id = NEW.venta_id) <> 'COMPLETADA'
       OR EXISTS (SELECT 1 FROM comprobantes WHERE venta_id = NEW.venta_id) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La venta ya está cerrada: no admite más líneas.';
    END IF;

    -- La línea sigue el modo de precio de su venta: todas iguales.
    SET NEW.impuesto_incluido = IFNULL((SELECT impuesto_incluido FROM ventas WHERE id = NEW.venta_id), 0);

    SELECT afecto_impuesto INTO v_afecto FROM productos WHERE id = NEW.producto_id;
    SET NEW.afecto_impuesto = IFNULL(v_afecto, 0);
    -- NULLIF: un valor vacío cuenta como ausente, igual que en PHP.
    SET NEW.tasa_impuesto = IF(NEW.afecto_impuesto = 1,
        IFNULL((SELECT CAST(NULLIF(valor, '') AS DECIMAL(6,4)) FROM configuracion
                 WHERE clave = 'tasa_impuesto'), 0), 0);
END$$

CREATE TRIGGER trg_venta_pagos_before_insert
BEFORE INSERT ON venta_pagos
FOR EACH ROW
BEGIN
    IF (SELECT estado FROM ventas WHERE id = NEW.venta_id) <> 'COMPLETADA'
       OR EXISTS (SELECT 1 FROM comprobantes WHERE venta_id = NEW.venta_id) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La venta ya está cerrada: no admite más pagos.';
    END IF;
END$$

CREATE TRIGGER trg_movimientos_caja_before_insert
BEFORE INSERT ON movimientos_caja
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM sesiones_caja
                    WHERE id = NEW.sesion_caja_id AND estado = 'ABIERTA') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El movimiento debe registrarse en un turno de caja abierto';
    END IF;
END$$

CREATE TRIGGER trg_cobros_qr_before_insert
BEFORE INSERT ON cobros_qr
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM sesiones_caja
                    WHERE id = NEW.sesion_caja_id AND estado = 'ABIERTA') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El cobro QR debe generarse en un turno de caja abierto';
    END IF;
END$$

DELIMITER ;

-- ---------------------------------------------------------------- la vista

CREATE OR REPLACE VIEW v_ventas_por_metodo_pago AS
SELECT j.dia                   AS dia,
       j.metodo_pago           AS metodo_pago,
       COUNT(DISTINCT j.venta) AS cantidad_ventas,
       SUM(j.monto)            AS monto
  FROM (
        SELECT DATE(v.fecha - INTERVAL IFNULL((SELECT CAST(NULLIF(c.valor, '') AS UNSIGNED)
                                                  FROM configuracion c
                                                 WHERE c.clave = 'hora_corte_jornada'), 5) HOUR) AS dia,
               mp.nombre AS metodo_pago,
               v.id      AS venta,
               vp.monto  AS monto
          FROM venta_pagos vp
          JOIN ventas v        ON v.id  = vp.venta_id AND v.estado <> 'ANULADA'
          JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id
       ) AS j
 GROUP BY j.dia, j.metodo_pago;
