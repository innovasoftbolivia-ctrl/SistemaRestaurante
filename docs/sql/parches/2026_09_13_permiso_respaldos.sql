-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Permiso para hacer y descargar respaldos (2026-09-13)
--
--  Qué agrega
--  ----------
--      respaldos.gestionar   Hacer y descargar respaldos de la base
--
--  y se lo da al rol Administrador, igual que una instalación nueva.
--
--  Por qué un permiso propio
--  -------------------------
--  Descargar un respaldo es llevarse la base entera: clientes, ventas y los
--  hashes de las contraseñas. No es parte de administrar el local ni de
--  gestionar cuentas, y no se le da a nadie por arrastre.
--
--  Se puede correr dos veces: el permiso y la asignación no se duplican.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

INSERT IGNORE INTO permisos (codigo, modulo, descripcion)
VALUES ('respaldos.gestionar', 'Sistema', 'Hacer y descargar respaldos de la base');

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.codigo = 'respaldos.gestionar'
 WHERE r.nombre = 'Administrador';

SELECT r.nombre AS rol, p.codigo
  FROM rol_permiso rp
  JOIN roles r    ON r.id = rp.rol_id
  JOIN permisos p ON p.id = rp.permiso_id
 WHERE p.codigo = 'respaldos.gestionar';
