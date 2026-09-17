-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - La reposición que llega después (2026-09-11)
--
--  El problema
--  -----------
--  `devoluciones_compra.con_reposicion` era un sí o un no, y los finales de una
--  devolución son tres:
--
--      1. El proveedor la cambia EN EL MOMENTO. Sale lo fallado, entra lo
--         bueno, y el stock queda igual. Es lo que ya hacía `con_reposicion`.
--      2. Se lleva la mercadería y trae el reemplazo LA SEMANA QUE VIENE. El
--         stock baja hoy y sube cuando llegue.
--      3. No repone nada: emite una nota de crédito y queda a cuenta.
--
--  Con un booleano, el caso 2 era indistinguible del 3. La mercadería salía y
--  no quedaba constancia de que el proveedor debía algo: cuando llegaba el
--  reemplazo se cargaba como un ingreso suelto, sin hilo con la devolución que
--  lo originó. Nadie podía responder «¿qué me deben todavía?».
--
--  Qué cambia
--  ----------
--      devoluciones_compra       -> `con_reposicion` pasa a ser `espera`, con
--                                   los tres finales
--      devolucion_compra_detalle -> nueva columna `cantidad_repuesta`
--
--  Por qué `cantidad_repuesta` en la línea
--  ---------------------------------------
--  Mismo motivo que `venta_detalle.cantidad_devuelta` y que
--  `compra_detalle.cantidad_devuelta`: el proveedor puede traer 6 de las 10 que
--  debe, y hay que impedir que después traiga 7 más. Una restricción no puede
--  consultar otra tabla, así que el acumulado vive en la propia línea y el
--  CHECK lo compara contra lo devuelto.
--
--  Lo que NO cambia
--  ----------------
--  El kardex. La reposición tardía entra por `movimientos_inventario` con
--  origen DEVOLUCION_COMPRA y su `devolucion_compra_id`, exactamente igual que
--  la inmediata: para el inventario son el mismo hecho, y lo único que las
--  distingue es la fecha.
--
--  Idempotente: cada paso comprueba antes si hace falta.
-- =============================================================================

-- ------------------------------------------- 1. el estado de la cabecera
SET NAMES utf8mb4;

SET @falta := (
    SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devoluciones_compra'
      AND COLUMN_NAME = 'espera'
);

SET @sql := IF(@falta, '
    ALTER TABLE devoluciones_compra
        ADD COLUMN espera ENUM(''REPUESTO'',''PENDIENTE'',''NOTA_CREDITO'') NOT NULL DEFAULT ''NOTA_CREDITO''
            COMMENT "en qué se quedó con el proveedor"
            AFTER motivo
', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Lo ya registrado: con reposición era el cambio en el momento; sin ella, lo
-- que el formulario decía era «se espera la nota de crédito del proveedor».
SET @sql := IF(@falta, '
    UPDATE devoluciones_compra
       SET espera = IF(con_reposicion = 1, ''REPUESTO'', ''NOTA_CREDITO'')
', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sobra := (
    SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devoluciones_compra'
      AND COLUMN_NAME = 'con_reposicion'
);

-- Se va: dos columnas que dicen lo mismo terminan contradiciéndose, y aquí una
-- de las dos no sabría expresar el caso nuevo.
SET @sql := IF(@sobra, 'ALTER TABLE devoluciones_compra DROP COLUMN con_reposicion', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------- 2. cuánto de cada línea ya volvió
SET @faltaCol := (
    SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devolucion_compra_detalle'
      AND COLUMN_NAME = 'cantidad_repuesta'
);

SET @sql := IF(@faltaCol, '
    ALTER TABLE devolucion_compra_detalle
        ADD COLUMN cantidad_repuesta DECIMAL(12,3) NOT NULL DEFAULT 0.000
            COMMENT "acumulado que el proveedor ya repuso de esta línea"
            AFTER cantidad,
        ADD CONSTRAINT ck_devcompradet_repuesta CHECK (cantidad_repuesta >= 0 AND cantidad_repuesta <= cantidad)
', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- El cambio en el momento repuso todo por definición: sin esto, las
-- devoluciones viejas aparecerían debiendo mercadería que ya está en el estante.
SET @sql := IF(@faltaCol, '
    UPDATE devolucion_compra_detalle dd
      JOIN devoluciones_compra d ON d.id = dd.devolucion_compra_id
       SET dd.cantidad_repuesta = dd.cantidad
     WHERE d.espera = ''REPUESTO''
', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------ 3. el índice
-- «¿Qué me deben todavía?» es una consulta de todos los días en el almacén, y
-- sin índice recorre la tabla entera de devoluciones.
SET @faltaIx := (
    SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devoluciones_compra'
      AND INDEX_NAME = 'ix_devcompra_espera'
);

SET @sql := IF(@faltaIx,
    'ALTER TABLE devoluciones_compra ADD KEY ix_devcompra_espera (espera, fecha)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
