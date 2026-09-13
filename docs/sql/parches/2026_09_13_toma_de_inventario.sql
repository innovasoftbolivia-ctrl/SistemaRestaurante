-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Toma de inventario completa (2026-09-13)
--
--  Qué agrega
--  ----------
--      tomas_inventario          -> un conteo físico de la tienda o de una
--                                   categoría: abierta, cerrada o cancelada
--      toma_inventario_detalle   -> cada producto de ese conteo, con lo que se
--                                   contó y lo que decía el sistema al contarlo
--
--  No toca ninguna tabla existente: el cierre de la toma aplica sus
--  diferencias como ajustes normales en `movimientos_inventario`.
--
--  Sin permiso nuevo: contar y cerrar una toma es ajustar inventario, así que
--  pide `inventario.ajustar`, igual que el ajuste de a un producto.
--
--  Sin CHARSET ni COLLATE: se heredan de la base, como todas las demás tablas.
--  Se puede correr dos veces: CREATE TABLE IF NOT EXISTS.
-- =============================================================================

USE ventas_db;

-- Toma de inventario: contar toda la tienda (o una categoría) de una vez.
--
-- El ajuste de `movimientos_inventario` es de a un producto, y sirve para la
-- rotura de hoy. Contar el local entero producto por producto, abriendo un
-- formulario para cada uno, no lo hace nadie. La toma abre la lista de lo que
-- hay que contar, guarda cada conteo a medida que se hace, y al cerrarla
-- aplica todas las diferencias juntas —como ajustes normales, en el kardex—.
--
-- El local sigue vendiendo mientras se cuenta. Por eso cada línea guarda lo que
-- decía el sistema EN EL MOMENTO de contarla (`stock_sistema`), y el cierre
-- aplica la DIFERENCIA y no el número contado: si se contaron 10 a las 9:00 y
-- a las 11:00 se vendieron 2, el cierre deja 8, no 10.
CREATE TABLE IF NOT EXISTS tomas_inventario (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    categoria_id        SMALLINT UNSIGNED NULL,          -- NULL = toda la tienda
    estado              ENUM('ABIERTA','CERRADA','CANCELADA') NOT NULL DEFAULT 'ABIERTA',
    observacion         VARCHAR(255) NULL,
    usuario_apertura_id INT UNSIGNED NOT NULL,
    fecha_apertura      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    usuario_cierre_id   INT UNSIGNED NULL,               -- quien la cerró o la canceló
    fecha_cierre        DATETIME     NULL,
    -- Una sola toma abierta a la vez: 1 mientras está abierta, NULL después, y
    -- un índice único admite muchos NULL. Dos tomas abiertas sobre el mismo
    -- producto aplicarían la misma diferencia dos veces.
    abierta             TINYINT(1) GENERATED ALWAYS AS (IF(estado = 'ABIERTA', 1, NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tomas_una_abierta (abierta),
    KEY ix_tomas_fecha (fecha_apertura),
    CONSTRAINT fk_tomas_categoria FOREIGN KEY (categoria_id)        REFERENCES categorias (id),
    CONSTRAINT fk_tomas_apertura  FOREIGN KEY (usuario_apertura_id) REFERENCES usuarios (id),
    CONSTRAINT fk_tomas_cierre    FOREIGN KEY (usuario_cierre_id)   REFERENCES usuarios (id),
    CONSTRAINT ck_tomas_cierre CHECK ((estado = 'ABIERTA') = (fecha_cierre IS NULL))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS toma_inventario_detalle (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    toma_id             INT UNSIGNED NOT NULL,
    producto_id         INT UNSIGNED NOT NULL,
    contado             DECIMAL(12,3) NULL,              -- NULL = todavía no se contó
    stock_sistema       DECIMAL(12,3) NULL,              -- lo que decía el sistema al contarlo
    diferencia          DECIMAL(12,3) GENERATED ALWAYS AS (contado - stock_sistema) STORED,
    costo_unitario      DECIMAL(12,2) NULL,              -- precio de compra al contarlo: valoriza la diferencia
    usuario_id          INT UNSIGNED NULL,               -- quién lo contó
    fecha_conteo        DATETIME     NULL,
    movimiento_id       BIGINT UNSIGNED NULL,            -- el ajuste que aplicó el cierre
    PRIMARY KEY (id),
    UNIQUE KEY uq_toma_detalle_producto (toma_id, producto_id),
    KEY ix_toma_detalle_producto (producto_id),
    CONSTRAINT fk_toma_detalle_toma       FOREIGN KEY (toma_id)       REFERENCES tomas_inventario (id),
    CONSTRAINT fk_toma_detalle_producto   FOREIGN KEY (producto_id)   REFERENCES productos (id),
    CONSTRAINT fk_toma_detalle_usuario    FOREIGN KEY (usuario_id)    REFERENCES usuarios (id),
    CONSTRAINT fk_toma_detalle_movimiento FOREIGN KEY (movimiento_id) REFERENCES movimientos_inventario (id),
    CONSTRAINT ck_toma_detalle_contado CHECK (
        (contado IS NULL AND stock_sistema IS NULL)
     OR (contado >= 0 AND stock_sistema IS NOT NULL)
    )
) ENGINE=InnoDB;

SELECT COUNT(*) AS tomas_de_inventario FROM tomas_inventario;
