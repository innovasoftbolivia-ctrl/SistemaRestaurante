-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Devolución al proveedor y cambio por defecto o vencimiento
--           (2026-09-11)
--
--  El problema
--  -----------
--  «Devoluciones» era del CLIENTE hacia la tienda: cuelga de una venta y el
--  kardex lo exige —el CHECK de `ck_movinv_origen` obliga a que un movimiento
--  con origen DEVOLUCION traiga su `devolucion_id` y prohíbe el proveedor—.
--  Lo que faltaba era el camino contrario: mercadería que sale de vuelta al
--  distribuidor porque vino fallada o porque se venció en el estante.
--
--  Sin esto, la única salida era un ajuste de inventario con el motivo escrito
--  a mano. Baja el stock, sí, pero queda mezclado con la merma y la rotura en
--  los reportes, no se sabe de qué factura salió, y nadie puede después
--  responder «cuánto le devolví a este proveedor el mes pasado».
--
--  Qué cambia
--  ----------
--      origen                    -> se agrega DEVOLUCION_COMPRA al ENUM
--      movimientos_inventario    -> nueva columna `devolucion_compra_id`
--      compra_detalle            -> nueva columna `cantidad_devuelta`
--      devoluciones_compra       -> la cabecera del documento
--      devolucion_compra_detalle -> sus líneas
--
--  El cambio no es un módulo aparte
--  --------------------------------
--  Cambiar un producto fallado por uno bueno es la misma devolución con una
--  casilla: `con_reposicion`. Sin ella, la mercadería sale y se espera la nota
--  de crédito. Con ella, sale la fallada y entra la repuesta —dos movimientos
--  colgados del mismo documento— y el stock queda igual que antes, que es
--  exactamente lo que pasó en el mostrador.
--
--  Por qué `cantidad_devuelta` en la línea de compra
--  -------------------------------------------------
--  Mismo motivo que en `venta_detalle`: para poder impedir que se devuelvan 30
--  unidades de una línea que trajo 24. Una restricción no puede consultar otra
--  tabla, así que el acumulado vive en la propia línea y el CHECK lo compara
--  contra lo comprado.
--
--  Por qué el ENUM y no reutilizar DEVOLUCION
--  ------------------------------------------
--  Porque son cosas distintas y mezclarlas haría imposible separarlas después:
--  una suma al stock y la otra lo resta, una la firma el cajero y la otra el
--  almacenero, y los reportes tienen que poder contarlas por separado. Ampliar
--  el ENUM obliga a un parche en cada instalación, pero un origen que miente
--  costaría más caro y para siempre.
--
--  El ORDEN no es negociable: MySQL rechaza un valor que no esté declarado en
--  el ENUM, y el CHECK nombra los orígenes uno por uno. Por eso primero se
--  suelta el CHECK, después se amplía el ENUM y al final se vuelve a poner.
--
--  Idempotente: cada paso comprueba antes si hace falta.
-- =============================================================================

-- ---------------------------------------------------------------- 1. columnas
SET @falta := (
    SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'compra_detalle'
      AND COLUMN_NAME = 'cantidad_devuelta'
);

