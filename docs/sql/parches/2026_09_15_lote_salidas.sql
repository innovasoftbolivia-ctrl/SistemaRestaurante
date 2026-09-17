-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - De qué lote salió cada venta (2026-09-15)
--
--  Lo anulado o devuelto volvía a un lote cualquiera y perdía su fecha real.
--  Esta tabla anota de qué lote salió cada línea de venta, para reponer ahí.
--  Las ventas anteriores no tienen registro y siguen con el reparto de antes.
--
--  Idempotente: CREATE TABLE IF NOT EXISTS.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

-- De qué lote salió cada línea de venta. Sin esto, lo anulado o devuelto
-- volvía al lote que vence antes entre los abiertos —o a uno que vence en un
-- año— y las unidades perdían su fecha real: dejaban de aparecer en la alerta
-- de vencimientos. `repuesta` evita devolver dos veces lo mismo.
CREATE TABLE IF NOT EXISTS lote_salidas (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lote_id             BIGINT UNSIGNED NOT NULL,
    venta_detalle_id    BIGINT UNSIGNED NOT NULL,
    cantidad            DECIMAL(12,3)   NOT NULL,
    repuesta            DECIMAL(12,3)   NOT NULL DEFAULT 0.000,
    PRIMARY KEY (id),
    KEY ix_lote_salidas_linea (venta_detalle_id),
    KEY ix_lote_salidas_lote  (lote_id),
    CONSTRAINT fk_lote_salidas_lote  FOREIGN KEY (lote_id)          REFERENCES lotes (id),
    CONSTRAINT fk_lote_salidas_linea FOREIGN KEY (venta_detalle_id) REFERENCES venta_detalle (id),
    CONSTRAINT ck_lote_salidas CHECK (cantidad > 0 AND repuesta >= 0 AND repuesta <= cantidad)
) ENGINE=InnoDB;

SELECT COUNT(*) AS salidas_registradas FROM lote_salidas;
