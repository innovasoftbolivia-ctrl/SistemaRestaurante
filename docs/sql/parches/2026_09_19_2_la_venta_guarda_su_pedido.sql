-- =============================================================================
--  SISTEMA DEL RESTAURANTE
--  Parche - La venta guarda su pedido, y los estados que no se contradicen
--  (2026-09-19, 2 de 4)
--
--  1. `pedidos.venta_id` guardaba 1 a 1 algo que es 1 a N: un pedido puede
--     tener varias ventas —la que se anuló y la que lo volvió a cobrar—, y al
--     anular se ponía en NULL, así que la venta anulada perdía de qué pedido
--     era. Ahora la clave vive en la venta:
--       - `ventas.pedido_id` (FK a `pedidos`, NULL en las ventas de antes de
--         los pedidos);
--       - `ventas.pedido_cobrado_uk` = IF(estado = 'COMPLETADA', pedido_id,
--         NULL), con índice único `uq_venta_pedido_cobrado`: una sola venta
--         vigente por pedido, lo mismo que garantizaba `uq_pedido_venta`;
--       - fuera `pedidos.venta_id`, con `uq_pedido_venta`, `fk_pedidos_venta`
--         y `ck_pedidos_venta`.
--     «Pedido CERRADO ⇔ tiene una venta COMPLETADA» ya no cabe en un CHECK
--     (son dos tablas): lo garantiza `App\Services\Pedidos`.
--  2. Fuera `pedidos.cliente_id` (y `fk_pedidos_cliente`): repetía
--     `ventas.cliente_id` sin nada que los igualara. El cliente es de la venta;
--     al pedido le basta `nombre_cliente` para llamarlo.
--  3. CHECK de coherencia de estados que la aplicación ya respeta pero la base
--     no exigía (y con LOGICA_EN_PHP no hay triggers que lo cubran):
--       ventas         ck_ventas_anulacion, ck_ventas_precio_final
--       sesiones_caja  ck_sesion_cierre, ck_sesion_cerrada, ck_sesion_fondo
--       comprobantes   ck_comprobante_anulado, ck_comprobante_sustituido
--       cobros_qr      ck_cobros_qr_venta, ck_cobros_qr_manual
--       pedido_detalle ck_pedidodet_entera (porciones enteras)
--
--  RELLENO DE DATOS. `ventas.pedido_id` se llena:
--    a) desde `pedidos.venta_id`, para cada venta que cobró un pedido;
--    b) para las ventas ANULADAS que ya habían perdido su pedido, desde la
--       bitácora: cada reapertura (`auditoria.accion = 'PEDIDO_REABIERTO'`,
--       entidad `pedidos`) anotó `venta_anulada` en su detalle.
--  Las ventas de antes de los pedidos quedan con NULL.
--
--  ANTES DE TOCAR NADA, comprueba que ningún dato viole los CHECK nuevos. Si
--  alguno lo hace, muestra cuáles y ABORTA sin cambiar nada: hay que
--  corregirlos a mano (con criterio: son datos del negocio) y volver a aplicar.
--
--  No borra datos del negocio: los dos campos que salen de `pedidos` quedan
--  representados en `ventas`. Idempotente: cada paso comprueba si queda algo
--  por hacer. Se aplica después de 2026_09_19_1_logica_igual_en_las_dos_vias.sql.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

-- ------------------------------------------------ la guarda: nada se toca si...
DROP PROCEDURE IF EXISTS tmp_verificar_estados;

DELIMITER $$

