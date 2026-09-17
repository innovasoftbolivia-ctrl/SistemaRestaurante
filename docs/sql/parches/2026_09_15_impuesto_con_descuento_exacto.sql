-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Impuesto con descuento, al centavo (2026-09-15)  [SIN EFECTO]
--
--  Este parche corregía `sp_recalcular_venta`, que guardaba la proporción del
--  descuento en una variable DECIMAL(12,6) y perdía un centavo (con impuesto
--  bruto 0.51, base 3.96 y descuento 0.66 calculaba 0.42 en lugar de 0.43).
--
--  Ya NO reemplaza el procedimiento. La misma corrección, y además el modo de
--  precios con el impuesto incluido, vienen en
--  `2026_09_15_precios_con_impuesto_incluido.sql`, que es el único parche que
--  define hoy `sp_recalcular_venta`. Si este archivo volviera a recrear su
--  versión de aquel día, el descuento del mostrador dejaría de aplicarse en el
--  modo de impuesto incluido: la venta guarda `descuento_precio_final` y aquel
--  procedimiento no lo leía.
--
--  Se conserva para que las bases que ya lo tienen registrado no lo vean como
--  pendiente. No hace nada.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

SELECT 'Sin efecto: sp_recalcular_venta lo define 2026_09_15_precios_con_impuesto_incluido.sql' AS aviso;
