-- =============================================================================
--  SISTEMA DEL RESTAURANTE
--  Parche - El local no trabaja con mozos (2026-09-18)
--
--  El dueño: «el restaurante no ocupará esa función de momento». Se retiran el
--  rol Mozo (con sus permisos) y el cargo Mesero. Los pedidos los toma el
--  Cajero, que ya tenía `pedidos.registrar`; ese permiso se queda.
--
--  Mismo criterio que con el rol Almacenero en
--  2026_09_17_eliminar_inventario_y_devoluciones.sql:
--
--  - Las cuentas que todavía tengan el rol Mozo pasan a Cajero ANTES de
--    borrarlo. Seguir pudiendo entrar importa más que el permiso exacto, y
--    desactivarlas dejaría a alguien sin poder trabajar al día siguiente del
--    parche. Ojo: el Cajero además cobra y abre caja, cosa que el Mozo no
--    hacía. Revisa después en Seguridad → Usuarios si a esa persona le
--    corresponde otro rol (o desactiva la cuenta si ya no la usa).
--  - Si no hay rol Cajero (una base a la que se lo borraron a mano), esas
--    cuentas se quedan con el Mozo y el rol no se borra: sin rol no hay cuenta
--    que valga (`usuarios.rol_id` es obligatorio).
--  - El cargo Mesero se borra solo si ningún empleado lo tiene. El cargo es la
--    función que dice el contrato de una persona real, no un permiso: si
--    alguien figura como Mesero, se deja como está y lo decide quien lleva el
--    personal (Administración → Cargos).
--
--  No toca pedidos ni ventas: lo que abrió un mozo sigue a su nombre.
--  Idempotente: una segunda pasada no encuentra nada que hacer.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

-- ------------------------------------------------ las cuentas pasan a Cajero
UPDATE usuarios SET rol_id = (SELECT id FROM roles WHERE nombre = 'Cajero')
 WHERE rol_id IN (SELECT id FROM (SELECT id FROM roles WHERE nombre = 'Mozo') AS r)
   AND EXISTS (SELECT 1 FROM (SELECT id FROM roles WHERE nombre = 'Cajero') AS c);

-- ------------------------------------------------------------- el rol Mozo
-- Solo si ya no le queda ninguna cuenta (ver arriba el caso sin Cajero).
DELETE rp FROM rol_permiso rp
  JOIN roles r ON r.id = rp.rol_id
 WHERE r.nombre = 'Mozo'
   AND NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.rol_id = r.id);

DELETE r FROM roles r
 WHERE r.nombre = 'Mozo'
   AND NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.rol_id = r.id);

-- ---------------------------------------------------------- el cargo Mesero
DELETE c FROM cargos c
 WHERE c.nombre = 'Mesero'
   AND NOT EXISTS (SELECT 1 FROM empleados e WHERE e.cargo_id = c.id);
