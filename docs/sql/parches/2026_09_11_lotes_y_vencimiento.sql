-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Lotes y fecha de vencimiento (2026-09-11)
--
--  El problema
--  -----------
--  El stock era un solo número por producto:
--
--      productos.stock_actual = 245
--
--  Con eso no hay forma de saber qué se vence. Los 245 chocolates pueden ser
--  120 que vencen el 15/10 y 125 que vencen el 30/11, y el sistema no puede
--  distinguirlos. Sin esa distinción no hay alerta de «se me vence en dos
--  semanas», que es exactamente lo que evita tirar mercadería a la basura.
--
--  Qué cambia
--  ----------
--      productos.controla_vencimiento   -> 1 = este producto se lleva por lotes
--      lotes                            -> el stock partido por fecha
--
--  Por qué no todos los productos
--  ------------------------------
--  Porque el detergente no vence, y obligar a poner una fecha cada vez que
--  llega detergente es la forma más rápida de que la gente escriba cualquier
--  cosa con tal de seguir. El control se enciende producto por producto, y el
--  que no lo tiene se comporta exactamente como hasta hoy: un solo número.
--
--  Por qué `fecha_vencimiento` admite NULL
--  ---------------------------------------
--  Porque hay stock que ya estaba ahí cuando se encendió el control, y porque
--  una devolución de cliente devuelve unidades que salieron hace una semana y
--  ya nadie sabe de qué lote eran. Ese stock existe y hay que contarlo, pero
--  su fecha no se conoce, y decir «sin fecha registrada» es más honesto que
--  inventar una. La alerta de vencimiento solo mira los lotes que SÍ tienen
--  fecha, y la pantalla dice aparte cuántas unidades quedaron sin fechar.
--
--  Por qué `cantidad_inicial` además de `cantidad_actual`
--  ------------------------------------------------------
--  `cantidad_actual` es lo que queda y baja con cada venta. `cantidad_inicial`
--  es lo que entró y no se toca nunca: es lo que permite después responder
--  «de ese lote que venció, ¿cuánto alcancé a vender y cuánto perdí?».
--
--  Sobre el orden de salida (FEFO)
--  -------------------------------
--  Al vender se descuenta del lote que vence ANTES —first expired, first out—,
--  que es como se despacha de verdad en un mostrador. Los lotes sin fecha van
--  al final: no se sabe cuándo vencen, así que no pueden reclamar prioridad.
--
--  Idempotente: la columna y la tabla se crean solo si faltan.
-- =============================================================================

SET @faltaColumna := (
    SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'productos'
      AND COLUMN_NAME = 'controla_vencimiento'
);

SET @sql := IF(@faltaColumna, '
    ALTER TABLE productos
        ADD COLUMN controla_vencimiento TINYINT(1) NOT NULL DEFAULT 0
            COMMENT "1 = el stock de este producto se lleva por lotes con fecha"
            AFTER nombre_empaque
', 'DO 0');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS lotes (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    producto_id         INT UNSIGNED  NOT NULL,
    -- El lote impreso por el fabricante, si la caja lo trae. Informativo: el
    -- sistema no lo usa para nada más que para poder citarlo al reclamar.
    codigo              VARCHAR(30)   NULL,
    -- NULL = stock cuya fecha no se conoce (ver cabecera).
    fecha_vencimiento   DATE          NULL,
    cantidad_inicial    DECIMAL(12,3) NOT NULL,
    cantidad_actual     DECIMAL(12,3) NOT NULL,
    -- De qué línea de compra vino, cuando vino de una. `SET NULL` y no
    -- `CASCADE`: si algún día se borrara la compra, el stock que entró por
    -- ella sigue en el estante y el lote no puede desaparecer con el papel.
    compra_detalle_id   BIGINT UNSIGNED NULL,
    creado_en           TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- El índice del despacho: por producto y por fecha, que es como se elige
    -- de qué lote sale cada venta.
    KEY ix_lotes_fefo        (producto_id, fecha_vencimiento),
    KEY ix_lotes_vencimiento (fecha_vencimiento),
    KEY ix_lotes_compra      (compra_detalle_id),
    CONSTRAINT fk_lotes_producto FOREIGN KEY (producto_id) REFERENCES productos (id),
    CONSTRAINT fk_lotes_compra   FOREIGN KEY (compra_detalle_id) REFERENCES compra_detalle (id) ON DELETE SET NULL,
    CONSTRAINT ck_lotes_cantidades CHECK (
        cantidad_inicial > 0
        AND cantidad_actual >= 0
        AND cantidad_actual <= cantidad_inicial
    )
) ENGINE=InnoDB;
