-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - `v_productos_mas_vendidos` descuenta lo devuelto  (2026-08-21)
--
--  Problema que corrige
--  --------------------
--  La vista mezclaba dos criterios en la misma fila: `unidades_vendidas` iba
--  neta de devoluciones, pero `monto_vendido` y `margen_estimado` iban en
--  bruto. Un producto devuelto por completo aparecía con cero unidades y con
--  todo su importe, y el ranking lo seguía colocando arriba.
--
--  Qué hace
--  --------
--  Redefine la vista para que las tres cifras sean netas. El importe de cada
--  línea se prorratea por la fracción no devuelta —el mismo criterio con el
--  que `sp_recalcular_venta` prorratea el impuesto—, así el descuento de línea
--  se reparte solo y no hay que repetir su fórmula.
--
--  Es `CREATE OR REPLACE`: se puede ejecutar las veces que haga falta. No toca
--  datos, solo la definición de la vista.
--
--      docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 \
--          -uroot -pventas123 ventas_db \
--          < docs/sql/parches/2026_08_21_mas_vendidos_neto.sql
--
--  Los instaladores nuevos NO lo necesitan: 01_schema_mysql.sql ya trae la
--  vista corregida.
-- =============================================================================

SET NAMES utf8mb4;

CREATE OR REPLACE VIEW v_productos_mas_vendidos AS
SELECT p.id, p.codigo, p.nombre, c.nombre AS categoria,
       SUM(n.unidades_netas)                                            AS unidades_vendidas,
       SUM(n.monto_neto)                                                AS monto_vendido,
       SUM(n.monto_neto - ROUND(n.unidades_netas * p.precio_compra, 2)) AS margen_estimado
  FROM (
        SELECT d.producto_id,
               (d.cantidad - d.cantidad_devuelta) AS unidades_netas,
               ROUND(d.importe * IF(d.cantidad > 0,
                                    (d.cantidad - d.cantidad_devuelta) / d.cantidad,
                                    0), 2)        AS monto_neto
          FROM venta_detalle d
          JOIN ventas v ON v.id = d.venta_id AND v.estado <> 'ANULADA'
       ) AS n
  JOIN productos  p ON p.id = n.producto_id
  JOIN categorias c ON c.id = p.categoria_id
 GROUP BY p.id, p.codigo, p.nombre, c.nombre;

-- ------------------------------------------------------------------ verificación
-- Un producto con devoluciones ya no debe mostrar importe con cero unidades.
SELECT codigo, nombre, unidades_vendidas, monto_vendido, margen_estimado
  FROM v_productos_mas_vendidos
 ORDER BY monto_vendido DESC;
