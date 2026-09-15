-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Tope de egresos del cajero (2026-09-14)
--
--  El cajero podía registrar un egreso de cualquier monto y tapar así un
--  faltante antes del arqueo. Ahora ningún egreso supera el efectivo esperado,
--  y el cajero tiene un tope: por encima lo registra un administrador en su
--  turno. Se cambia desde Sistema > Configuración.
--
--  Bs 200 por omisión: los egresos reales de la demo rondan entre 190 y 250.
--  Idempotente: INSERT IGNORE no pisa un valor ya elegido.
-- =============================================================================

USE ventas_db;

INSERT IGNORE INTO configuracion (clave, valor, descripcion) VALUES
    ('egreso_max_cajero', '200.00', 'Egreso máximo (Bs) que el cajero registra sin autorización');

SELECT clave, valor FROM configuracion WHERE clave = 'egreso_max_cajero';
