-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - El empaque en el que llega el producto (2026-09-10)
--
--  El problema
--  -----------
--  Un producto tenía UNA unidad y UN stock. El comerciante compra por caja y
--  vende por unidad, así que con ese modelo tenía que elegir entre dos males:
--
--    - unidad = CAJA  ->  el mostrador vende cajas enteras y ya no puede
--                         despachar una gaseosa suelta;
--    - unidad = UND   ->  puede vender sueltas, pero cada vez que llega
--                         mercadería tiene que hacer la multiplicación de
--                         cabeza (5 cajas x 24 = 120) y escribir 120.
--
--  Y el caso que rompe las dos opciones: llegan 3 cajas completas y 5 sueltas
--  porque la cuarta vino a medias. Hoy eso se resuelve con calculadora, se
--  escribe 77, y el kardex pierde para siempre el dato de qué llegó de verdad.
--
--  Qué cambia
--  ----------
--  Dos columnas nuevas en `productos`:
--
--      contenido_empaque  -> cuántas unidades de venta trae un empaque (24)
--      nombre_empaque     -> cómo se llama ese empaque ("Caja", "Paquete")
--
--  La regla que ordena todo esto: `stock_actual` SIGUE contándose siempre en
--  la unidad en la que se VENDE. El empaque no es una segunda unidad de stock,
--  es solo una forma de contar rápido al ingresar mercadería. Por eso no toca
--  ni `movimientos_inventario` ni el punto de venta ni los reportes: para todo
--  lo que ya existía, el stock sigue siendo el mismo número que antes.
--
--  Por qué NULL y no 1
--  -------------------
--  Un producto sin empaque —el arroz a granel, el kilo de azúcar— no tiene
--  «cajas de 1». Con NULL la pantalla sabe que no debe mostrar la casilla de
--  cajas, y el CHECK impide el estado a medias (contenido sin nombre o al
--  revés), que sería una caja sin nombre o un nombre sin contenido.
--
--  El mínimo es 2: un empaque de 1 unidad no ahorra ninguna cuenta y solo
--  serviría para confundir la pantalla de ingreso.
--
--  Idempotente
--  -----------
--  Se puede correr dos veces. Las columnas se agregan solo si faltan, para que
--  aplicar el parche sobre una base ya parchada no aborte el script entero.
-- =============================================================================

SET @faltan := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'productos'
      AND COLUMN_NAME = 'contenido_empaque'
);

SET @sql := IF(@faltan = 0, '
    ALTER TABLE productos
        ADD COLUMN contenido_empaque SMALLINT UNSIGNED NULL
            COMMENT "unidades de venta que trae un empaque; NULL = no viene en empaque"
            AFTER unidad_medida_id,
        ADD COLUMN nombre_empaque VARCHAR(20) NULL
            COMMENT "cómo se llama el empaque: Caja, Paquete, Plancha…"
            AFTER contenido_empaque,
        ADD CONSTRAINT ck_productos_empaque CHECK (
            (contenido_empaque IS NULL AND nombre_empaque IS NULL)
            OR (contenido_empaque >= 2 AND nombre_empaque IS NOT NULL)
        )
', 'DO 0');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
--  Datos de la demo
--
--  Se rellena solo lo que el catálogo de ejemplo tiene de verdad en caja: las
--  gaseosas y las cervezas vienen por docena o media docena, los cigarrillos
--  por plancha de 10 cajetillas. Todo lo demás (arroz por kilo, aceite suelto)
--  se queda en NULL, que es como debe verse un producto sin empaque.
--
--  Los precios NO se tocan: `precio_compra` ya está expresado por unidad de
--  venta, que es como lo espera el resto del sistema.
-- -----------------------------------------------------------------------------

UPDATE productos p
  JOIN categorias c ON c.id = p.categoria_id
   SET p.contenido_empaque = 12,
       p.nombre_empaque    = 'Caja'
 WHERE c.nombre = 'Bebidas'
   AND p.contenido_empaque IS NULL;

UPDATE productos p
  JOIN categorias c ON c.id = p.categoria_id
   SET p.contenido_empaque = 10,
       p.nombre_empaque    = 'Plancha'
 WHERE c.nombre = 'Cigarrillos'
   AND p.contenido_empaque IS NULL;
