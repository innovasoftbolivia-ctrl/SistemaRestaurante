-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - La imagen del QR que entrega el banco (2026-09-14)
--
--  Banco Económico no devuelve el texto a codificar sino la imagen PNG del QR
--  en base64 (unos 33 KB para 850 x 850 px). `cobros_qr.payload` era TEXT, que
--  tope en 65 KB: alcanza hoy, pero no deja margen si el banco agranda la
--  imagen. Pasa a MEDIUMTEXT.
--
--  Idempotente: MODIFY deja la columna igual si ya es MEDIUMTEXT.
-- =============================================================================

USE ventas_db;

ALTER TABLE cobros_qr MODIFY payload MEDIUMTEXT NULL;

SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cobros_qr' AND COLUMN_NAME = 'payload';
