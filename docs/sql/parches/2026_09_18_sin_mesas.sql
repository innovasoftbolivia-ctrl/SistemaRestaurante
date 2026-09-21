-- =============================================================================
--  SISTEMA DEL RESTAURANTE
--  Parche - Sin mesas ni cuentas abiertas: todo entra por el mostrador (2026-09-18)
--
--  El dueño: «el negocio de momento no maneja número de mesas, no maneja
--  mozos». El cliente pide y paga en la caja, recibe su ticket con el número
--  del pedido, se sienta donde quiera y alguien le lleva el plato cantando el
--  número. Se retiran las mesas y la cuenta que queda abierta para cobrarse
--  después:
--
--  - `pedidos.tipo` pasa de ENUM('MESA','LLEVAR') a ENUM('LOCAL','LLEVAR').
--    LOCAL es «comer aquí». Los pedidos de mesa que ya existen pasan a LOCAL
--    ANTES de estrechar el ENUM: siguen siendo pedidos que se comieron en el
--    local. Conservan su número, su jornada, sus líneas y su venta.
--  - Fuera de `pedidos`: `mesa_id` con su clave foránea y su índice, la
--    columna generada `mesa_abierta_uk` con `uq_pedido_mesa_abierta` (una
--    cuenta abierta por mesa) y el CHECK `ck_pedidos_mesa` que ataba el tipo a
--    la mesa.
--  - Se borra la tabla `mesas`. ES LO ÚNICO QUE SE PIERDE: el número, nombre,
--    zona y capacidad de cada mesa, y a qué mesa fue cada pedido viejo. Si hace
--    falta esa historia, haz un respaldo antes (Sistema → Respaldos o
--    scripts/backup-db.sh). La bitácora conserva sus registros MESA_*.
--  - El permiso `pedidos.registrar` se queda —lo tienen Cajero y
--    Administrador— con su descripción al día: ahora sirve para cancelar un
--    pedido por cobrar o uno de sus platos.
--
--  Lo que se queda del flujo de cuenta abierta, a propósito: un pedido puede
--  seguir ABIERTO —«por cobrar»— cuando se anula su venta
--  (`Pedidos::reabrirTrasAnular`). El cliente ya tiene su ticket y la cocina
--  ya lo prepara: se vuelve a cobrar con el mismo número, o se cancela. Las
--  cuentas de mesa que estén abiertas al aplicar este parche quedan así: como
--  pedidos para comer aquí por cobrar, en el punto de venta.
--
--  Idempotente: cada paso comprueba si queda algo por hacer.
--  Se aplica después de 2026_09_17_mesas_y_pedidos.sql.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

-- ------------------------------------------ el CHECK que ataba tipo y mesa
-- Primero, o no se podría pasar a LOCAL un pedido que tiene mesa.
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos'
                AND CONSTRAINT_NAME = 'ck_pedidos_mesa' AND CONSTRAINT_TYPE = 'CHECK');
SET @sql := IF(@hay, 'ALTER TABLE pedidos DROP CHECK ck_pedidos_mesa', 'SELECT ''pedidos ya no tiene ck_pedidos_mesa'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------- MESA pasa a LOCAL («comer aquí»)
-- El ENUM se ensancha para que quepan los dos, se migra, y más abajo se
-- estrecha: estrecharlo con filas en MESA las dejaría vacías.
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos'
                AND COLUMN_NAME = 'tipo' AND COLUMN_TYPE NOT LIKE '%''LOCAL''%');
SET @sql := IF(@hay,
    'ALTER TABLE pedidos MODIFY COLUMN tipo ENUM(''MESA'',''LOCAL'',''LLEVAR'') NOT NULL DEFAULT ''LOCAL''',
    'SELECT ''pedidos.tipo ya admite LOCAL'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos'
                AND COLUMN_NAME = 'tipo' AND COLUMN_TYPE LIKE '%''MESA''%');
SET @sql := IF(@hay, 'UPDATE pedidos SET tipo = ''LOCAL'' WHERE tipo = ''MESA''', 'SELECT ''no quedan pedidos de mesa'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------ una cuenta abierta por mesa: ya no aplica
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos'
                AND INDEX_NAME = 'uq_pedido_mesa_abierta');
SET @sql := IF(@hay, 'ALTER TABLE pedidos DROP INDEX uq_pedido_mesa_abierta', 'SELECT ''pedidos ya no tiene uq_pedido_mesa_abierta'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos'
                AND COLUMN_NAME = 'mesa_abierta_uk');
SET @sql := IF(@hay, 'ALTER TABLE pedidos DROP COLUMN mesa_abierta_uk', 'SELECT ''pedidos ya no tiene mesa_abierta_uk'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------- pedidos.mesa_id
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos'
                AND CONSTRAINT_NAME = 'fk_pedidos_mesa' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql := IF(@hay, 'ALTER TABLE pedidos DROP FOREIGN KEY fk_pedidos_mesa', 'SELECT ''pedidos ya no tiene fk_pedidos_mesa'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos'
                AND INDEX_NAME = 'ix_pedidos_mesa');
SET @sql := IF(@hay, 'ALTER TABLE pedidos DROP INDEX ix_pedidos_mesa', 'SELECT ''pedidos ya no tiene ix_pedidos_mesa'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos'
                AND COLUMN_NAME = 'mesa_id');
SET @sql := IF(@hay, 'ALTER TABLE pedidos DROP COLUMN mesa_id', 'SELECT ''pedidos ya no tiene mesa_id'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -------------------------------------------------- el ENUM, ya sin MESA
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos'
                AND COLUMN_NAME = 'tipo' AND COLUMN_TYPE LIKE '%''MESA''%');
SET @sql := IF(@hay,
    'ALTER TABLE pedidos MODIFY COLUMN tipo ENUM(''LOCAL'',''LLEVAR'') NOT NULL DEFAULT ''LOCAL''',
    'SELECT ''pedidos.tipo ya es LOCAL o LLEVAR'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------- mesas
DROP TABLE IF EXISTS mesas;

-- ------------------------------------------------- el permiso de los pedidos
UPDATE permisos
   SET descripcion = 'Cancelar un pedido por cobrar o uno de sus platos'
 WHERE codigo = 'pedidos.registrar';
