-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Fuera el inventario y las devoluciones (2026-09-17)
--
--  El negocio pasa a ser un restaurante. Se van dos módulos enteros:
--
--  * el inventario (stock, lotes y vencimientos, compras y devoluciones al
--    proveedor, tomas de inventario y proveedores): un restaurante de este
--    tamaño no lleva kardex de insumos;
--  * las devoluciones de cliente: lo que se sirvió no vuelve a la carta, así
--    que una venta equivocada se anula entera.
--
--  Y con ellos el rol Almacenero, que se queda sin trabajo.
--
--  - DROP de las tablas de inventario, en el orden que exigen sus claves.
--  - `productos` pierde el empaque, el control de vencimiento, el proveedor y
--    el stock, con sus índices, claves y restricciones.
--  - Fuera `trg_venta_detalle_after_insert` (descontaba stock) y
--    `trg_movimientos_inventario_before_insert` (su tabla ya no existe).
--  - `trg_devolucion_detalle_after_insert` y `sp_anular_venta` se reemplazan
--    por su versión sin stock: lo demás que hacían sigue igual.
--  - Fuera las vistas `v_alertas_stock` y `v_kardex`, y los permisos
--    `inventario.ingresar` / `inventario.ajustar`.
--  - Fuera `devoluciones` y `devolucion_detalle` con sus triggers,
--    `ventas.total_devuelto`, `venta_detalle.cantidad_devuelta` y los estados
--    DEVUELTA / DEVUELTA_PARCIAL; `sp_cerrar_caja` deja de restar devoluciones.
--
--  ATENCIÓN: borra datos. El historial de compras, lotes, kardex y devoluciones
--  se pierde.
--  Haz un respaldo antes (Sistema > Respaldos o scripts/backup-db.sh).
--
--  Idempotente: cada paso comprueba si queda algo por hacer.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

-- --------------------------------------------------------------------- vistas
DROP VIEW IF EXISTS v_alertas_stock;
DROP VIEW IF EXISTS v_kardex;

-- ------------------------------------------------------------------- triggers
DROP TRIGGER IF EXISTS trg_venta_detalle_after_insert;
DROP TRIGGER IF EXISTS trg_movimientos_inventario_before_insert;

-- -------------------------------------------------------------------- tablas
-- De las hojas hacia la raíz: cada una se borra cuando ya nadie la referencia.
DROP TABLE IF EXISTS toma_inventario_detalle;
DROP TABLE IF EXISTS tomas_inventario;
DROP TABLE IF EXISTS movimientos_inventario;
DROP TABLE IF EXISTS lote_salidas;
DROP TABLE IF EXISTS devolucion_compra_detalle;
DROP TABLE IF EXISTS devoluciones_compra;
DROP TABLE IF EXISTS lotes;
DROP TABLE IF EXISTS compra_detalle;
DROP TABLE IF EXISTS compras;

-- ----------------------------------------------------------------- productos
-- La clave hacia `proveedores` primero: sin ella la tabla se puede borrar.
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'
                AND CONSTRAINT_NAME = 'fk_productos_proveedor' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql := IF(@hay, 'ALTER TABLE productos DROP FOREIGN KEY fk_productos_proveedor', 'SELECT ''productos ya no tiene fk_productos_proveedor'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND INDEX_NAME = 'ix_productos_proveedor');
SET @sql := IF(@hay, 'ALTER TABLE productos DROP INDEX ix_productos_proveedor', 'SELECT ''productos ya no tiene ix_productos_proveedor'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Las restricciones antes que las columnas: MySQL no deja borrar una columna
-- que usa un CHECK.
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'
                AND CONSTRAINT_NAME = 'ck_productos_stock' AND CONSTRAINT_TYPE = 'CHECK');
SET @sql := IF(@hay, 'ALTER TABLE productos DROP CHECK ck_productos_stock', 'SELECT ''productos ya no tiene ck_productos_stock'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'
                AND CONSTRAINT_NAME = 'ck_productos_empaque' AND CONSTRAINT_TYPE = 'CHECK');
SET @sql := IF(@hay, 'ALTER TABLE productos DROP CHECK ck_productos_empaque', 'SELECT ''productos ya no tiene ck_productos_empaque'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP PROCEDURE IF EXISTS tmp_quitar_columna;

DELIMITER $$

CREATE PROCEDURE tmp_quitar_columna (IN p_columna VARCHAR(64))
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos'
                  AND COLUMN_NAME = p_columna) THEN
        SET @sql := CONCAT('ALTER TABLE productos DROP COLUMN ', p_columna);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CALL tmp_quitar_columna('contenido_empaque');
