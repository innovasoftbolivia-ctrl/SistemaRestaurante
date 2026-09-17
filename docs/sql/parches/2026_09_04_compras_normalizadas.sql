-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Cabecera y detalle de compras, normalizadas (2026-09-04)
--
--  Problema que corrige
--  --------------------
--  Una compra a un proveedor (una guía o factura, con varias líneas de
--  producto) no tenía tabla propia: cada línea quedaba como una fila suelta
--  en `movimientos_inventario`, repitiendo `proveedor_id` y
--  `documento_externo` en cada una. Si una compra traía 5 productos, esos dos
--  datos —que describen la COMPRA, no cada movimiento— se guardaban 5 veces.
--  Corregir un número de guía mal tecleado exigía tocar N filas, y no había
--  una sola fila que representara "esta compra" como unidad (2FN: atributos
--  que dependen de la compra completa, no de cada línea de kardex).
--
--  Qué hace
--  --------
--  Crea `compras` (cabecera: proveedor, usuario, documento, fecha) y
--  `compra_detalle` (una fila por producto, con su cantidad y costo; el
--  importe es columna generada, igual que en `venta_detalle`). Añade
--  `compra_id` a `movimientos_inventario` para que un movimiento de origen
--  COMPRA pueda enlazar a su cabecera.
--
--  No se toca nada existente: `proveedor_id` y `documento_externo` siguen en
--  `movimientos_inventario` para no romper el historial ya cargado (esas
--  columnas seguían siendo válidas antes de este parche) ni la pantalla
--  actual de "Ingresar mercadería", que registra una línea a la vez y sigue
--  funcionando igual. `compra_id` queda NULL en los movimientos antiguos y en
--  los que siga generando esa pantalla mientras no se conecte a las tablas
--  nuevas; cuando exista una pantalla de compra con varias líneas, esos
--  movimientos sí la referenciarán.
--
--  Es idempotente: se puede ejecutar más de una vez sin romper nada.
--
--      docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 \
--          -uroot -pventas123 ventas_db \
--          < docs/sql/parches/2026_09_04_compras_normalizadas.sql
--
--  Los instaladores nuevos NO necesitan este parche: 01_schema_mysql.sql ya
--  crea estas tablas.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS compras (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    proveedor_id        INT UNSIGNED NOT NULL,
    usuario_id          INT UNSIGNED NOT NULL,
    documento_externo   VARCHAR(30)  NULL,       -- guía o factura del proveedor
    fecha               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    observacion         VARCHAR(255) NULL,
    creado_en           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_compras_proveedor (proveedor_id),
    KEY ix_compras_fecha     (fecha),
    CONSTRAINT fk_compras_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores (id),
    CONSTRAINT fk_compras_usuario   FOREIGN KEY (usuario_id)   REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS compra_detalle (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    compra_id       INT UNSIGNED NOT NULL,
    producto_id     INT UNSIGNED NOT NULL,
    cantidad        DECIMAL(12,3) NOT NULL,
    costo_unitario  DECIMAL(12,2) NOT NULL,
    -- derivada de las dos anteriores: columna generada, no se puede desincronizar (3FN)
    importe         DECIMAL(12,2) GENERATED ALWAYS AS (ROUND(cantidad * costo_unitario, 2)) STORED,
    PRIMARY KEY (id),
    KEY ix_compradet_compra   (compra_id),
    KEY ix_compradet_producto (producto_id),
    CONSTRAINT fk_compradet_compra   FOREIGN KEY (compra_id)   REFERENCES compras (id) ON DELETE CASCADE,
    CONSTRAINT fk_compradet_producto FOREIGN KEY (producto_id) REFERENCES productos (id),
    CONSTRAINT ck_compradet_cantidad CHECK (cantidad > 0),
    CONSTRAINT ck_compradet_costo    CHECK (costo_unitario >= 0)
) ENGINE=InnoDB;

SET @existe_columna = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'movimientos_inventario'
       AND COLUMN_NAME  = 'compra_id'
);

SET @sql_columna = IF(@existe_columna = 0,
    'ALTER TABLE movimientos_inventario
         ADD COLUMN compra_id INT UNSIGNED NULL AFTER proveedor_id,
         ADD KEY ix_movinv_compra (compra_id),
         ADD CONSTRAINT fk_movinv_compra FOREIGN KEY (compra_id) REFERENCES compras (id)',
    'SELECT 1'
);

PREPARE stmt FROM @sql_columna;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
