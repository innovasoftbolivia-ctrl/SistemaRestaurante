-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - El cajero ya no cierra su propia caja (2026-09-04)
--
--  Problema que corrige
--  --------------------
--  El rol Cajero traía el permiso `caja.cerrar` desde la instalación inicial:
--  cualquier cajero podía cerrar su propio turno, sin que nadie más contara
--  el efectivo. Control interno básico de un negocio con caja: el arqueo lo
--  hace alguien que no tuvo la mano en el cajón durante el turno.
--
--  Qué hace
--  --------
--  Quita `caja.cerrar` del rol Cajero, si lo tiene. Deja intacto todo lo
--  demás: el cajero sigue abriendo su turno, vendiendo y registrando sus
--  propios movimientos (ingresos/egresos), solo que ya no lo cierra él mismo.
--
--  Si el negocio YA reasignó los permisos a su gusto desde Roles y permisos
--  —por ejemplo, le devolvió `caja.cerrar` al Cajero a propósito—, este
--  parche no lo sabe y se lo vuelve a quitar iguial. Revisa `Roles y
--  permisos` después de aplicarlo si tu negocio necesita otra cosa.
--
--  Es idempotente: si el rol ya no tiene el permiso, no hace nada.
--
--      docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 \
--          -uroot -pventas123 ventas_db \
--          < docs/sql/parches/2026_09_04_cajero_no_cierra_caja.sql
--
--  Los instaladores nuevos NO necesitan este parche: 02_datos_iniciales.sql
--  ya no le da `caja.cerrar` al Cajero desde el alta.
-- =============================================================================

SET NAMES utf8mb4;

DELETE rp FROM rol_permiso rp
JOIN roles r    ON r.id = rp.rol_id
JOIN permisos p ON p.id = rp.permiso_id
WHERE r.nombre = 'Cajero'
  AND p.codigo = 'caja.cerrar';
