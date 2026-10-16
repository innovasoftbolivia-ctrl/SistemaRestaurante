USE ventas_db;
SET NAMES utf8mb4;

-- =============================================================================
--  La jornada, guardada en la venta (2026-10-16)
--
--  Hasta ahora la jornada de una venta se calculaba al vuelo, con la hora de
--  corte que estuviera configurada en ese momento. Eso ataba la historia a la
--  configuración de hoy: al cambiar `hora_corte_jornada`, las ventas de la
--  madrugada se mudaban de día y los reportes de meses pasados cambiaban sus
--  totales, dejando de cuadrar con los cierres de caja ya firmados y con la
--  jornada que sí estaba guardada en cada pedido.
--
--  Ahora `ventas.jornada` se guarda al cobrar y no se vuelve a mover.
--
--  1. La columna y su índice (los reportes agrupan y filtran por ahí).
--  2. El relleno de lo que ya está: la fórmula de siempre, con la hora de
--     corte actual, que es la que se venía usando para mostrarlo.
--  3. `trg_ventas_before_insert` la calcula en cada venta nueva: lo que venga
--     en el INSERT no manda.
--  4. `v_ventas_por_dia` lee la columna en vez de recalcularla.
--  5. De paso, el índice por `fecha_cierre` de los turnos: el aviso de
--     «arqueos con diferencia» recorría la tabla entera.
--
--  Idempotente: cada paso mira antes si ya está hecho. El mismo cambio está en
--  01_schema_mysql.sql. Para un hosting sin procedimientos, triggers ni vistas
--  (InfinityFree) hay una variante en su paquete: ahí la jornada la escribe
--  PHP (`Ventas::registrar`).
-- =============================================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS _parche_jornada$$
CREATE PROCEDURE _parche_jornada()
BEGIN
    -- 1. La columna. Nace aceptando NULL para poder rellenarla; al final queda
    --    NOT NULL, como `pedidos.jornada`.
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas' AND COLUMN_NAME = 'jornada') THEN
        ALTER TABLE ventas ADD COLUMN jornada DATE NULL AFTER fecha;
    END IF;

    -- 2. Lo que ya está vendido: la misma cuenta que venían haciendo los
    --    reportes, congelada. Solo las filas sin jornada, para que volver a
    --    aplicar el parche no toque nada.
    UPDATE ventas
       SET jornada = DATE(fecha - INTERVAL IFNULL((SELECT CAST(NULLIF(c.valor, '') AS UNSIGNED)
                                                     FROM configuracion c
                                                    WHERE c.clave = 'hora_corte_jornada'), 5) HOUR)
     WHERE jornada IS NULL;

    -- Ya sin huecos, la columna queda obligatoria.
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas'
                  AND COLUMN_NAME = 'jornada' AND IS_NULLABLE = 'YES') THEN
        ALTER TABLE ventas MODIFY COLUMN jornada DATE NOT NULL;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas' AND INDEX_NAME = 'ix_ventas_jornada') THEN
        ALTER TABLE ventas ADD KEY ix_ventas_jornada (jornada);
    END IF;

    -- 5. El cierre de los turnos, indexado.
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sesiones_caja' AND INDEX_NAME = 'ix_sesiones_cierre') THEN
        ALTER TABLE sesiones_caja ADD KEY ix_sesiones_cierre (fecha_cierre);
    END IF;
END$$

DELIMITER ;

CALL _parche_jornada();
DROP PROCEDURE _parche_jornada;

-- 3. Cada venta nueva nace con su jornada.
DROP TRIGGER IF EXISTS trg_ventas_before_insert;

DELIMITER $$

CREATE TRIGGER trg_ventas_before_insert
BEFORE INSERT ON ventas
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM sesiones_caja
                    WHERE id = NEW.sesion_caja_id AND estado = 'ABIERTA') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La venta debe registrarse en un turno de caja abierto';
    END IF;

    -- La jornada sale de la fecha y de la hora de corte de AHORA, y se guarda
    -- con la venta: lo que venga en el INSERT no manda, para que nadie pueda
    -- mover una venta de día escribiendo otra fecha.
    SET NEW.jornada = DATE(NEW.fecha - INTERVAL IFNULL((SELECT CAST(NULLIF(c.valor, '') AS UNSIGNED)
                                                          FROM configuracion c
                                                         WHERE c.clave = 'hora_corte_jornada'), 5) HOUR);
END$$

DELIMITER ;

-- 4. La vista lee la columna.
CREATE OR REPLACE VIEW v_ventas_por_dia AS
SELECT v.jornada             AS dia,
       COUNT(*)              AS cantidad_ventas,
       SUM(v.total)          AS monto_total,
       ROUND(AVG(v.total), 2) AS ticket_promedio
  FROM ventas v
 WHERE v.estado <> 'ANULADA'
 GROUP BY v.jornada;

INSERT IGNORE INTO parches_aplicados (archivo) VALUES ('2026_10_16_jornada_en_ventas.sql');