CREATE PROCEDURE tmp_verificar_estados ()
BEGIN
    DROP TEMPORARY TABLE IF EXISTS tmp_violaciones;
    CREATE TEMPORARY TABLE tmp_violaciones (
        regla   VARCHAR(40)  NOT NULL,
        filas   INT UNSIGNED NOT NULL,
        ejemplo VARCHAR(255) NULL
    );

    INSERT INTO tmp_violaciones
    SELECT 'ck_ventas_anulacion', COUNT(*), GROUP_CONCAT(id ORDER BY id SEPARATOR ',')
      FROM ventas
     WHERE NOT ((estado = 'ANULADA') = (anulada_en IS NOT NULL)
            AND (anulada_en IS NULL) = (anulada_por IS NULL)
            AND (anulada_en IS NULL) = (motivo_anulacion IS NULL))
    UNION ALL
    SELECT 'ck_ventas_precio_final', COUNT(*), GROUP_CONCAT(id ORDER BY id SEPARATOR ',')
      FROM ventas WHERE NOT (impuesto_incluido = 1 OR descuento_precio_final IS NULL)
    UNION ALL
    SELECT 'ck_sesion_cierre', COUNT(*), GROUP_CONCAT(id ORDER BY id SEPARATOR ',')
      FROM sesiones_caja WHERE NOT ((estado = 'ABIERTA') = (fecha_cierre IS NULL))
    UNION ALL
    SELECT 'ck_sesion_cerrada', COUNT(*), GROUP_CONCAT(id ORDER BY id SEPARATOR ',')
      FROM sesiones_caja
     WHERE NOT (estado <> 'CERRADA' OR (usuario_cierre_id IS NOT NULL
                AND monto_esperado IS NOT NULL AND monto_declarado IS NOT NULL))
    UNION ALL
    SELECT 'ck_sesion_fondo', COUNT(*), GROUP_CONCAT(id ORDER BY id SEPARATOR ',')
      FROM sesiones_caja
     WHERE NOT (fondo_dejado IS NULL OR (fondo_dejado >= 0 AND fondo_dejado <= monto_declarado))
    UNION ALL
    SELECT 'ck_comprobante_anulado', COUNT(*), GROUP_CONCAT(id ORDER BY id SEPARATOR ',')
      FROM comprobantes WHERE NOT ((estado = 'ANULADO') = (anulado_en IS NOT NULL))
    UNION ALL
    SELECT 'ck_comprobante_sustituido', COUNT(*), GROUP_CONCAT(id ORDER BY id SEPARATOR ',')
      FROM comprobantes WHERE NOT ((estado = 'SUSTITUIDO') = (sustituido_en IS NOT NULL))
    UNION ALL
    SELECT 'ck_cobros_qr_venta', COUNT(*), GROUP_CONCAT(id ORDER BY id SEPARATOR ',')
      FROM cobros_qr WHERE NOT (venta_id IS NULL OR estado = 'PAGADO')
    UNION ALL
    SELECT 'ck_cobros_qr_manual', COUNT(*), GROUP_CONCAT(id ORDER BY id SEPARATOR ',')
      FROM cobros_qr WHERE NOT (confirmado_por <> 'MANUAL' OR confirmado_por_id IS NOT NULL)
    UNION ALL
    SELECT 'ck_pedidodet_entera', COUNT(*), GROUP_CONCAT(id ORDER BY id SEPARATOR ',')
      FROM pedido_detalle WHERE cantidad <> FLOOR(cantidad);

    DELETE FROM tmp_violaciones WHERE filas = 0;

    IF EXISTS (SELECT 1 FROM tmp_violaciones) THEN
        SELECT regla AS 'Regla que no se cumple', filas AS 'Filas',
               LEFT(ejemplo, 120) AS 'Ids (tabla de la regla)'
          FROM tmp_violaciones ORDER BY regla;
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Parche NO aplicado: hay datos que violan los CHECK nuevos (ver la lista). No se cambió nada.';
    END IF;

    DROP TEMPORARY TABLE IF EXISTS tmp_violaciones;
END$$

DELIMITER ;

CALL tmp_verificar_estados();
DROP PROCEDURE IF EXISTS tmp_verificar_estados;

-- ------------------------------------------------------- ventas.pedido_id
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas' AND COLUMN_NAME = 'pedido_id');
SET @sql := IF(@hay, 'SELECT ''ventas ya tiene pedido_id'' AS aviso',
    'ALTER TABLE ventas ADD COLUMN pedido_id BIGINT UNSIGNED NULL AFTER sesion_caja_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- a) Desde `pedidos.venta_id`, mientras exista.
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'venta_id');
SET @sql := IF(@hay,
    'UPDATE ventas v JOIN pedidos p ON p.venta_id = v.id SET v.pedido_id = p.id WHERE v.pedido_id IS NULL',
    'SELECT ''pedidos ya no tiene venta_id: nada que copiar'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- b) Las anuladas que ya lo habían perdido, desde la bitácora de reaperturas.
