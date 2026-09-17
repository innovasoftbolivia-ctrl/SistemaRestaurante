-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Plazo para devoluciones y número de operación (2026-09-15)
--
--  1. `dias_max_devolucion`: hasta cuántos días después de la venta se acepta
--     una devolución. Antes no había límite (en los datos de prueba había
--     devoluciones a 76 días). Por omisión 7; se cambia en Configuración.
--  2. `exigir_referencia_pago`: el pago con tarjeta, billetera o transferencia
--     lleva el número de operación del voucher o comprobante, para conciliar
--     con el banco. El QR ya lo trae su cobro.
--
--  Idempotente: no pisa un valor que ya exista.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

INSERT IGNORE INTO configuracion (clave, valor, descripcion) VALUES
    ('dias_max_devolucion', '7',                     'Días máximos tras la venta para aceptar una devolución'),
    ('exigir_referencia_pago', '1',                  'Pedir el número de operación en pagos con tarjeta, billetera o transferencia (1 = sí)');

SELECT clave, valor FROM configuracion WHERE clave IN ('dias_max_devolucion', 'exigir_referencia_pago');
