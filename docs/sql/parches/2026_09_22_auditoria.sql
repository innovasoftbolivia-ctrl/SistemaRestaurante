USE ventas_db;
SET NAMES utf8mb4;

-- =============================================================================
--  Correcciones de la auditoría de la base (2026-09-22)
--
--  1. Los costos del inventario a cuatro decimales: el costo por unidad sale
--     de dividir la caja (25,00 / 12 = 2,0833) y a dos la compra ya no sumaba
--     la factura, y el costo que se congela en cada venta salía corrido.
--  2. `ck_config_moneda` sin REGEXP_LIKE, que MariaDB no tiene: la misma regla
--     con REGEXP y cotejamiento binario (distingue mayúsculas) vale en los dos.
--  3. `ck_config_banderas` con la bandera `cajero_cierra_su_caja`: el esquema
--     la sumó sin parche, y las bases con parches aceptaban cualquier valor.
--     Con su fila, si faltaba.
--  4. El arqueo por billetes no se borra en cascada con el turno: es un
--     registro de dinero firmado, como el resto (RESTRICT).
--  5. La línea devuelta al proveedor apunta a la línea de compra Y a su mismo
--     producto: un INSERT a mano con otro producto sacaba del stock el
--     producto equivocado.
--  6. El kardex no se edita ni se borra (triggers, con la lógica en la base).
--
--  Idempotente: cada paso mira antes si ya está hecho (un procedimiento de
--  uso único, que se borra al final). El mismo cambio está en
--  01_schema_mysql.sql. Para un hosting sin procedimientos ni triggers hay una
--  variante en el paquete de InfinityFree.
-- =============================================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS _parche_auditoria$$
CREATE PROCEDURE _parche_auditoria()
BEGIN
    -- 1. Costos a cuatro decimales (volver a aplicarlo no cambia nada).
    ALTER TABLE productos                 MODIFY COLUMN costo          DECIMAL(12,4) NULL;
    ALTER TABLE venta_detalle             MODIFY COLUMN costo_unitario DECIMAL(12,4) NULL;
    ALTER TABLE compra_detalle            MODIFY COLUMN costo_unitario DECIMAL(12,4) NOT NULL;
    ALTER TABLE devolucion_compra_detalle MODIFY COLUMN costo_unitario DECIMAL(12,4) NOT NULL;
    ALTER TABLE movimientos_inventario    MODIFY COLUMN costo_unitario DECIMAL(12,4) NULL;
    ALTER TABLE toma_inventario_detalle   MODIFY COLUMN costo_unitario DECIMAL(12,4) NULL;

    -- 2. La moneda, sin REGEXP_LIKE.
    IF EXISTS (SELECT 1 FROM information_schema.CHECK_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'ck_config_moneda') THEN
        ALTER TABLE configuracion DROP CHECK ck_config_moneda;
    END IF;
    ALTER TABLE configuracion ADD CONSTRAINT ck_config_moneda
        CHECK (clave <> 'moneda_codigo' OR valor COLLATE utf8mb4_bin REGEXP '^[A-Z]{3}$');

    -- 3. Las banderas, con la del cierre del cajero.
    IF EXISTS (SELECT 1 FROM information_schema.CHECK_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'ck_config_banderas') THEN
        ALTER TABLE configuracion DROP CHECK ck_config_banderas;
    END IF;
    ALTER TABLE configuracion ADD CONSTRAINT ck_config_banderas
        CHECK (clave NOT IN ('precios_incluyen_impuesto', 'exigir_referencia_pago', 'cajero_cierra_su_caja')
               OR valor IN ('0', '1'));

    -- 4. El arqueo, sin cascada.
    IF EXISTS (SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_arqueo_sesion'
                  AND DELETE_RULE = 'CASCADE') THEN
        ALTER TABLE arqueo_caja DROP FOREIGN KEY fk_arqueo_sesion;
        ALTER TABLE arqueo_caja ADD CONSTRAINT fk_arqueo_sesion
            FOREIGN KEY (sesion_caja_id) REFERENCES sesiones_caja (id) ON DELETE RESTRICT;
    END IF;

    -- 5. La línea devuelta, con su mismo producto.
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'compra_detalle'
                      AND INDEX_NAME = 'uq_compradet_id_producto') THEN
        ALTER TABLE compra_detalle ADD UNIQUE KEY uq_compradet_id_producto (id, producto_id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_devcompradet_linea_producto') THEN
        ALTER TABLE devolucion_compra_detalle ADD CONSTRAINT fk_devcompradet_linea_producto
            FOREIGN KEY (compra_detalle_id, producto_id) REFERENCES compra_detalle (id, producto_id);
    END IF;
END$$

DELIMITER ;

CALL _parche_auditoria();
DROP PROCEDURE _parche_auditoria;

INSERT IGNORE INTO configuracion (clave, valor, descripcion) VALUES
    ('cajero_cierra_su_caja', '0', 'El cajero cierra su propia caja, sin ver el esperado (1 = sí; 0 = lo cierra un administrador)');

-- 6. El kardex no se edita ni se borra.
DROP TRIGGER IF EXISTS trg_movimientos_inventario_before_update;
DROP TRIGGER IF EXISTS trg_movimientos_inventario_before_delete;

DELIMITER $$

CREATE TRIGGER trg_movimientos_inventario_before_update
BEFORE UPDATE ON movimientos_inventario
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El kardex no se edita: un error se corrige con un ajuste.';
END$$

CREATE TRIGGER trg_movimientos_inventario_before_delete
BEFORE DELETE ON movimientos_inventario
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El kardex no se borra: un error se corrige con un ajuste.';
END$$

DELIMITER ;