--    Solo ventas ANULADAS y pedidos que existen: una fila de bitácora rara no
--    puede dejar dos ventas vigentes para un pedido.
UPDATE ventas v
  JOIN auditoria a
    ON a.accion = 'PEDIDO_REABIERTO'
   AND a.entidad = 'pedidos'
   AND CAST(JSON_UNQUOTE(JSON_EXTRACT(a.detalle, '$.venta_anulada')) AS UNSIGNED) = v.id
  JOIN pedidos p ON p.id = a.entidad_id
   SET v.pedido_id = p.id
 WHERE v.pedido_id IS NULL
   AND v.estado = 'ANULADA';

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas' AND INDEX_NAME = 'ix_ventas_pedido');
SET @sql := IF(@hay, 'SELECT ''ventas ya tiene ix_ventas_pedido'' AS aviso',
    'ALTER TABLE ventas ADD KEY ix_ventas_pedido (pedido_id)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas'
                AND CONSTRAINT_NAME = 'fk_ventas_pedido' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql := IF(@hay, 'SELECT ''ventas ya tiene fk_ventas_pedido'' AS aviso',
    'ALTER TABLE ventas ADD CONSTRAINT fk_ventas_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos (id)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Una sola venta vigente por pedido.
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas' AND COLUMN_NAME = 'pedido_cobrado_uk');
SET @sql := IF(@hay, 'SELECT ''ventas ya tiene pedido_cobrado_uk'' AS aviso',
    'ALTER TABLE ventas ADD COLUMN pedido_cobrado_uk BIGINT UNSIGNED GENERATED ALWAYS AS (IF(estado = ''COMPLETADA'', pedido_id, NULL)) VIRTUAL, ADD UNIQUE KEY uq_venta_pedido_cobrado (pedido_cobrado_uk)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------- fuera pedidos.venta_id y cliente_id
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos'
                AND CONSTRAINT_NAME = 'fk_pedidos_venta' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql := IF(@hay, 'ALTER TABLE pedidos DROP FOREIGN KEY fk_pedidos_venta', 'SELECT ''pedidos ya no tiene fk_pedidos_venta'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos'
                AND CONSTRAINT_NAME = 'ck_pedidos_venta' AND CONSTRAINT_TYPE = 'CHECK');
SET @sql := IF(@hay, 'ALTER TABLE pedidos DROP CHECK ck_pedidos_venta', 'SELECT ''pedidos ya no tiene ck_pedidos_venta'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND INDEX_NAME = 'uq_pedido_venta');
SET @sql := IF(@hay, 'ALTER TABLE pedidos DROP INDEX uq_pedido_venta', 'SELECT ''pedidos ya no tiene uq_pedido_venta'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'venta_id');
SET @sql := IF(@hay, 'ALTER TABLE pedidos DROP COLUMN venta_id', 'SELECT ''pedidos ya no tiene venta_id'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos'
                AND CONSTRAINT_NAME = 'fk_pedidos_cliente' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql := IF(@hay, 'ALTER TABLE pedidos DROP FOREIGN KEY fk_pedidos_cliente', 'SELECT ''pedidos ya no tiene fk_pedidos_cliente'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'cliente_id');
SET @sql := IF(@hay, 'ALTER TABLE pedidos DROP COLUMN cliente_id', 'SELECT ''pedidos ya no tiene cliente_id'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------ los CHECK nuevos
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

CALL tmp_agregar_check('ventas', 'ck_ventas_anulacion',
    '(estado = ''ANULADA'') = (anulada_en IS NOT NULL) AND (anulada_en IS NULL) = (anulada_por IS NULL) AND (anulada_en IS NULL) = (motivo_anulacion IS NULL)');
CALL tmp_agregar_check('ventas', 'ck_ventas_precio_final',
    'impuesto_incluido = 1 OR descuento_precio_final IS NULL');
CALL tmp_agregar_check('sesiones_caja', 'ck_sesion_cierre',
    '(estado = ''ABIERTA'') = (fecha_cierre IS NULL)');
CALL tmp_agregar_check('sesiones_caja', 'ck_sesion_cerrada',
    'estado <> ''CERRADA'' OR (usuario_cierre_id IS NOT NULL AND monto_esperado IS NOT NULL AND monto_declarado IS NOT NULL)');
CALL tmp_agregar_check('sesiones_caja', 'ck_sesion_fondo',
    'fondo_dejado IS NULL OR (fondo_dejado >= 0 AND fondo_dejado <= monto_declarado)');
CALL tmp_agregar_check('comprobantes', 'ck_comprobante_anulado',
    '(estado = ''ANULADO'') = (anulado_en IS NOT NULL)');
CALL tmp_agregar_check('comprobantes', 'ck_comprobante_sustituido',
    '(estado = ''SUSTITUIDO'') = (sustituido_en IS NOT NULL)');
CALL tmp_agregar_check('cobros_qr', 'ck_cobros_qr_venta',
    'venta_id IS NULL OR estado = ''PAGADO''');
CALL tmp_agregar_check('cobros_qr', 'ck_cobros_qr_manual',
    'confirmado_por <> ''MANUAL'' OR confirmado_por_id IS NOT NULL');
CALL tmp_agregar_check('pedido_detalle', 'ck_pedidodet_entera',
    'cantidad = FLOOR(cantidad)');

DROP PROCEDURE IF EXISTS tmp_agregar_check;
