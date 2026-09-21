-- =============================================================================
--  SISTEMA DEL RESTAURANTE
--  Parche - Los respaldos, sin pantalla (2026-09-19, 6)
--
--  Quita el permiso `respaldos.gestionar` (y con él su asignación a cada rol,
--  por el ON DELETE CASCADE de `fk_rolperm_permiso`).
--
--  Por qué
--  -------
--  La pantalla Sistema → Respaldos ya no existe. Descargar un respaldo es
--  llevarse la base entera, con los hashes de las contraseñas, y restaurarlo es
--  trabajo de quien mantiene el sistema, no del local. Los respaldos corren por
--  debajo: el programador de tareas hace uno cada noche (`respaldo:crear`) y lo
--  sube a Google Drive (ver `App\Services\Respaldos` y
--  scripts/conectar-drive.sh). Un permiso que no abre nada solo confundiría en
--  «Roles y permisos».
--
--  Idempotente: si el permiso ya no está, no hace nada. Se aplica después de
--  2026_09_19_5_identificadores_sin_tope.sql (y de 2026_09_13_permiso_respaldos.sql,
--  que es el que lo creó).
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

DELETE FROM permisos WHERE codigo = 'respaldos.gestionar';

SELECT COUNT(*) AS roles_con_respaldos
  FROM rol_permiso rp
  JOIN permisos p ON p.id = rp.permiso_id
 WHERE p.codigo = 'respaldos.gestionar';
