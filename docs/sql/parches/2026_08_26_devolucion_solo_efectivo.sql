-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - La devolución solo descuenta del cajón lo que se pagó en efectivo
--           (2026-08-26)
--
--  Problema que corrige
--  --------------------
--  `sp_cerrar_caja` restaba del efectivo esperado el total ÍNTEGRO de cada
--  devolución, sin mirar cómo se había cobrado la venta original.
--
--  Si el cliente pagó con tarjeta y después devolvió la mercadería, del cajón
--  no salió ni un centavo —el reembolso va por el mismo medio—, pero el arqueo
--  esperaba menos dinero del que había. El cajero terminaba el turno con un
--  sobrante igual al importe devuelto, y ese sobrante quedaba registrado como
--  diferencia de caja a su nombre.
--
--  Reproducido antes de escribir el parche: venta de 9.00 con tarjeta, devuelta
--  entera. Con 100.00 de fondo inicial y ningún movimiento de efectivo, el
--  cierre daba «esperado 91.00, declarado 100.00, diferencia +9.00».
--
--  Qué hace
--  --------
--  Reemplaza `sp_cerrar_caja` para que descuente solo la parte de cada
--  devolución que salió del cajón, en proporción a cómo se cobró la venta:
--
--      efectivo_devuelto = devolucion.total × (pagos_que_afectan_caja / venta.total)
--
--  Con eso, los tres casos quedan bien:
--    - venta en efectivo devuelta  -> se descuenta todo
--    - venta con tarjeta devuelta  -> no se descuenta nada
--    - venta mixta devuelta        -> se descuenta la parte en efectivo
--
--  Las sesiones ya cerradas NO se tocan: `monto_esperado` es el arqueo firmado
--  de aquel turno y reescribirlo falsearía el historial. El parche corrige de
--  aquí en adelante.
--
--  Es idempotente: se puede ejecutar más de una vez sin romper nada.
--
--      docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 \
--          -uroot -pventas123 ventas_db \
--          < docs/sql/parches/2026_08_26_devolucion_solo_efectivo.sql
--
--  Los instaladores nuevos NO necesitan este parche: 01_schema_mysql.sql ya
--  crea el procedimiento corregido.
-- =============================================================================

SET NAMES utf8mb4;

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
    -- en efectivo. Una venta cobrada con tarjeta no devuelve dinero del cajón.
    SELECT IFNULL(SUM(
               ROUND(d.total * IFNULL(
                   (SELECT SUM(vp.monto)
                      FROM venta_pagos vp
                      JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id
                     WHERE vp.venta_id = d.venta_id
                       AND mp.afecta_caja = 1)
                   / NULLIF(v.total, 0), 0), 2)
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
           observacion       = p_observacion
     WHERE id = p_sesion_id;

    SELECT v_esperado AS monto_esperado,
           p_declarado AS monto_declarado,
           ROUND(p_declarado - v_esperado, 2) AS diferencia;
END$$

DELIMITER ;
