-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - La unidad, impresa en el comprobante (2026-09-10)
--
--  El problema
--  -----------
--  El ticket imprimía la columna «Cant.» a secas: un 2.5 pelado. Para una
--  gaseosa se entiende, pero el negocio también vende arroz por kilo y aceite
--  por litro, y ahí «2.5» no dice nada. El cliente que recibe el papel no puede
--  comprobar qué se le cobró, que es justamente para lo que sirve el papel.
--
--  Qué cambia
--  ----------
--  Una columna nueva en `venta_detalle`:
--
--      unidad  VARCHAR(10) NULL   -- UND, KG, LT… tal como estaba al vender
--
--  Por qué se copia y no se lee del producto
--  -----------------------------------------
--  Porque es un documento emitido. `venta_detalle` ya guarda copia histórica
--  del nombre (`descripcion`), del precio (`precio_unitario`) y hasta del
--  régimen de impuesto, con una regla detrás: una venta ya emitida no cambia
--  porque cambie el catálogo. La unidad es del mismo tipo de dato. Si se leyera
--  al vuelo de `productos.unidad_medida_id`, bastaría con que alguien corrigiera
--  un producto de UND a KG para que todos los tickets viejos de ese producto
--  pasaran a decir kilos donde se vendieron unidades — y un comprobante
--  reimpreso no diría lo mismo que el que se entregó en mano.
--
--  Los tickets ya emitidos
--  -----------------------
--  Se rellenan con la unidad que el producto tiene HOY. No es la copia
--  histórica que sería ideal —esa información no se guardó nunca y no hay de
--  dónde sacarla—, pero es la mejor aproximación disponible: hasta hoy nadie
--  pudo cambiar de unidad sin que el kardex y el stock lo delataran, así que en
--  la práctica coincide. A partir de este parche sí queda congelada de verdad.
--
--  Idempotente: la columna se agrega solo si falta.
-- =============================================================================

SET @falta := (
    SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'venta_detalle'
      AND COLUMN_NAME = 'unidad'
);

SET @sql := IF(@falta, '
    ALTER TABLE venta_detalle
        ADD COLUMN unidad VARCHAR(10) NULL
            COMMENT "copia histórica de la unidad de venta: UND, KG, LT…"
            AFTER descripcion
', 'DO 0');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Relleno de lo ya vendido, con la unidad vigente del producto.
UPDATE venta_detalle d
  JOIN productos p        ON p.id = d.producto_id
  JOIN unidades_medida u  ON u.id = p.unidad_medida_id
   SET d.unidad = u.codigo
 WHERE d.unidad IS NULL;
