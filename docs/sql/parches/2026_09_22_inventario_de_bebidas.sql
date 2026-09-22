USE ventas_db;
SET NAMES utf8mb4;

-- =============================================================================
--  Inventario de lo que se compra hecho (2026-09-22)
--
--  Las bebidas embotelladas no se preparan: se compran al proveedor por caja o
--  paquete y se venden por unidad. Para ellas —y solo para ellas: el plato se
--  hace en la casa y no tiene stock— se lleva inventario:
--
--    - `productos` aprende a controlar stock, su empaque de compra, un stock
--      mínimo y el último costo;
--    - proveedores, compras (la factura entera) y devoluciones al proveedor;
--    - el kardex (`movimientos_inventario`), con el stock antes y después;
--    - la toma de inventario: contar la bodega y ajustar la diferencia;
--    - `venta_detalle.costo_unitario`, el costo congelado al vender: con él
--      el reporte del menú calcula la ganancia de lo que tiene costo.
--
--  Lo aplica PHP (App\Services\Inventario), dentro de la misma transacción de
--  la venta, la compra o la toma: así funciona igual con la lógica en la base
--  y en un hosting sin triggers.
--
--  El mismo cambio está en 01_schema_mysql.sql. Idempotente solo en el
--  permiso: el resto se aplica una vez (lo anota aplicar-parches.sh).
-- =============================================================================

ALTER TABLE productos
    ADD COLUMN controla_stock    TINYINT(1)    NOT NULL DEFAULT 0 AFTER afecto_impuesto,
    ADD COLUMN stock_actual      DECIMAL(12,3) NOT NULL DEFAULT 0.000 AFTER controla_stock,
    ADD COLUMN stock_minimo      DECIMAL(12,3) NOT NULL DEFAULT 0.000 AFTER stock_actual,
    ADD COLUMN contenido_empaque DECIMAL(10,3) UNSIGNED NULL AFTER stock_minimo,
    ADD COLUMN nombre_empaque    VARCHAR(20)   NULL AFTER contenido_empaque,
    ADD COLUMN costo             DECIMAL(12,2) NULL AFTER nombre_empaque,
    ADD KEY ix_productos_stock (controla_stock, activo),
    ADD CONSTRAINT ck_productos_stock_minimo CHECK (stock_minimo >= 0),
    ADD CONSTRAINT ck_productos_costo CHECK (costo IS NULL OR costo >= 0),
    ADD CONSTRAINT ck_productos_empaque CHECK (
        (contenido_empaque IS NULL AND nombre_empaque IS NULL)
        OR (contenido_empaque > 1 AND nombre_empaque IS NOT NULL)
    );

ALTER TABLE venta_detalle
    ADD COLUMN costo_unitario DECIMAL(12,2) NULL AFTER precio_unitario;

CREATE TABLE proveedores (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    razon_social    VARCHAR(120) NOT NULL,
    documento       VARCHAR(20)  NULL,
    telefono        VARCHAR(30)  NULL,
    email           VARCHAR(120) NULL,
    direccion       VARCHAR(200) NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_proveedores_documento (documento),
    UNIQUE KEY uq_proveedores_razon (razon_social)
) ENGINE=InnoDB;

