-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - El contenido del empaque admite fracciones (2026-09-10)
--
--  Qué cambia
--  ----------
--      contenido_empaque  SMALLINT UNSIGNED  ->  DECIMAL(10,3) UNSIGNED
--      ck_productos_empaque: el mínimo pasa de «>= 2» a «> 1»
--
--  Por qué
--  -------
--  El empaque nació pensado en cosas que se cuentan —cajas de 24 gaseosas—, y
--  ahí un entero sobra. Pero el mismo campo es el que sirve para lo que se pesa
--  y lo que se mide, y ahí las fracciones son la norma y no la excepción:
--
--      un saco de 46 kg          -> entero, funcionaba ya
--      un bidón de 20 L          -> entero, funcionaba ya
--      un galón de 3.785 L       -> NO entraba
--      una caja de 2.5 kg        -> NO entraba
--
--  Con el campo en entero, a quien compra por galón no le quedaba más remedio
--  que redondear, y el redondeo se le iba al stock: cargar 10 galones como 10x4
--  son 40 litros contra los 37.85 que entraron de verdad. Un descuadre de 2
--  litros por compra que nadie sabría explicar tres meses después.
--
--  DECIMAL(10,3) y no FLOAT: la misma precisión que `stock_actual`, y por el
--  mismo motivo —el stock resultante de una multiplicación tiene que caber
--  exacto en la columna que lo guarda, sin arrastrar el error binario de un
--  flotante—.
--
--  Sobre el mínimo
--  ---------------
--  Seguía siendo «>= 2» de cuando todo era entero. Con fracciones eso prohibía
--  una caja de 1.5 kg, que es perfectamente real. Lo que hay que impedir es el
--  empaque que no ahorra ninguna cuenta —el de una unidad o menos—, y eso lo
--  dice «> 1» sin dejar fuera nada legítimo.
--
--  Idempotente
--  -----------
--  Se puede correr dos veces: el ALTER solo se arma si la columna todavía es
--  la vieja, y el CHECK se suelta solo si existe.
-- =============================================================================

SET NAMES utf8mb4;

SET @esEntero := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'productos'
      AND COLUMN_NAME = 'contenido_empaque'
      AND DATA_TYPE = 'smallint'
);

-- El CHECK nombra la columna, así que hay que soltarlo antes de cambiarle
-- el tipo y volver a ponerlo después.
SET @sql := IF(@esEntero = 1,
    'ALTER TABLE productos DROP CHECK ck_productos_empaque', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(@esEntero = 1, '
    ALTER TABLE productos
        MODIFY COLUMN contenido_empaque DECIMAL(10,3) UNSIGNED NULL
            COMMENT "unidades de venta que trae un empaque; NULL = no viene en empaque",
        ADD CONSTRAINT ck_productos_empaque CHECK (
            (contenido_empaque IS NULL AND nombre_empaque IS NULL)
            OR (contenido_empaque > 1 AND nombre_empaque IS NOT NULL)
        )
', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
