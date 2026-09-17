-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - La devolución se registra CON impuesto  (2026-08-21)
--
--  Problema que corrige
--  --------------------
--  `devoluciones.total` se calculaba como la suma de `devolucion_detalle.importe`,
--  y ese importe es la BASE (cantidad × precio_unitario, sin impuesto). Como
--  `sp_cerrar_caja` descuenta ese mismo total del efectivo esperado, devolverle
--  al cliente lo que realmente pagó dejaba la caja con un faltante igual al
--  impuesto de lo devuelto.
--
--  Qué hace
--  --------
--  1. Añade a `devolucion_detalle` el mismo desglose de impuesto que ya tiene
--     `venta_detalle` (afecto_impuesto, tasa_impuesto, impuesto_linea, total_linea).
--  2. Crea el trigger BEFORE INSERT que copia el régimen de la línea de venta.
--  3. Rehace el trigger AFTER INSERT para que el total sume `total_linea`.
--  4. Rellena las devoluciones que ya estaban registradas.
--
--  Es idempotente: se puede ejecutar más de una vez sin romper nada.
--
--      docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 \
--          -uroot -pventas123 ventas_db \
--          < docs/sql/parches/2026_08_21_devolucion_con_impuesto.sql
--
--  Los instaladores nuevos NO necesitan este parche: 01_schema_mysql.sql ya
--  crea el esquema corregido. Vive fuera de `docs/sql/` a propósito: esa
--  carpeta se monta como /docker-entrypoint-initdb.d y MySQL ejecutaría el
--  parche en cada instalación nueva. El subdirectorio no lo recorre.
-- =============================================================================

-- ------------------------------------------------------------------ 1. columnas
SET NAMES utf8mb4;

SET @faltan := (
    SELECT COUNT(*) = 0
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'devolucion_detalle'
       AND column_name  = 'tasa_impuesto'
);

