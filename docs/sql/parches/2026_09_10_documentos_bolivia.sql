-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Documentos de identidad bolivianos (2026-09-10)
--
--  Qué cambia
--  ----------
--  Los códigos de documento pasan de peruanos a bolivianos:
--
--      DNI  ->  CI    (Cédula de Identidad)
--      RUC  ->  NIT   (Número de Identificación Tributaria)
--
--  CE (Carnet de Extranjería) y PAS (Pasaporte) se quedan: existen igual en
--  Bolivia. SIN («sin documento», la venta al paso) también.
--
--  Por qué importa
--  ---------------
--  No es cosmético: el código del documento sale IMPRESO en cada comprobante,
--  al lado del número. Un cliente boliviano que recibe una factura que dice
--  «RUC 1023456789» está leyendo un documento que en su país no existe.
--
--  Por qué toca tres tablas
--  ------------------------
--  `clientes` y `empleados` son los maestros. `comprobantes` guarda una COPIA
--  del documento del cliente al momento de emitir —a propósito, para que el
--  papel no cambie si después se corrige la ficha—, así que esa copia también
--  hay que migrarla o los comprobantes viejos seguirían diciendo «DNI».
--
--  Se migran los comprobantes de la demo porque son datos de ejemplo. En una
--  instalación con historia real la decisión sería otra: un documento ya
--  entregado al cliente no se reescribe, y habría que dejar los códigos viejos
--  en el ENUM para no romper el pasado. Hoy no hay ninguna instalación con
--  historia real, así que el corte limpio es correcto y más simple.
--
--  El orden NO es negociable
--  -------------------------
--  Un ENUM de MySQL no admite un valor que no esté declarado, y los CHECK
--  nombran los códigos uno por uno. Si se intenta el UPDATE antes de ampliar,
--  falla entero. Por eso:
--
--    1. se sueltan los CHECK que nombran los códigos viejos
--    2. se amplía el ENUM para que quepan los viejos Y los nuevos a la vez
--    3. recién ahí se migran los datos
--    4. se estrecha el ENUM a los códigos bolivianos
--    5. se rehacen los CHECK con los nombres nuevos
--
--  Se aplica sobre una base que ya tiene datos. No es idempotente: si ya se
--  aplicó, el paso 1 falla porque esos CHECK ya no nombran lo mismo.
-- =============================================================================

USE ventas_db;

-- -----------------------------------------------------------------------------
--  1. Soltar los CHECK que nombran los códigos viejos
-- -----------------------------------------------------------------------------
ALTER TABLE clientes DROP CHECK ck_clientes_natural;
ALTER TABLE clientes DROP CHECK ck_clientes_juridica;

-- -----------------------------------------------------------------------------
--  2. Ampliar: viejos y nuevos conviven un momento
-- -----------------------------------------------------------------------------
ALTER TABLE clientes
    MODIFY tipo_documento ENUM('DNI','CE','PAS','RUC','SIN','CI','NIT')
    NOT NULL DEFAULT 'DNI';

ALTER TABLE empleados
    MODIFY tipo_documento ENUM('DNI','CE','PAS','CI')
    NOT NULL DEFAULT 'DNI';

ALTER TABLE comprobantes
    MODIFY cliente_tipo_documento ENUM('DNI','CE','PAS','RUC','SIN','CI','NIT')
    NOT NULL DEFAULT 'SIN';

-- -----------------------------------------------------------------------------
--  3. Migrar los datos
-- -----------------------------------------------------------------------------
UPDATE clientes     SET tipo_documento = 'CI'  WHERE tipo_documento = 'DNI';
UPDATE clientes     SET tipo_documento = 'NIT' WHERE tipo_documento = 'RUC';
UPDATE empleados    SET tipo_documento = 'CI'  WHERE tipo_documento = 'DNI';
UPDATE comprobantes SET cliente_tipo_documento = 'CI'  WHERE cliente_tipo_documento = 'DNI';
UPDATE comprobantes SET cliente_tipo_documento = 'NIT' WHERE cliente_tipo_documento = 'RUC';

-- -----------------------------------------------------------------------------
--  4. Estrechar: solo códigos bolivianos
-- -----------------------------------------------------------------------------
ALTER TABLE clientes
    MODIFY tipo_documento ENUM('CI','CE','PAS','NIT','SIN')
    NOT NULL DEFAULT 'CI';

ALTER TABLE empleados
    MODIFY tipo_documento ENUM('CI','CE','PAS')
    NOT NULL DEFAULT 'CI';

ALTER TABLE comprobantes
    MODIFY cliente_tipo_documento ENUM('CI','CE','PAS','NIT','SIN')
    NOT NULL DEFAULT 'SIN';

-- -----------------------------------------------------------------------------
--  5. Rehacer los CHECK con los códigos nuevos
--
--  Dicen lo mismo que antes: una persona natural no lleva NIT y una jurídica
--  no lleva otra cosa. Es lo que impide emitir una factura a nombre de alguien
--  que se identificó con cédula.
-- -----------------------------------------------------------------------------
ALTER TABLE clientes
    ADD CONSTRAINT ck_clientes_natural CHECK (
        tipo_persona <> 'NATURAL' OR (
            nombres      IS NOT NULL AND
            apellidos    IS NOT NULL AND
            razon_social IS NULL     AND
            tipo_documento IN ('CI','CE','PAS','SIN')
        )
    );

ALTER TABLE clientes
    ADD CONSTRAINT ck_clientes_juridica CHECK (
        tipo_persona <> 'JURIDICA' OR (
            razon_social IS NOT NULL AND
            documento    IS NOT NULL AND
            direccion    IS NOT NULL AND
            nombres      IS NULL     AND
            apellidos    IS NULL     AND
            tipo_documento = 'NIT'
        )
    );

-- -----------------------------------------------------------------------------
--  Control
-- -----------------------------------------------------------------------------
SELECT 'clientes'     AS tabla, tipo_documento AS codigo, COUNT(*) AS n FROM clientes     GROUP BY tipo_documento
UNION ALL
SELECT 'empleados',    tipo_documento,         COUNT(*) FROM empleados    GROUP BY tipo_documento
UNION ALL
SELECT 'comprobantes', cliente_tipo_documento, COUNT(*) FROM comprobantes GROUP BY cliente_tipo_documento;
