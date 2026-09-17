-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Cada quien ve lo suyo, y eliminar es del administrador (2026-09-16)
--
--  Qué agrega
--  ----------
--      clientes.editar      Editar los datos de un cliente ya registrado
--      registros.eliminar   Eliminar productos, categorías, unidades,
--                           proveedores, clientes y personal
--
--  Los dos, solo al rol Administrador. El cajero sigue registrando clientes
--  desde el mostrador, pero no los edita ni los borra; el almacenero da de alta
--  y edita el catálogo, pero no elimina.
--
--  Qué quita
--  ---------
--      reportes.ver del rol Almacenero: con él veía las ventas, las cajas y las
--      devoluciones de todos los cajeros. Eso lo ve solo el administrador.
--
--  Se puede correr dos veces.
-- =============================================================================

USE ventas_db;

INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
    ('clientes.editar',    'Ventas',  'Editar los datos de un cliente ya registrado'),
    ('registros.eliminar', 'Sistema', 'Eliminar productos, categorías, unidades, proveedores, clientes y personal');

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.codigo IN ('clientes.editar', 'registros.eliminar')
 WHERE r.nombre = 'Administrador';

DELETE rp
  FROM rol_permiso rp
  JOIN roles r    ON r.id = rp.rol_id
  JOIN permisos p ON p.id = rp.permiso_id
 WHERE r.nombre = 'Almacenero' AND p.codigo = 'reportes.ver';

SELECT r.nombre AS rol, GROUP_CONCAT(p.codigo ORDER BY p.codigo) AS permisos
  FROM roles r
  JOIN rol_permiso rp ON rp.rol_id = r.id
  JOIN permisos p     ON p.id = rp.permiso_id
 GROUP BY r.id, r.nombre;