CREATE TABLE compras (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    proveedor_id        INT UNSIGNED NOT NULL,
    usuario_id          INT UNSIGNED NOT NULL,
    documento_externo   VARCHAR(30)  NULL,
    fecha               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    observacion         VARCHAR(255) NULL,
    creado_en           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_compras_proveedor (proveedor_id, fecha),
    KEY ix_compras_fecha     (fecha),
    CONSTRAINT fk_compras_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores (id),
    CONSTRAINT fk_compras_usuario   FOREIGN KEY (usuario_id)   REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE compra_detalle (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    compra_id         INT UNSIGNED NOT NULL,
    producto_id       INT UNSIGNED NOT NULL,
    cantidad          DECIMAL(12,3) NOT NULL,
    cantidad_devuelta DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    costo_unitario    DECIMAL(12,2) NOT NULL,
    importe           DECIMAL(12,2) GENERATED ALWAYS AS (ROUND(cantidad * costo_unitario, 2)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_compradet_producto (compra_id, producto_id),
    KEY ix_compradet_producto (producto_id),
    CONSTRAINT fk_compradet_compra   FOREIGN KEY (compra_id)   REFERENCES compras (id),
    CONSTRAINT fk_compradet_producto FOREIGN KEY (producto_id) REFERENCES productos (id),
    CONSTRAINT ck_compradet_cantidad CHECK (cantidad > 0),
    CONSTRAINT ck_compradet_costo    CHECK (costo_unitario >= 0),
    CONSTRAINT ck_compradet_devuelta CHECK (cantidad_devuelta >= 0 AND cantidad_devuelta <= cantidad)
) ENGINE=InnoDB;

CREATE TABLE devoluciones_compra (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    compra_id           INT UNSIGNED NOT NULL,
    usuario_id          INT UNSIGNED NOT NULL,
    fecha               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    motivo              ENUM('DEFECTO','VENCIMIENTO','ERROR','OTRO') NOT NULL,
    espera              ENUM('REPUESTO','PENDIENTE','NOTA_CREDITO') NOT NULL DEFAULT 'NOTA_CREDITO',
    documento_externo   VARCHAR(30)  NULL,
    observacion         VARCHAR(255) NULL,
    creado_en           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_devcompra_compra (compra_id),
    KEY ix_devcompra_espera (espera, fecha),
    CONSTRAINT fk_devcompra_compra  FOREIGN KEY (compra_id)  REFERENCES compras (id),
    CONSTRAINT fk_devcompra_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE devolucion_compra_detalle (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    devolucion_compra_id  INT UNSIGNED    NOT NULL,
    compra_detalle_id     BIGINT UNSIGNED NOT NULL,
    producto_id           INT UNSIGNED    NOT NULL,
    cantidad              DECIMAL(12,3)   NOT NULL,
    cantidad_repuesta     DECIMAL(12,3)   NOT NULL DEFAULT 0.000,
    costo_unitario        DECIMAL(12,2)   NOT NULL,
    importe               DECIMAL(12,2) GENERATED ALWAYS AS (ROUND(cantidad * costo_unitario, 2)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_devcompradet_linea (devolucion_compra_id, compra_detalle_id),
    KEY ix_devcompradet_linea    (compra_detalle_id),
    KEY ix_devcompradet_producto (producto_id),
    CONSTRAINT fk_devcompradet_cabecera FOREIGN KEY (devolucion_compra_id) REFERENCES devoluciones_compra (id),
    CONSTRAINT fk_devcompradet_linea    FOREIGN KEY (compra_detalle_id)    REFERENCES compra_detalle (id),
    CONSTRAINT fk_devcompradet_producto FOREIGN KEY (producto_id)          REFERENCES productos (id),
    CONSTRAINT ck_devcompradet_cantidad CHECK (cantidad > 0 AND costo_unitario >= 0),
    CONSTRAINT ck_devcompradet_repuesta CHECK (cantidad_repuesta >= 0 AND cantidad_repuesta <= cantidad)
) ENGINE=InnoDB;

CREATE TABLE tomas_inventario (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    estado              ENUM('ABIERTA','CERRADA','CANCELADA') NOT NULL DEFAULT 'ABIERTA',
    observacion         VARCHAR(255) NULL,
    usuario_apertura_id INT UNSIGNED NOT NULL,
    fecha_apertura      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    usuario_cierre_id   INT UNSIGNED NULL,
    fecha_cierre        DATETIME     NULL,
    abierta             TINYINT(1) GENERATED ALWAYS AS (IF(estado = 'ABIERTA', 1, NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tomas_una_abierta (abierta),
    KEY ix_tomas_fecha (fecha_apertura),
    CONSTRAINT fk_tomas_apertura FOREIGN KEY (usuario_apertura_id) REFERENCES usuarios (id),
    CONSTRAINT fk_tomas_cierre   FOREIGN KEY (usuario_cierre_id)   REFERENCES usuarios (id),
    CONSTRAINT ck_tomas_cierre CHECK ((estado = 'ABIERTA') = (fecha_cierre IS NULL))
) ENGINE=InnoDB;

CREATE TABLE movimientos_inventario (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    producto_id          INT UNSIGNED NOT NULL,
    usuario_id           INT UNSIGNED NOT NULL,
    tipo                 ENUM('ENTRADA','SALIDA') NOT NULL,
    origen               ENUM('INICIAL','COMPRA','VENTA','ANULACION','AJUSTE','TOMA','DEVOLUCION_COMPRA','REPOSICION') NOT NULL,
    venta_id             BIGINT UNSIGNED NULL,
    compra_id            INT UNSIGNED NULL,
    devolucion_compra_id INT UNSIGNED NULL,
    toma_id              INT UNSIGNED NULL,
    cantidad             DECIMAL(12,3) NOT NULL,
    stock_anterior       DECIMAL(12,3) NOT NULL,
    stock_resultante     DECIMAL(12,3) NOT NULL,
    costo_unitario       DECIMAL(12,2) NULL,
    motivo               VARCHAR(255) NULL,
    fecha                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_movinv_producto (producto_id, fecha),
    KEY ix_movinv_venta    (venta_id),
    KEY ix_movinv_compra   (compra_id),
    KEY ix_movinv_devcompra (devolucion_compra_id),
    KEY ix_movinv_toma     (toma_id),
    KEY ix_movinv_fecha    (fecha),
    CONSTRAINT fk_movinv_producto  FOREIGN KEY (producto_id)          REFERENCES productos (id),
    CONSTRAINT fk_movinv_usuario   FOREIGN KEY (usuario_id)           REFERENCES usuarios (id),
    CONSTRAINT fk_movinv_venta     FOREIGN KEY (venta_id)             REFERENCES ventas (id),
    CONSTRAINT fk_movinv_compra    FOREIGN KEY (compra_id)            REFERENCES compras (id),
    CONSTRAINT fk_movinv_devcompra FOREIGN KEY (devolucion_compra_id) REFERENCES devoluciones_compra (id),
    CONSTRAINT fk_movinv_toma      FOREIGN KEY (toma_id)              REFERENCES tomas_inventario (id),
    CONSTRAINT ck_movinv_cantidad  CHECK (cantidad > 0),
    CONSTRAINT ck_movinv_origen CHECK (
        (origen IN ('VENTA','ANULACION') AND venta_id IS NOT NULL
             AND compra_id IS NULL AND devolucion_compra_id IS NULL AND toma_id IS NULL)
     OR (origen = 'COMPRA' AND compra_id IS NOT NULL
             AND venta_id IS NULL AND devolucion_compra_id IS NULL AND toma_id IS NULL)
     OR (origen IN ('DEVOLUCION_COMPRA','REPOSICION') AND devolucion_compra_id IS NOT NULL
             AND venta_id IS NULL AND compra_id IS NULL AND toma_id IS NULL)
     OR (origen = 'TOMA' AND toma_id IS NOT NULL
             AND venta_id IS NULL AND compra_id IS NULL AND devolucion_compra_id IS NULL)
     OR (origen IN ('INICIAL','AJUSTE')
             AND venta_id IS NULL AND compra_id IS NULL AND devolucion_compra_id IS NULL AND toma_id IS NULL)
    ),
    CONSTRAINT ck_movinv_motivo CHECK (origen <> 'AJUSTE' OR motivo IS NOT NULL)
) ENGINE=InnoDB;

CREATE TABLE toma_inventario_detalle (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    toma_id             INT UNSIGNED NOT NULL,
    producto_id         INT UNSIGNED NOT NULL,
    contado             DECIMAL(12,3) NULL,
    stock_sistema       DECIMAL(12,3) NULL,
    diferencia          DECIMAL(12,3) GENERATED ALWAYS AS (contado - stock_sistema) STORED,
    costo_unitario      DECIMAL(12,2) NULL,
    usuario_id          INT UNSIGNED NULL,
    fecha_conteo        DATETIME     NULL,
    movimiento_id       BIGINT UNSIGNED NULL,
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

-- El permiso, para el Administrador (quien administra usuarios tiene todos).
INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
    ('inventario.gestionar', 'Inventario', 'Proveedores, compras, stock, toma de inventario y devoluciones al proveedor');

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT DISTINCT rp.rol_id, nuevo.id
  FROM rol_permiso rp
  JOIN permisos admin ON admin.id = rp.permiso_id AND admin.codigo = 'usuarios.gestionar'
  JOIN permisos nuevo ON nuevo.codigo = 'inventario.gestionar';
