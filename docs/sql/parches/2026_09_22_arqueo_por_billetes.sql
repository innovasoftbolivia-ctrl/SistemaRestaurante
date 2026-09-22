USE ventas_db;
SET NAMES utf8mb4;

-- =============================================================================
--  Arqueo por billetes y monedas al cerrar la caja (2026-09-22)
--
--  El cajero cuenta cuántos billetes de 200, de 100… y cuántas monedas hay en
--  el cajón, y el sistema suma: el efectivo contado ya no se escribe a mano,
--  que era donde se equivocaba la cuenta. El detalle queda guardado con el
--  turno y sale en el resumen impreso del cierre.
--
--  Una fila por denominación contada (1FN: no una lista en un texto). Contar
--  por billetes es opcional: un turno cerrado escribiendo el total no tiene
--  filas aquí.
--
--  El mismo cambio está en 01_schema_mysql.sql. Idempotente (IF NOT EXISTS).
--  La cascada de la FK la cambia a RESTRICT el parche 2026_09_22_auditoria.
-- =============================================================================

CREATE TABLE IF NOT EXISTS arqueo_caja (
    sesion_caja_id  INT UNSIGNED  NOT NULL,
    denominacion    DECIMAL(8,2)  NOT NULL,   -- 200.00, 100.00 … 0.10
    cantidad        INT UNSIGNED  NOT NULL,   -- cuántos billetes o monedas
    PRIMARY KEY (sesion_caja_id, denominacion),
    CONSTRAINT fk_arqueo_sesion FOREIGN KEY (sesion_caja_id) REFERENCES sesiones_caja (id) ON DELETE CASCADE,
    CONSTRAINT ck_arqueo_denominacion CHECK (denominacion > 0),
    CONSTRAINT ck_arqueo_cantidad CHECK (cantidad > 0)
) ENGINE=InnoDB;
