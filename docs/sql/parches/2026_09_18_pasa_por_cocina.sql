-- =============================================================================
--  SISTEMA DEL RESTAURANTE
--  Parche - Lo que no pasa por la cocina (2026-09-18)
--
--  Desde que todo lo que se vende en el mostrador llega a la cocina, su
--  pantalla y la comanda se llenaban de gaseosas y botellas que nadie cocina.
--
--  - `categorias.pasa_por_cocina` (1 por omisión): si lo de esa categoría se
--    prepara en la cocina. Se edita en Menú → Categorías. Al crearla, este
--    parche la deja en 0 para «Bebidas», si existe.
--  - `pedido_detalle.pasa_por_cocina` (1 por omisión): la línea lo copia de
--    su categoría al pedirse, como copia el precio. Cambiar la categoría
--    después no mueve lo ya pedido. Lo que no pasa por la cocina se cobra
--    igual y sigue en el pedido, pero no va a la pantalla de la cocina ni a
--    la comanda; queda PENDIENTE mientras el pedido está abierto (así se
--    puede cancelar y no cuenta como empezado) y ENTREGADO al cobrarse: se
--    entrega con el ticket.
--
--  Las líneas que ya existen se alinean con su categoría UNA sola vez, al
--  crear la columna: las bebidas de un pedido abierto dejan de aparecer en la
--  cocina. No se les cambia el estado: el historial queda como estaba.
--
--  No borra datos. Idempotente: cada paso comprueba si queda algo por hacer, y
--  una segunda pasada no vuelve a pisar lo que el negocio haya cambiado en
--  Categorías.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

-- ------------------------------------------------ categorias.pasa_por_cocina
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categorias'
                  AND COLUMN_NAME = 'pasa_por_cocina');
SET @sql := IF(@falta,
    'ALTER TABLE categorias ADD COLUMN pasa_por_cocina TINYINT(1) NOT NULL DEFAULT 1 AFTER activo',
    'SELECT ''categorias ya tiene pasa_por_cocina'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Las bebidas, solo la primera vez: después lo decide el negocio.
SET @sql := IF(@falta,
    'UPDATE categorias SET pasa_por_cocina = 0 WHERE nombre = ''Bebidas''',
    'SELECT ''las categorías ya tenían su marca'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -------------------------------------------- pedido_detalle.pasa_por_cocina
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedido_detalle'
                  AND COLUMN_NAME = 'pasa_por_cocina');
SET @sql := IF(@falta,
    'ALTER TABLE pedido_detalle ADD COLUMN pasa_por_cocina TINYINT(1) NOT NULL DEFAULT 1 AFTER nota',
    'SELECT ''pedido_detalle ya tiene pasa_por_cocina'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Lo ya pedido sigue a su categoría, también solo la primera vez. Se asigna
-- `actualizado_en` a sí mismo para que el ON UPDATE no le ponga la hora del
-- parche a cada línea: esa hora es la del último cambio en la cocina.
SET @sql := IF(@falta,
    'UPDATE pedido_detalle d JOIN productos p ON p.id = d.producto_id JOIN categorias c ON c.id = p.categoria_id SET d.pasa_por_cocina = 0, d.actualizado_en = d.actualizado_en WHERE c.pasa_por_cocina = 0',
    'SELECT ''las líneas ya tenían su marca'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