SET @sql := IF(@falta, '
    ALTER TABLE compra_detalle
        ADD COLUMN cantidad_devuelta DECIMAL(12,3) NOT NULL DEFAULT 0.000
            COMMENT "acumulado devuelto al proveedor de esta línea"
            AFTER cantidad,
        ADD CONSTRAINT ck_compradet_devuelta CHECK (cantidad_devuelta >= 0 AND cantidad_devuelta <= cantidad)
', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------ 2. tablas
CREATE TABLE IF NOT EXISTS devoluciones_compra (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    compra_id           INT UNSIGNED NOT NULL,
    usuario_id          INT UNSIGNED NOT NULL,
    fecha               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Por qué se devuelve. Separado del texto libre porque es lo que después
    -- permite contar «cuánto devolví por vencimiento este trimestre», que es
    -- justo la cifra que dice si hay que comprar menos o rotar mejor.
    motivo              ENUM('DEFECTO','VENCIMIENTO','ERROR','OTRO') NOT NULL,
    -- 1 = el proveedor repone la mercadería (cambio). 0 = se espera nota de
    -- crédito y el stock se va sin volver.
    con_reposicion      TINYINT(1)   NOT NULL DEFAULT 0,
    documento_externo   VARCHAR(30)  NULL,       -- nota de crédito o guía de devolución
    observacion         VARCHAR(255) NULL,
    creado_en           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_devcompra_compra (compra_id),
    KEY ix_devcompra_fecha  (fecha),
    CONSTRAINT fk_devcompra_compra  FOREIGN KEY (compra_id)  REFERENCES compras (id),
    CONSTRAINT fk_devcompra_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS devolucion_compra_detalle (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    devolucion_compra_id  INT UNSIGNED    NOT NULL,
    compra_detalle_id     BIGINT UNSIGNED NOT NULL,
    producto_id           INT UNSIGNED    NOT NULL,
    -- La tanda que se devuelve, cuando el producto lleva control de
    -- vencimiento. Es lo que permite devolver EL lote vencido y no el que
    -- tocaría por orden de salida.
    lote_id               BIGINT UNSIGNED NULL,
    cantidad              DECIMAL(12,3)   NOT NULL,
    costo_unitario        DECIMAL(12,2)   NOT NULL,
    importe               DECIMAL(12,2) GENERATED ALWAYS AS (ROUND(cantidad * costo_unitario, 2)) STORED,
    PRIMARY KEY (id),
    KEY ix_devcompradet_cabecera (devolucion_compra_id),
    KEY ix_devcompradet_linea    (compra_detalle_id),
    KEY ix_devcompradet_producto (producto_id),
    CONSTRAINT fk_devcompradet_cabecera FOREIGN KEY (devolucion_compra_id) REFERENCES devoluciones_compra (id) ON DELETE CASCADE,
    CONSTRAINT fk_devcompradet_linea    FOREIGN KEY (compra_detalle_id)    REFERENCES compra_detalle (id),
    CONSTRAINT fk_devcompradet_producto FOREIGN KEY (producto_id)          REFERENCES productos (id),
    CONSTRAINT fk_devcompradet_lote     FOREIGN KEY (lote_id)              REFERENCES lotes (id) ON DELETE SET NULL,
    CONSTRAINT ck_devcompradet_cantidad CHECK (cantidad > 0 AND costo_unitario >= 0)
) ENGINE=InnoDB;

-- --------------------------------------------------- 3. el kardex lo reconoce
SET @faltaCol := (
    SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos_inventario'
      AND COLUMN_NAME = 'devolucion_compra_id'
);

SET @sql := IF(@faltaCol, '
    ALTER TABLE movimientos_inventario
        ADD COLUMN devolucion_compra_id INT UNSIGNED NULL
            COMMENT "DEVOLUCION_COMPRA: el documento que la originó"
            AFTER compra_id,
        ADD KEY ix_movinv_devcompra (devolucion_compra_id),
        ADD CONSTRAINT fk_movinv_devcompra FOREIGN KEY (devolucion_compra_id) REFERENCES devoluciones_compra (id)
', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- El CHECK nombra los orígenes uno por uno: hay que soltarlo antes de ampliar
-- el ENUM y volver a ponerlo después, ya con la rama nueva.
SET @sql := IF(@faltaCol, 'ALTER TABLE movimientos_inventario DROP CHECK ck_movinv_origen', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@faltaCol, '
    ALTER TABLE movimientos_inventario
        MODIFY COLUMN origen ENUM(''VENTA'',''COMPRA'',''DEVOLUCION'',''DEVOLUCION_COMPRA'',''ANULACION'',''AJUSTE'',''INICIAL'') NOT NULL
', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@faltaCol, '
    ALTER TABLE movimientos_inventario
        ADD CONSTRAINT ck_movinv_origen CHECK (
            (origen IN (''VENTA'',''ANULACION'')
                 AND venta_id IS NOT NULL AND devolucion_id IS NULL AND proveedor_id IS NULL
                 AND devolucion_compra_id IS NULL)
         OR (origen = ''DEVOLUCION''
                 AND devolucion_id IS NOT NULL AND venta_id IS NULL AND proveedor_id IS NULL
                 AND devolucion_compra_id IS NULL)
         OR (origen = ''COMPRA''
                 AND venta_id IS NULL AND devolucion_id IS NULL AND devolucion_compra_id IS NULL)
         OR (origen = ''DEVOLUCION_COMPRA''
                 AND devolucion_compra_id IS NOT NULL AND venta_id IS NULL AND devolucion_id IS NULL)
         OR (origen IN (''AJUSTE'',''INICIAL'')
                 AND venta_id IS NULL AND devolucion_id IS NULL AND proveedor_id IS NULL
                 AND devolucion_compra_id IS NULL AND documento_externo IS NULL)
        )
', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------- 4. nota sobre la vista v_kardex
-- `v_kardex` gana una rama para el origen nuevo en 01_schema_mysql.sql, pero
-- este parche NO la reemplaza. Dos motivos: la aplicación no la consulta —arma
-- sus propias consultas del kardex— y en el hosting compartido donde corre la
-- demo no existe ninguna vista, porque ahí `CREATE VIEW` está denegado (error
-- 1142). Reemplazarla aquí rompería ese caso a cambio de nada.
--
-- Si esta instalación es de servidor propio y se quiere la vista al día, basta
-- con volver a ejecutar su bloque `CREATE OR REPLACE VIEW v_kardex` desde
-- 01_schema_mysql.sql.
