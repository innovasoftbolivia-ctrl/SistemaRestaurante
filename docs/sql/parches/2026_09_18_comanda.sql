-- =============================================================================
--  SISTEMA DEL RESTAURANTE
--  Parche - La comanda impresa para la cocina (2026-09-18)
--
--  Hasta ahora lo único que se imprimía era el comprobante, al cobrar. Si la
--  cocina no tiene pantalla o se cae la red, no quedaba nada en papel. La
--  comanda es un papel de 80 mm para la cocina: número del pedido, comer aquí
--  o para llevar, la hora y cada plato con su nota, sin precios.
--
--  Para saber qué ya salió en papel, cada línea del pedido guarda:
--
--  - `pedido_detalle.comandado_en`: cuándo salió en una comanda. La comanda
--    trae solo lo que todavía no salió; una reimpresión no marca nada.
--  - `pedido_detalle.cancelacion_comandada_en`: cuándo salió el aviso de que
--    se canceló. Una línea que ya estaba en la cocina en papel y se cancela
--    (un plato, o el pedido entero) sale en la siguiente comanda como
--    «CANCELADO: 1 × …», una sola vez: si no, el cocinero la prepara igual.
--
--  Las líneas que ya existen quedan con las dos en NULL: nunca salieron en
--  papel, porque no había comanda.
--
--  No borra datos. Idempotente: cada columna se agrega solo si falta.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

-- ------------------------------------------------------------ comandado_en
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedido_detalle'
                  AND COLUMN_NAME = 'comandado_en');
SET @sql := IF(@falta,
    'ALTER TABLE pedido_detalle ADD COLUMN comandado_en TIMESTAMP NULL AFTER actualizado_por',
    'SELECT ''pedido_detalle ya tiene comandado_en'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------- cancelacion_comandada_en
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedido_detalle'
                  AND COLUMN_NAME = 'cancelacion_comandada_en');
SET @sql := IF(@falta,
    'ALTER TABLE pedido_detalle ADD COLUMN cancelacion_comandada_en TIMESTAMP NULL AFTER comandado_en',
    'SELECT ''pedido_detalle ya tiene cancelacion_comandada_en'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
