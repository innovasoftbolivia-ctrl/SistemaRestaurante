-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - El pedido se numera por día (2026-09-17)
--
--  El ticket encabezaba con el `id` del pedido, que es global y creciente: a
--  los pocos meses de servicio decía «PEDIDO #4812», un número que nadie puede
--  cantar en la barra. El dueño quiere el de toda la vida: 1, 2, 3… y vuelta a
--  empezar cada día.
--
--  - `pedidos.numero_dia`: el número que se canta. El `id` se queda como clave
--    y en las URLs; `numero_dia` es lo único que lee una persona.
--  - `pedidos.fecha_dia`: columna generada STORED con el día de la apertura.
--    Es lo que acota el contador al día, y es STORED porque debajo lleva un
--    índice: MySQL no admite columnas VIRTUAL en un UNIQUE.
--  - `uq_pedido_numero_dia (fecha_dia, numero_dia)`: la unicidad la garantiza
--    la base. Mismo truco de columna generada + índice único que
--    `sesiones_caja.caja_abierta_uk` y `pedidos.mesa_abierta_uk`. Dos mozos
--    abriendo mesa en el mismo segundo no pueden sacar el mismo número:
--    `App\Services\Pedidos::abrir()` toma el siguiente con `FOR UPDATE` —como
--    `sp_siguiente_comprobante` con el correlativo— y reintenta si aun así
--    choca, igual que `Ventas::registrar()` ante un deadlock.
--  - Un solo contador para todos los pedidos del día, de mesa o para llevar:
--    el ticket ya dice cuál es cuál, y dos numeraciones paralelas harían que
--    en el mismo turno convivan dos «pedido 7».
--
--  Los pedidos que ya están en la base se numeran por día, en orden de
--  apertura: el más viejo de cada día es el 1. Sin ese relleno la columna no
--  puede ser NOT NULL ni llevar el índice único.
--
--  No borra datos. Idempotente: cada paso comprueba si queda algo por hacer.
--  Se aplica después de 2026_09_17_mesas_y_pedidos.sql.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

-- ------------------------------------------------------------ numero_dia
-- Nace NULL para poder rellenar lo que ya existe; más abajo pasa a NOT NULL.
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos'
                  AND COLUMN_NAME = 'numero_dia');
SET @sql := IF(@falta, 'ALTER TABLE pedidos ADD COLUMN numero_dia SMALLINT UNSIGNED NULL AFTER tipo', 'SELECT ''pedidos ya tiene numero_dia'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------- fecha_dia
-- Tampoco si ya está `jornada`: es esta misma columna, que
-- 2026_09_18_jornada_del_pedido.sql renombró y dejó de generar. Sin esta
-- condición, volver a correr este parche sobre una base ya al día agregaba
-- otra vez una `fecha_dia` generada.
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos'
                  AND COLUMN_NAME IN ('fecha_dia', 'jornada'));
SET @sql := IF(@falta, 'ALTER TABLE pedidos ADD COLUMN fecha_dia DATE GENERATED ALWAYS AS (DATE(fecha_apertura)) STORED AFTER mesa_abierta_uk', 'SELECT ''pedidos ya tiene fecha_dia'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------- los pedidos que ya están
-- Uno por día y en orden de apertura: el más viejo de cada día es el 1. El
-- `id` desempata las aperturas del mismo segundo, que en una base cargada de
-- una tirada son todas. Solo toca las filas sin número, así que una segunda
-- pasada no renumera nada.
UPDATE pedidos p
  JOIN (SELECT id,
               ROW_NUMBER() OVER (PARTITION BY DATE(fecha_apertura)
                                      ORDER BY fecha_apertura, id) AS n
          FROM pedidos) AS orden ON orden.id = p.id
   SET p.numero_dia = orden.n
 WHERE p.numero_dia IS NULL;

-- ----------------------------------------------------- la columna, obligatoria
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos'
                AND COLUMN_NAME = 'numero_dia' AND IS_NULLABLE = 'YES');
SET @sql := IF(@hay, 'ALTER TABLE pedidos MODIFY COLUMN numero_dia SMALLINT UNSIGNED NOT NULL', 'SELECT ''pedidos.numero_dia ya es obligatoria'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------- el índice único
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos'
                  AND INDEX_NAME = 'uq_pedido_numero_dia');
SET @sql := IF(@falta, 'ALTER TABLE pedidos ADD UNIQUE KEY uq_pedido_numero_dia (fecha_dia, numero_dia)', 'SELECT ''pedidos ya tiene uq_pedido_numero_dia'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------- el número empieza en 1
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos'
                  AND CONSTRAINT_NAME = 'ck_pedidos_numero' AND CONSTRAINT_TYPE = 'CHECK');
SET @sql := IF(@falta, 'ALTER TABLE pedidos ADD CONSTRAINT ck_pedidos_numero CHECK (numero_dia > 0)', 'SELECT ''pedidos ya tiene ck_pedidos_numero'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
