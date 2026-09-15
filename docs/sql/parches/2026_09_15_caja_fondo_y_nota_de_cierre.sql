-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Fondo para el siguiente turno y nota de cierre aparte (2026-09-15)
--
--  1. `observacion_cierre`: el cierre escribía su nota en `observacion` y borraba
--     la que se dejó al abrir. Los turnos ya cerrados no se pueden recuperar:
--     su `observacion` es la del cierre.
--  2. `fondo_dejado`: lo que queda en el cajón para empezar el siguiente turno.
--     Si el siguiente abre con otro monto, tiene que explicar por qué; antes el
--     monto inicial era un número suelto y un fondo declarado de menos dejaba
--     un sobrante que se podía retirar sin que el arqueo lo viera.
--
--  Idempotente: las columnas se agregan solo si faltan y el procedimiento se
--  reemplaza.
-- =============================================================================

USE ventas_db;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sesiones_caja' AND COLUMN_NAME = 'fondo_dejado');
SET @sql := IF(@falta,
    'ALTER TABLE sesiones_caja ADD COLUMN fondo_dejado DECIMAL(12,2) NULL AFTER monto_declarado',
    'SELECT ''sesiones_caja ya tiene fondo_dejado'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sesiones_caja' AND COLUMN_NAME = 'observacion_cierre');
SET @sql := IF(@falta,
    'ALTER TABLE sesiones_caja ADD COLUMN observacion_cierre VARCHAR(255) NULL AFTER observacion',
    'SELECT ''sesiones_caja ya tiene observacion_cierre'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP PROCEDURE IF EXISTS sp_cerrar_caja;

DELIMITER $$

CREATE PROCEDURE sp_cerrar_caja (
    IN p_sesion_id  INT UNSIGNED,
    IN p_usuario_id INT UNSIGNED,
    IN p_declarado  DECIMAL(12,2),
    IN p_observacion VARCHAR(255)
)
BEGIN
    DECLARE v_inicial   DECIMAL(12,2);
    DECLARE v_ventas    DECIMAL(12,2);
    DECLARE v_ingresos  DECIMAL(12,2);
    DECLARE v_egresos   DECIMAL(12,2);
    DECLARE v_devuelto  DECIMAL(12,2);
    DECLARE v_esperado  DECIMAL(12,2);

    SELECT monto_inicial INTO v_inicial
      FROM sesiones_caja WHERE id = p_sesion_id AND estado = 'ABIERTA' FOR UPDATE;

    IF v_inicial IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La sesión de caja no existe o ya está cerrada';
    END IF;

    -- solo los pagos en métodos que afectan la caja física
    -- El efectivo que queda en el cajón es `monto`: por definición
    -- monto_recibido - vuelto = monto. Restar el vuelto aquí lo descontaría dos veces.
    SELECT IFNULL(SUM(vp.monto), 0) INTO v_ventas
      FROM venta_pagos vp
      JOIN ventas v        ON v.id = vp.venta_id
      JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id
     WHERE v.sesion_caja_id = p_sesion_id
       AND v.estado <> 'ANULADA'
       AND mp.afecta_caja = 1;

    SELECT IFNULL(SUM(IF(tipo = 'INGRESO', monto, 0)), 0),
           IFNULL(SUM(IF(tipo = 'EGRESO',  monto, 0)), 0)
      INTO v_ingresos, v_egresos
      FROM movimientos_caja WHERE sesion_caja_id = p_sesion_id;

    -- De cada devolución sale del cajón solo la fracción que en su día entró
    -- en efectivo. Una venta cobrada con tarjeta se reembolsa por el mismo
    -- medio: descontarla del cajón dejaría al cajero con un sobrante.
    -- Desde el 14/09/2026 cada devolución guarda en `efectivo` lo que salió
    -- del cajón según el medio de reembolso elegido. Las anteriores no lo
    -- tienen y siguen con la proporción de siempre.
    SELECT IFNULL(SUM(IFNULL(d.efectivo,
               ROUND(d.total * IFNULL(
                   (SELECT SUM(vp.monto)
                      FROM venta_pagos vp
                      JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id
                     WHERE vp.venta_id = d.venta_id
                       AND mp.afecta_caja = 1)
                   / NULLIF(v.total, 0), 0), 2))
           ), 0) INTO v_devuelto
      FROM devoluciones d
      JOIN ventas v ON v.id = d.venta_id
     WHERE d.sesion_caja_id = p_sesion_id;

    SET v_esperado = v_inicial + v_ventas + v_ingresos - v_egresos - v_devuelto;

    -- `diferencia` es columna generada: sale sola de esperado y declarado
    UPDATE sesiones_caja
       SET fecha_cierre      = NOW(),
           usuario_cierre_id = p_usuario_id,
           monto_esperado    = v_esperado,
           monto_declarado   = p_declarado,
           estado            = 'CERRADA',
           -- en su propia columna: la nota de apertura no se pisa
           observacion_cierre = p_observacion
     WHERE id = p_sesion_id;

    SELECT v_esperado AS monto_esperado,
           p_declarado AS monto_declarado,
           ROUND(p_declarado - v_esperado, 2) AS diferencia;
END$$

DELIMITER ;

SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sesiones_caja'
   AND COLUMN_NAME IN ('fondo_dejado', 'observacion_cierre');
