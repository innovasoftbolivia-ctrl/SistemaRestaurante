-- =============================================================================
--  SISTEMA DEL RESTAURANTE
--  Parche - Identificadores de cajas, roles y cargos sin el tope de 255
--  (2026-09-19, 5)
--
--  `cajas.id`, `roles.id` y `cargos.id` eran TINYINT UNSIGNED: 255 como
--  máximo. Un negocio no llega a 255 cajas, pero el AUTO_INCREMENT no vuelve
--  atrás cuando una transacción se deshace, y cada corrida de las pruebas gasta
--  decenas de números aunque no deje ninguna fila. La base de pruebas llegó al
--  255 y las pruebas que crean una caja empezaron a fallar solas. En un
--  servidor real pasaría lo mismo con cualquier alta que fallara a mitad.
--
--  Pasan a INT UNSIGNED los tres identificadores y todo lo que los referencia:
--  `empleados.cargo_id`, `usuarios.rol_id`, `rol_permiso.rol_id`,
--  `sesiones_caja.caja_id` y la columna generada `sesiones_caja.caja_abierta_uk`
--  (la que impide dos turnos abiertos en la misma caja).
--
--  Para cambiar el tipo hay que soltar las FK un momento y volver a ponerlas,
--  con la misma definición que tienen en `01_schema_mysql.sql`. Los datos no
--  cambian: solo el tipo de las columnas.
--
--  Idempotente: cada tabla se amplía solo si todavía es TINYINT. Se aplica
--  después de 2026_09_19_4_emisor_documentos_y_detalle.sql.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS tmp_ampliar_identificadores;

DELIMITER $$

CREATE PROCEDURE tmp_ampliar_identificadores ()
BEGIN
    -- cargos ← empleados.cargo_id
    IF (SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cargos' AND COLUMN_NAME = 'id') = 'tinyint' THEN
        ALTER TABLE empleados DROP FOREIGN KEY fk_empleados_cargo;
        ALTER TABLE cargos MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT;
        ALTER TABLE empleados MODIFY cargo_id INT UNSIGNED NOT NULL;
        ALTER TABLE empleados
            ADD CONSTRAINT fk_empleados_cargo FOREIGN KEY (cargo_id) REFERENCES cargos (id);
    END IF;

    -- roles ← usuarios.rol_id y rol_permiso.rol_id
    IF (SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'id') = 'tinyint' THEN
        ALTER TABLE usuarios DROP FOREIGN KEY fk_usuarios_rol;
        ALTER TABLE rol_permiso DROP FOREIGN KEY fk_rolperm_rol;
        ALTER TABLE roles MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT;
        ALTER TABLE usuarios MODIFY rol_id INT UNSIGNED NOT NULL;
        ALTER TABLE rol_permiso MODIFY rol_id INT UNSIGNED NOT NULL;
        ALTER TABLE usuarios
            ADD CONSTRAINT fk_usuarios_rol FOREIGN KEY (rol_id) REFERENCES roles (id);
        ALTER TABLE rol_permiso
            ADD CONSTRAINT fk_rolperm_rol FOREIGN KEY (rol_id) REFERENCES roles (id) ON DELETE CASCADE;
    END IF;

    -- cajas ← sesiones_caja.caja_id, y la columna generada que la sigue. Se
    -- modifica en su sitio (no se borra y se vuelve a crear): recreada quedaría
    -- al final de la tabla y el esquema dejaría de coincidir con el de una
    -- instalación nueva.
    IF (SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cajas' AND COLUMN_NAME = 'id') = 'tinyint' THEN
        ALTER TABLE sesiones_caja DROP FOREIGN KEY fk_sesion_caja;
        ALTER TABLE cajas MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT;
        ALTER TABLE sesiones_caja
            MODIFY caja_id INT UNSIGNED NOT NULL,
            MODIFY caja_abierta_uk INT UNSIGNED
                GENERATED ALWAYS AS (IF(estado = 'ABIERTA', caja_id, NULL)) VIRTUAL;
        ALTER TABLE sesiones_caja
            ADD CONSTRAINT fk_sesion_caja FOREIGN KEY (caja_id) REFERENCES cajas (id);
    END IF;
END$$

DELIMITER ;

CALL tmp_ampliar_identificadores();
DROP PROCEDURE IF EXISTS tmp_ampliar_identificadores;