SET @sql := IF(@faltan, '
    ALTER TABLE devolucion_detalle
        ADD COLUMN afecto_impuesto TINYINT(1)   NOT NULL DEFAULT 1 AFTER importe,
        ADD COLUMN tasa_impuesto   DECIMAL(6,4) NOT NULL DEFAULT 0.0000 AFTER afecto_impuesto,
        ADD COLUMN impuesto_linea  DECIMAL(12,2) GENERATED ALWAYS AS
                   (ROUND(ROUND(cantidad * precio_unitario, 2)
                          * IF(afecto_impuesto = 1, tasa_impuesto, 0), 2)) STORED AFTER tasa_impuesto,
        ADD COLUMN total_linea     DECIMAL(12,2) GENERATED ALWAYS AS
                   (ROUND(cantidad * precio_unitario, 2)
                    + ROUND(ROUND(cantidad * precio_unitario, 2)
                            * IF(afecto_impuesto = 1, tasa_impuesto, 0), 2)) STORED AFTER impuesto_linea
', 'DO 0');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------ 2. triggers
DROP TRIGGER IF EXISTS trg_devolucion_detalle_before_insert;
DROP TRIGGER IF EXISTS trg_devolucion_detalle_after_insert;

DELIMITER $$

CREATE TRIGGER trg_devolucion_detalle_before_insert
BEFORE INSERT ON devolucion_detalle
FOR EACH ROW
BEGIN
    DECLARE v_afecto TINYINT(1);
    DECLARE v_tasa   DECIMAL(6,4);

    IF NEW.tasa_impuesto = 0 THEN
        SELECT afecto_impuesto, tasa_impuesto
          INTO v_afecto, v_tasa
          FROM venta_detalle WHERE id = NEW.venta_detalle_id;

        SET NEW.afecto_impuesto = IFNULL(v_afecto, 0);
        SET NEW.tasa_impuesto   = IF(NEW.afecto_impuesto = 1, IFNULL(v_tasa, 0), 0);
    END IF;
END$$

CREATE TRIGGER trg_devolucion_detalle_after_insert
AFTER INSERT ON devolucion_detalle
FOR EACH ROW
BEGIN
    DECLARE v_stock    DECIMAL(12,3);
    DECLARE v_usuario  INT UNSIGNED;
    DECLARE v_venta_id BIGINT UNSIGNED;
    DECLARE v_pend     INT;

    UPDATE venta_detalle
       SET cantidad_devuelta = cantidad_devuelta + NEW.cantidad
     WHERE id = NEW.venta_detalle_id;

    SELECT venta_id INTO v_venta_id FROM devoluciones WHERE id = NEW.devolucion_id;

    -- CON impuesto: es el dinero que sale del cajón.
    UPDATE devoluciones
       SET total = (SELECT IFNULL(SUM(total_linea), 0)
                      FROM devolucion_detalle WHERE devolucion_id = NEW.devolucion_id)
     WHERE id = NEW.devolucion_id;

    UPDATE ventas
       SET total_devuelto = (SELECT IFNULL(SUM(total), 0)
                               FROM devoluciones WHERE venta_id = v_venta_id)
     WHERE id = v_venta_id;

    SELECT COUNT(*) INTO v_pend
      FROM venta_detalle
     WHERE venta_id = v_venta_id AND cantidad_devuelta < cantidad;

    UPDATE ventas
       SET estado = IF(v_pend = 0, 'DEVUELTA', 'DEVUELTA_PARCIAL')
     WHERE id = v_venta_id
       AND estado IN ('COMPLETADA', 'DEVUELTA_PARCIAL', 'DEVUELTA');

    IF NEW.reingresa_stock = 1 THEN
        SELECT stock_actual INTO v_stock
          FROM productos WHERE id = NEW.producto_id FOR UPDATE;

        UPDATE productos
           SET stock_actual = stock_actual + NEW.cantidad
         WHERE id = NEW.producto_id;

        SELECT usuario_id INTO v_usuario
          FROM devoluciones WHERE id = NEW.devolucion_id;

        INSERT INTO movimientos_inventario
            (producto_id, usuario_id, tipo, origen, devolucion_id,
             cantidad, stock_anterior, stock_resultante, motivo)
        VALUES
            (NEW.producto_id, v_usuario, 'ENTRADA', 'DEVOLUCION', NEW.devolucion_id,
             NEW.cantidad, v_stock, v_stock + NEW.cantidad, 'Devolución de cliente');
    END IF;
END$$

DELIMITER ;

-- ------------------------------------------------- 3. devoluciones ya registradas
-- Las líneas viejas quedaron con tasa 0. Se les copia el régimen de su línea de
-- venta y se recalculan los totales de arriba hacia abajo.
UPDATE devolucion_detalle dd
  JOIN venta_detalle vd ON vd.id = dd.venta_detalle_id
   SET dd.afecto_impuesto = vd.afecto_impuesto,
       dd.tasa_impuesto   = IF(vd.afecto_impuesto = 1, vd.tasa_impuesto, 0)
 WHERE dd.tasa_impuesto = 0;

UPDATE devoluciones d
   SET d.total = (SELECT IFNULL(SUM(total_linea), 0)
                    FROM devolucion_detalle WHERE devolucion_id = d.id);

UPDATE ventas v
   SET v.total_devuelto = (SELECT IFNULL(SUM(total), 0)
                             FROM devoluciones WHERE venta_id = v.id)
 WHERE EXISTS (SELECT 1 FROM devoluciones WHERE venta_id = v.id);

-- ------------------------------------------------------------------ verificación
SELECT d.id            AS devolucion,
       d.total         AS total_con_impuesto,
       SUM(dd.importe) AS base_sin_impuesto,
       v.total         AS total_venta,
       v.total_devuelto
  FROM devoluciones d
  JOIN devolucion_detalle dd ON dd.devolucion_id = d.id
  JOIN ventas v              ON v.id = d.venta_id
 GROUP BY d.id, d.total, v.total, v.total_devuelto;
