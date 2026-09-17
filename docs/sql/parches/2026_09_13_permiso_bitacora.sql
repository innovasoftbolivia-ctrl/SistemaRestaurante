-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Permiso para leer la bitácora (2026-09-13)
--
--  Qué agrega
--  ----------
--      bitacora.ver   Consultar la bitácora de operaciones
--
--  y se lo da al rol Administrador, igual que una instalación nueva.
--
--  Por qué un permiso propio
--  -------------------------
--  La bitácora dice quién anuló una venta, quién cambió un precio, desde qué
--  equipo entró cada uno. Es información sensible, y no tiene por qué ir de la
--  mano de administrar el local (`configuracion.editar`) ni de gestionar
--  cuentas (`usuarios.gestionar`): así se le puede dar a un encargado de
--  control sin darle nada más. Se reparte desde Roles y permisos.
--
--  Se puede correr dos veces: el permiso y la asignación no se duplican.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

INSERT IGNORE INTO permisos (codigo, modulo, descripcion)
VALUES ('bitacora.ver', 'Sistema', 'Consultar la bitácora de operaciones');

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.codigo = 'bitacora.ver'
 WHERE r.nombre = 'Administrador';

SELECT r.nombre AS rol, p.codigo
  FROM rol_permiso rp
  JOIN roles r    ON r.id = rp.rol_id
  JOIN permisos p ON p.id = rp.permiso_id
 WHERE p.codigo = 'bitacora.ver';