CALL tmp_quitar_columna('nombre_empaque');
CALL tmp_quitar_columna('controla_vencimiento');
CALL tmp_quitar_columna('proveedor_id');
CALL tmp_quitar_columna('stock_actual');
CALL tmp_quitar_columna('stock_minimo');

DROP PROCEDURE IF EXISTS tmp_quitar_columna;

DROP TABLE IF EXISTS proveedores;

-- ------------------------------------- la anulación, sin reponer stock
DROP PROCEDURE IF EXISTS sp_anular_venta;
-- Y el de sustituir, que hablaba de «una venta anulada o devuelta».
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
    SET v_dias_max = IFNULL((SELECT CAST(valor AS SIGNED) FROM configuracion
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

CREATE PROCEDURE sp_anular_venta (
    IN p_venta_id   BIGINT UNSIGNED,
    IN p_usuario_id INT UNSIGNED,
    IN p_motivo     VARCHAR(255)
)
BEGIN
    DECLARE v_estado VARCHAR(20);

    SELECT estado INTO v_estado FROM ventas WHERE id = p_venta_id FOR UPDATE;

    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La venta no existe';
    END IF;
    IF v_estado <> 'COMPLETADA' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se puede anular una venta COMPLETADA';
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

-- ------------------------------------------------------------------- permisos
-- Primero lo que cuelga de ellos: `rol_permiso` los referencia.
DELETE rp FROM rol_permiso rp
  JOIN permisos p ON p.id = rp.permiso_id
 WHERE p.codigo IN ('inventario.ingresar', 'inventario.ajustar');

DELETE FROM permisos WHERE codigo IN ('inventario.ingresar', 'inventario.ajustar');

UPDATE permisos
   SET descripcion = 'Eliminar productos, categorías, unidades, clientes y personal'
 WHERE codigo = 'registros.eliminar'
   AND descripcion = 'Eliminar productos, categorías, unidades, proveedores, clientes y personal';

-- ================================================================ devoluciones
--
--  En el restaurante una venta mal cobrada se ANULA entera: no hay devolución
--  parcial de mercadería, porque lo que se sirvió no vuelve a la carta.
--
--  ESTO BORRA HISTORIAL. Las devoluciones ya registradas se pierden, y con
--  ellas el detalle de qué se devolvió y cuánto dinero salió del cajón por esa
--  vía. Los arqueos ya firmados NO se recalculan: `sesiones_caja` guarda su
--  `monto_esperado` y su `monto_declarado` como números, así que los cierres
--  viejos siguen diciendo lo mismo que el día que se firmaron. Lo que cambia
--  es la fórmula de los cierres FUTUROS, que ya no resta devoluciones.
--
--  Las ventas que estaban DEVUELTA o DEVUELTA_PARCIAL vuelven a COMPLETADA
--  antes de estrechar el ENUM: siguen siendo ventas cobradas, y su comprobante
--  sigue emitido. Si alguna se devolvió entera y el negocio no quiere contarla,
--  hay que anularla a mano después de aplicar el parche.

UPDATE ventas SET estado = 'COMPLETADA' WHERE estado IN ('DEVUELTA', 'DEVUELTA_PARCIAL');

ALTER TABLE ventas MODIFY estado ENUM('COMPLETADA','ANULADA') NOT NULL DEFAULT 'COMPLETADA';

DROP TRIGGER IF EXISTS trg_devolucion_detalle_before_insert;
DROP TRIGGER IF EXISTS trg_devolucion_detalle_after_insert;

DROP TABLE IF EXISTS devolucion_detalle;
DROP TABLE IF EXISTS devoluciones;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalle'
                AND CONSTRAINT_NAME = 'ck_detalle_devuelta' AND CONSTRAINT_TYPE = 'CHECK');
SET @sql := IF(@hay, 'ALTER TABLE venta_detalle DROP CHECK ck_detalle_devuelta', 'SELECT ''venta_detalle ya no tiene ck_detalle_devuelta'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalle' AND COLUMN_NAME = 'cantidad_devuelta');
SET @sql := IF(@hay, 'ALTER TABLE venta_detalle DROP COLUMN cantidad_devuelta', 'SELECT ''venta_detalle ya no tiene cantidad_devuelta'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas' AND COLUMN_NAME = 'total_devuelto');
SET @sql := IF(@hay, 'ALTER TABLE ventas DROP COLUMN total_devuelto', 'SELECT ''ventas ya no tiene total_devuelto'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- El ranking dejaba de compilar en cuanto `cantidad_devuelta` desapareció:
-- se rehace sin el prorrateo de lo devuelto.
--
-- Solo mientras `costo_unitario` siga existiendo. El parche
-- `2026_09_17_sin_costo_de_compra.sql`, que va después en esta misma tanda,
-- vuelve a definir la vista sin el margen y borra esa columna: sin esta
-- guarda, repasar este parche encima de aquél dejaría la vista apuntando a
-- una columna que ya no existe —y el CREATE fallaría a mitad, con la vista
-- ya borrada—.
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venta_detalle'
                AND COLUMN_NAME = 'costo_unitario');
-- Productos más vendidos (HU-33)
-- El importe de cada línea va neto del descuento de la venta, repartido entre
-- sus líneas en la misma proporción en que `sp_recalcular_venta` reparte el
-- impuesto: así el descuento de cabecera no hace falta repetirlo aquí.
-- Las ventas anuladas no cuentan, y el costo es el que tenía el producto el
-- día que se vendió.
SET @sql := IF(@hay, '
CREATE OR REPLACE VIEW v_productos_mas_vendidos AS
SELECT p.id, p.codigo, p.nombre, c.nombre AS categoria,
       SUM(n.unidades_netas)                                     AS unidades_vendidas,
       SUM(n.monto_neto)                                         AS monto_vendido,
       SUM(n.monto_neto - ROUND(n.unidades_netas * n.costo, 2))  AS margen_estimado
  FROM (
        SELECT d.producto_id,
               d.cantidad AS unidades_netas,
               ROUND(d.importe
                     * IF(v.subtotal > 0, (v.subtotal - v.descuento) / v.subtotal, 1), 2) AS monto_neto,
               COALESCE(d.costo_unitario, pc.precio_compra) AS costo
          FROM venta_detalle d
          JOIN ventas v ON v.id = d.venta_id AND v.estado <> ''ANULADA''
          JOIN productos pc ON pc.id = d.producto_id
       ) AS n
  JOIN productos  p ON p.id = n.producto_id
  JOIN categorias c ON c.id = p.categoria_id
 GROUP BY p.id, p.codigo, p.nombre, c.nombre',
    'SELECT ''el ranking ya no lleva costo: lo define 2026_09_17_sin_costo_de_compra.sql'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DELETE rp FROM rol_permiso rp
  JOIN permisos p ON p.id = rp.permiso_id
 WHERE p.codigo = 'devoluciones.registrar';

DELETE FROM permisos WHERE codigo = 'devoluciones.registrar';

DELETE FROM configuracion WHERE clave = 'dias_max_devolucion';

-- ============================================================ rol Almacenero
--
--  Sin inventario que llevar, el rol se queda sin trabajo: en el restaurante
--  la carta la mantiene el administrador. Las cuentas que todavía lo tengan
--  pasan a Cajero —seguir entrando importa más que el permiso exacto, y un
--  administrador les ajusta el rol después— en lugar de desactivarlas, que
--  dejaría a alguien sin poder trabajar al día siguiente del parche.

UPDATE usuarios SET rol_id = (SELECT id FROM roles WHERE nombre = 'Cajero')
 WHERE rol_id IN (SELECT id FROM (SELECT id FROM roles WHERE nombre = 'Almacenero') AS r)
   AND EXISTS (SELECT 1 FROM roles WHERE nombre = 'Cajero');

DELETE rp FROM rol_permiso rp JOIN roles r ON r.id = rp.rol_id WHERE r.nombre = 'Almacenero';

DELETE FROM roles WHERE nombre = 'Almacenero';

-- ===================================================== el arqueo sin devoluciones
--
--  `sp_cerrar_caja` ya no resta devoluciones: del cajón entra lo cobrado en
--  efectivo y salen los egresos. Una venta anulada deja de contarse arriba,
--  que es lo que ahora corrige un cobro equivocado.

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

    -- Del cajón entra lo cobrado en efectivo y salen los egresos: no hay
    -- devoluciones que restar, porque una venta mal cobrada se anula y su
    -- efectivo deja de contarse arriba (`v.estado <> 'ANULADA'`).
    SET v_esperado = v_inicial + v_ventas + v_ingresos - v_egresos;

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
