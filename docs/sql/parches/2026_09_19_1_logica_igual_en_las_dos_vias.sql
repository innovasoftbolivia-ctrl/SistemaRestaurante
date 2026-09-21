-- =============================================================================
--  SISTEMA DEL RESTAURANTE
--  Parche - Lo que dicen la base y PHP, igual (2026-09-19, 1 de 4)
--
--  La aplicación tiene dos vías para las mismas reglas: los triggers y
--  procedimientos de la base, y su réplica en PHP (`ReglasEnPhp`, para un
--  hosting sin rutinas). Este parche corrige dos puntos donde decían cosas
--  distintas:
--
--  - Un valor VACÍO en `configuracion` cuenta como ausente, como en PHP: la
--    tasa de impuesto (`trg_venta_detalle_before_insert`), la moneda y el nombre
--    del cliente genérico (`sp_emitir_comprobante`) y el plazo para sustituir
--    (`sp_sustituir_comprobante`) se leen con NULLIF(valor, ''). Antes, un
--    `cliente_generico_nombre` vacío dejaba el comprobante a nombre de nadie
--    en la base y de «Cliente varios» en PHP.
--  - `sp_anular_venta` rechaza anular una venta de un turno de caja cerrado.
--    Hasta ahora solo lo impedía `Ventas::anular` en PHP; una llamada directa
--    al procedimiento cambiaba el arqueo de un cierre ya firmado.
--
--  No toca datos. Idempotente: reemplaza las rutinas enteras.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS trg_venta_detalle_before_insert;

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
        -- NULLIF: un valor vacío cuenta como ausente, igual que en PHP.
        SET NEW.tasa_impuesto = IF(NEW.afecto_impuesto = 1,
            IFNULL((SELECT CAST(NULLIF(valor, '') AS DECIMAL(6,4)) FROM configuracion
                     WHERE clave = 'tasa_impuesto'), 0), 0);
    END IF;
END$$

DELIMITER ;

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

    -- El trigger trg_comprobantes_before_insert valida que el tipo de documento
    -- corresponda al tipo de persona del cliente.
    INSERT INTO comprobantes (
        venta_id, serie_id, numero, numero_completo,
        cliente_id, tipo_persona, cliente_nombre, cliente_tipo_documento,
        cliente_documento, cliente_direccion, representante_legal,
        subtotal, descuento, impuesto, moneda, emitido_por
    )
    SELECT v.id, p_serie_id, v_numero, p_numero_completo,
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

DROP PROCEDURE IF EXISTS sp_sustituir_comprobante;

DELIMITER $$

CREATE PROCEDURE sp_sustituir_comprobante (
    IN  p_comprobante_id      BIGINT UNSIGNED,
    IN  p_serie_id            SMALLINT UNSIGNED,  -- serie del nuevo documento (define el tipo)
    IN  p_cliente_id          INT UNSIGNED,   -- cliente a asignar a la venta (NULL = no cambia)
    IN  p_usuario_id          INT UNSIGNED,
    IN  p_motivo              VARCHAR(255),
    OUT p_nuevo_id            BIGINT UNSIGNED,
    OUT p_numero_completo     VARCHAR(20)
)
BEGIN
    DECLARE v_estado_doc   VARCHAR(15);
    DECLARE v_venta_id     BIGINT UNSIGNED;
    DECLARE v_numero_ant   VARCHAR(20);
    DECLARE v_estado_venta VARCHAR(20);
    DECLARE v_fecha_venta  DATETIME;
    DECLARE v_dias_max     INT;

    SELECT estado, venta_id, numero_completo
      INTO v_estado_doc, v_venta_id, v_numero_ant
      FROM comprobantes WHERE id = p_comprobante_id FOR UPDATE;

    IF v_estado_doc IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El comprobante no existe';
    END IF;
    IF v_estado_doc <> 'EMITIDO' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Solo se puede sustituir un comprobante vigente (EMITIDO)';
    END IF;

    SELECT estado, fecha INTO v_estado_venta, v_fecha_venta
      FROM ventas WHERE id = v_venta_id FOR UPDATE;

    IF v_estado_venta <> 'COMPLETADA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'No se sustituye el comprobante de una venta anulada';
    END IF;

    -- ventana de tiempo permitida (configurable)
    SET v_dias_max = IFNULL((SELECT CAST(NULLIF(valor, '') AS SIGNED) FROM configuracion
                              WHERE clave = 'dias_max_sustitucion'), 1);
    IF DATEDIFF(NOW(), v_fecha_venta) > v_dias_max THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La venta excede el plazo permitido para sustituir su comprobante';
    END IF;

    -- si se va a facturar, la venta debe quedar asociada al cliente jurídico
    IF p_cliente_id IS NOT NULL THEN
        UPDATE ventas SET cliente_id = p_cliente_id WHERE id = v_venta_id;
    END IF;

    -- el anterior deja de ser el vigente (libera el índice único)
    UPDATE comprobantes
       SET estado        = 'SUSTITUIDO',
           sustituido_en = NOW()
     WHERE id = p_comprobante_id;

    -- el nuevo documento toma su propio correlativo; el trigger valida que el tipo
    -- corresponda al tipo de persona del cliente
    CALL sp_emitir_comprobante(v_venta_id, p_serie_id, p_nuevo_id, p_numero_completo);

    UPDATE comprobantes
       SET sustituye_a    = p_comprobante_id,
           motivo_emision = p_motivo,
           emitido_por    = p_usuario_id
     WHERE id = p_nuevo_id;

    INSERT INTO auditoria (usuario_id, accion, entidad, entidad_id, detalle)
    VALUES (p_usuario_id, 'SUSTITUIR_COMPROBANTE', 'comprobantes', p_nuevo_id,
            JSON_OBJECT('venta_id',   v_venta_id,
                        'sustituye_a', p_comprobante_id,
                        'anterior',    v_numero_ant,
                        'nuevo',       p_numero_completo,
                        'motivo',      p_motivo));
END$$

DELIMITER ;

DROP PROCEDURE IF EXISTS sp_anular_venta;

DELIMITER $$

CREATE PROCEDURE sp_anular_venta (
    IN p_venta_id   BIGINT UNSIGNED,
    IN p_usuario_id INT UNSIGNED,
    IN p_motivo     VARCHAR(255)
)
BEGIN
    DECLARE v_estado VARCHAR(20);
    DECLARE v_sesion INT UNSIGNED;
    DECLARE v_turno  VARCHAR(20);

    SELECT estado, sesion_caja_id INTO v_estado, v_sesion
      FROM ventas WHERE id = p_venta_id FOR UPDATE;

    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La venta no existe';
    END IF;
    IF v_estado <> 'COMPLETADA' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se puede anular una venta COMPLETADA';
    END IF;

    -- Con el turno bloqueado en modo compartido: un cierre en curso espera.
    SELECT estado INTO v_turno FROM sesiones_caja WHERE id = v_sesion FOR SHARE;

    IF v_turno <> 'ABIERTA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El turno de caja de esta venta ya cerró: no se anula, su dinero ya se contó en el arqueo';
    END IF;

    -- Sin inventario no hay stock que reponer: anular es cambiar el estado de
    -- la venta y dejar su comprobante anulado, con el correlativo intacto.
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
