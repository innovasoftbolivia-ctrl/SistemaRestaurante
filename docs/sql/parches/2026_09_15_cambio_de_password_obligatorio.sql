-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Cambio de contraseña obligatorio (2026-09-15)
--
--  Una instalación nueva arrancaba con admin/admin123, publicada en el README,
--  y nada obligaba a cambiarla. Ahora la instalación de producción pone una
--  contraseña aleatoria y marca `debe_cambiar_password`, igual que cuando un
--  administrador le restablece la contraseña a otra persona: al entrar, lo
--  primero es poner una propia.
--
--  Idempotente: la columna se agrega solo si falta. Las cuentas existentes
--  quedan en 0: no se le pide nada a quien ya trabaja con la suya.
-- =============================================================================

USE ventas_db;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'debe_cambiar_password');
SET @sql := IF(@falta,
    'ALTER TABLE usuarios ADD COLUMN debe_cambiar_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_actualizado_en',
    'SELECT ''usuarios ya tiene debe_cambiar_password'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'debe_cambiar_password';
