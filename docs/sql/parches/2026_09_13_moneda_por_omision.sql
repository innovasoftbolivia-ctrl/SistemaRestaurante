-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - La moneda por omisión del comprobante es el boliviano (2026-09-13)
--
--  Qué cambia
--  ----------
--  El valor por omisión de `comprobantes.moneda` pasa de 'PEN' (el sol
--  peruano, herencia del esquema original) a 'BOB'.
--
--  Por qué, si nunca se usa
--  ------------------------
--  Hoy la moneda de cada comprobante sale de `configuracion.moneda_codigo`,
--  que vale 'BOB', y por eso los tickets dicen «Bs». El 'PEN' solo aparecería
--  si esa clave faltara: y entonces el ticket saldría en soles sin que nada
--  avise. Un valor por omisión tiene que ser el que tendría sentido si se usara.
--
--  Lo que este parche NO toca: el mismo 'PEN' de respaldo dentro del
--  procedimiento que registra la venta. Cambiarlo en una base instalada
--  obliga a recrear el procedimiento entero, y el caso —que falte la clave de
--  configuración— no se da. Las instalaciones nuevas ya lo traen en 'BOB'.
-- =============================================================================

USE ventas_db;

ALTER TABLE comprobantes ALTER COLUMN moneda SET DEFAULT 'BOB';

SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comprobantes' AND COLUMN_NAME = 'moneda';
