USE ventas_db;
SET NAMES utf8mb4;

-- =============================================================================
--  El cajero ve la cocina y entrega lo listo (2026-09-21)
--
--  Permiso nuevo `cocina.entregar`: ver la pantalla de la cocina y marcar
--  ENTREGADO lo que ya está LISTO, sin poder mover la preparación (empezar,
--  marcar listo). Es para quien lleva los platos desde el mostrador. La
--  cocina sigue con `cocina.ver`, que lo puede todo.
--
--  Idempotente: aplicarlo dos veces no agrega nada.
-- =============================================================================

INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
    ('cocina.entregar', 'Cocina', 'Ver la pantalla de la cocina y marcar entregado lo que ya está listo');

-- Quien administra usuarios (el Administrador) tiene todos los permisos. Sin
-- este, tampoco podría otorgarlo: nadie reparte lo que no tiene
-- (Administracion::otorgaDeMas, en app/Support).
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT DISTINCT rp.rol_id, nuevo.id
  FROM rol_permiso rp
  JOIN permisos admin ON admin.id = rp.permiso_id AND admin.codigo = 'usuarios.gestionar'
  JOIN permisos nuevo ON nuevo.codigo = 'cocina.entregar';

-- El rol del mostrador. Si el negocio lo renombró, se le asigna desde
-- Seguridad → Roles.
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, nuevo.id
  FROM roles r
  JOIN permisos nuevo ON nuevo.codigo = 'cocina.entregar'
 WHERE r.nombre = 'Cajero';
