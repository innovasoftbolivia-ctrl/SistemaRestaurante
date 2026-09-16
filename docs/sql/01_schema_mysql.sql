-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Script 01 - Esquema de base de datos
--  Motor: MySQL 8.0+ / InnoDB / utf8mb4
-- =============================================================================

-- El cliente debe hablar utf8mb4 al cargar este archivo; si no, los acentos
-- entran doblemente codificados ("Diaz" -> "DÃ­az").
SET NAMES utf8mb4;

DROP DATABASE IF EXISTS ventas_db;
CREATE DATABASE ventas_db
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_0900_ai_ci;
USE ventas_db;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
--  1. PERSONAL, SEGURIDAD Y USUARIOS
-- =============================================================================
-- Tres conceptos distintos, tres tablas:
--   cargo     -> qué hace la persona en el negocio (Cajero, Almacenero, Administrador)
--   empleado  -> la persona y su vínculo laboral (ingreso, cese, contrato)
--   usuario   -> la cuenta con la que entra al sistema, y su rol de acceso
-- Un empleado puede no tener usuario (trabaja pero no usa el sistema).
-- Un usuario siempre pertenece a un empleado.

CREATE TABLE cargos (
    id              TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre          VARCHAR(50)  NOT NULL,      -- Cajero, Almacenero, Administrador...
    descripcion     VARCHAR(150) NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cargos_nombre (nombre)
) ENGINE=InnoDB;

CREATE TABLE empleados (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cargo_id            TINYINT UNSIGNED NOT NULL,
    tipo_documento      ENUM('CI','CE','PAS') NOT NULL DEFAULT 'CI',
    documento           VARCHAR(20)  NOT NULL,
    nombres             VARCHAR(60)  NOT NULL,
    apellidos           VARCHAR(60)  NOT NULL,
    fecha_nacimiento    DATE         NULL,
    telefono            VARCHAR(20)  NULL,
    email               VARCHAR(120) NULL,
    direccion           VARCHAR(200) NULL,
    -- vínculo laboral
    fecha_ingreso       DATE         NOT NULL,
    fecha_cese          DATE         NULL,
    motivo_cese         VARCHAR(255) NULL,
    tipo_contrato       ENUM('INDEFINIDO','PLAZO_FIJO','PARCIAL','PRACTICAS') NOT NULL DEFAULT 'INDEFINIDO',
    estado              ENUM('ACTIVO','SUSPENDIDO','CESADO') NOT NULL DEFAULT 'ACTIVO',
    creado_en           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    nombre_completo     VARCHAR(130) GENERATED ALWAYS AS
                        (TRIM(CONCAT_WS(' ', nombres, apellidos))) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_empleados_documento (tipo_documento, documento),
    KEY ix_empleados_cargo  (cargo_id),
    KEY ix_empleados_estado (estado),
    KEY ix_empleados_nombre (nombre_completo),
    CONSTRAINT fk_empleados_cargo FOREIGN KEY (cargo_id) REFERENCES cargos (id),
    -- cesado exige fecha de cese, y una fecha de cese exige estado cesado
    CONSTRAINT ck_empleados_cese CHECK (
        (estado =  'CESADO' AND fecha_cese IS NOT NULL) OR
        (estado <> 'CESADO' AND fecha_cese IS NULL)
    ),
    CONSTRAINT ck_empleados_fechas CHECK (fecha_cese IS NULL OR fecha_cese >= fecha_ingreso)
) ENGINE=InnoDB;

CREATE TABLE roles (
    id              TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre          VARCHAR(40)  NOT NULL,
    descripcion     VARCHAR(150) NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_nombre (nombre)
) ENGINE=InnoDB;

CREATE TABLE permisos (
    id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo          VARCHAR(60)  NOT NULL,   -- ej: ventas.anular
    modulo          VARCHAR(40)  NOT NULL,
    descripcion     VARCHAR(150) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permisos_codigo (codigo)
) ENGINE=InnoDB;

CREATE TABLE rol_permiso (
    rol_id          TINYINT  UNSIGNED NOT NULL,
    permiso_id      SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (rol_id, permiso_id),
    CONSTRAINT fk_rolperm_rol     FOREIGN KEY (rol_id)     REFERENCES roles (id)    ON DELETE CASCADE,
    CONSTRAINT fk_rolperm_permiso FOREIGN KEY (permiso_id) REFERENCES permisos (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- La cuenta de acceso. Los datos de la persona están en `empleados`; aquí solo
-- vive lo que tiene que ver con entrar al sistema. `activo` es el acceso, no el
-- vínculo laboral: eso es `empleados.estado`.
CREATE TABLE usuarios (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    empleado_id         INT UNSIGNED NOT NULL,
    rol_id              TINYINT UNSIGNED NOT NULL,
    usuario             VARCHAR(40)  NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,
    password_actualizado_en DATETIME NULL,
    -- 1 = la contraseña la puso otro (la instalación o un administrador): se
    -- pide cambiarla al entrar, antes de hacer cualquier otra cosa.
    debe_cambiar_password TINYINT(1) NOT NULL DEFAULT 0,
    activo              TINYINT(1)   NOT NULL DEFAULT 1,
    ultimo_acceso       DATETIME     NULL,
    intentos_fallidos   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    creado_en           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_usuario  (usuario),
    UNIQUE KEY uq_usuarios_empleado (empleado_id),   -- una sola cuenta por empleado
    KEY ix_usuarios_rol (rol_id),
    CONSTRAINT fk_usuarios_empleado FOREIGN KEY (empleado_id) REFERENCES empleados (id),
    CONSTRAINT fk_usuarios_rol      FOREIGN KEY (rol_id)      REFERENCES roles (id)
) ENGINE=InnoDB;

-- =============================================================================
--  2. CATÁLOGO
-- =============================================================================

CREATE TABLE categorias (
    id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre          VARCHAR(60)  NOT NULL,
    descripcion     VARCHAR(200) NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categorias_nombre (nombre)
) ENGINE=InnoDB;

CREATE TABLE unidades_medida (
    id              TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo          VARCHAR(10) NOT NULL,   -- UND, KG, LT, CAJA
    nombre          VARCHAR(40) NOT NULL,
    permite_decimal TINYINT(1)  NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_unidades_codigo (codigo)
) ENGINE=InnoDB;

CREATE TABLE proveedores (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    razon_social    VARCHAR(120) NOT NULL,
    documento       VARCHAR(20)  NULL,
    telefono        VARCHAR(20)  NULL,
    email           VARCHAR(120) NULL,
    direccion       VARCHAR(200) NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_proveedores_documento (documento),
    KEY ix_proveedores_razon (razon_social)
) ENGINE=InnoDB;

CREATE TABLE productos (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    categoria_id        SMALLINT UNSIGNED NOT NULL,
    unidad_medida_id    TINYINT  UNSIGNED NOT NULL,       -- la unidad en la que se VENDE
    -- El empaque en el que llega del proveedor, cuando llega en empaque.
    -- `stock_actual` se cuenta SIEMPRE en la unidad de venta: esto no es una
    -- segunda unidad de stock, es cuántas unidades trae una caja para poder
    -- ingresar «3 cajas y 5 sueltas» sin sacar la calculadora.
    -- Decimal y no entero: sirve tanto para «la caja trae 24 gaseosas» como
    -- para «el galón trae 3.785 litros». Con un entero, quien compra por galón
    -- tendría que redondear, y el redondeo se le iría derecho al stock.
    contenido_empaque   DECIMAL(10,3) UNSIGNED NULL,      -- 24 = la caja trae 24 unidades
    nombre_empaque      VARCHAR(20)  NULL,                -- Caja, Paquete, Plancha…
    -- 1 = el stock de este producto se lleva por lotes con fecha (ver `lotes`).
    -- Se decide producto por producto: el detergente no vence, y pedirle una
    -- fecha cada vez que llega es la forma más rápida de que alguien escriba
    -- cualquier cosa con tal de seguir.
    controla_vencimiento TINYINT(1)  NOT NULL DEFAULT 0,
    proveedor_id        INT UNSIGNED NULL,
    codigo              VARCHAR(30)  NOT NULL,          -- código interno / SKU
    codigo_barras       VARCHAR(50)  NULL,
    nombre              VARCHAR(120) NOT NULL,
    descripcion         VARCHAR(255) NULL,
    precio_compra       DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- costo, sin impuesto
    precio_venta        DECIMAL(12,2) NOT NULL,                -- precio SIN impuesto (base imponible)
    afecto_impuesto     TINYINT(1)   NOT NULL DEFAULT 1,       -- 1 = se le agrega el impuesto al vender
    stock_actual        DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    stock_minimo        DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    imagen              VARCHAR(255) NULL,
    activo              TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_productos_codigo  (codigo),
    UNIQUE KEY uq_productos_barras  (codigo_barras),
    KEY ix_productos_categoria (categoria_id),
    KEY ix_productos_proveedor (proveedor_id),
    KEY ix_productos_nombre    (nombre),
    KEY ix_productos_activo    (activo),
    CONSTRAINT fk_productos_categoria FOREIGN KEY (categoria_id)     REFERENCES categorias (id),
    CONSTRAINT fk_productos_unidad    FOREIGN KEY (unidad_medida_id) REFERENCES unidades_medida (id),
    CONSTRAINT fk_productos_proveedor FOREIGN KEY (proveedor_id)     REFERENCES proveedores (id) ON DELETE SET NULL,
    CONSTRAINT ck_productos_precios   CHECK (precio_venta >= 0 AND precio_compra >= 0),
    CONSTRAINT ck_productos_stock     CHECK (stock_actual >= 0 AND stock_minimo >= 0),
    -- O el producto no viene en empaque (las dos columnas en NULL), o viene en
    -- uno con nombre y con más de una unidad dentro. Un empaque de 1 no ahorra
    -- ninguna cuenta: solo ensucia la pantalla de ingreso.
    CONSTRAINT ck_productos_empaque   CHECK (
        (contenido_empaque IS NULL AND nombre_empaque IS NULL)
        OR (contenido_empaque > 1 AND nombre_empaque IS NOT NULL)
    )
) ENGINE=InnoDB;

-- =============================================================================
--  3. CLIENTES
-- =============================================================================

-- Un solo maestro de clientes con discriminador `tipo_persona`:
--   NATURAL  -> se identifica con nombres + apellidos y CI/CE/PAS. Se le emite RECIBO.
--   JURIDICA -> se identifica con razón social y NIT. Se le emite FACTURA.
-- Las columnas propias de cada tipo son nulas para el otro y los CHECK garantizan
-- que un cliente nunca quede a medio llenar.
CREATE TABLE clientes (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo_persona        ENUM('NATURAL','JURIDICA') NOT NULL DEFAULT 'NATURAL',
    tipo_documento      ENUM('CI','CE','PAS','NIT','SIN') NOT NULL DEFAULT 'CI',
    documento           VARCHAR(20)  NULL,
    -- persona natural
    nombres             VARCHAR(60)  NULL,
    apellidos           VARCHAR(60)  NULL,
    fecha_nacimiento    DATE         NULL,
    -- persona jurídica
    razon_social        VARCHAR(150) NULL,
    nombre_comercial    VARCHAR(120) NULL,
    representante_legal VARCHAR(120) NULL,
    -- comunes
    direccion           VARCHAR(200) NULL,   -- dirección fiscal, obligatoria para factura
    telefono            VARCHAR(20)  NULL,
    email               VARCHAR(120) NULL,
    activo              TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- nombre unificado para búsquedas, listados y comprobantes
    nombre              VARCHAR(150) GENERATED ALWAYS AS (
                            IF(tipo_persona = 'JURIDICA',
                               razon_social,
                               TRIM(CONCAT_WS(' ', nombres, apellidos)))
                        ) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_clientes_documento (tipo_documento, documento),
    KEY ix_clientes_nombre  (nombre),
    KEY ix_clientes_persona (tipo_persona),
    -- Una persona natural puede tener NIT (unipersonal, profesional
    -- independiente): con él recibe factura a su nombre.
    CONSTRAINT ck_clientes_natural CHECK (
        tipo_persona <> 'NATURAL' OR (
            nombres   IS NOT NULL AND
            apellidos IS NOT NULL AND
            razon_social IS NULL  AND
            tipo_documento IN ('CI','CE','PAS','SIN','NIT') AND
            (tipo_documento <> 'NIT' OR documento IS NOT NULL)
        )
    ),
    CONSTRAINT ck_clientes_juridica CHECK (
        tipo_persona <> 'JURIDICA' OR (
            razon_social IS NOT NULL AND
            documento    IS NOT NULL AND
            direccion    IS NOT NULL AND
            nombres      IS NULL     AND
            apellidos    IS NULL     AND
            tipo_documento = 'NIT'
        )
    )
) ENGINE=InnoDB;

-- =============================================================================
--  4. CAJA Y TURNOS
-- =============================================================================

CREATE TABLE cajas (
    id              TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre          VARCHAR(40) NOT NULL,       -- "Caja 1"
    ubicacion       VARCHAR(60) NULL,
    activo          TINYINT(1)  NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cajas_nombre (nombre)
) ENGINE=InnoDB;

CREATE TABLE sesiones_caja (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    caja_id             TINYINT UNSIGNED NOT NULL,
    usuario_apertura_id INT UNSIGNED NOT NULL,
    usuario_cierre_id   INT UNSIGNED NULL,
    fecha_apertura      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_cierre        DATETIME     NULL,
    monto_inicial       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    monto_esperado      DECIMAL(12,2) NULL,     -- calculado al cerrar
    monto_declarado     DECIMAL(12,2) NULL,     -- efectivo contado
    fondo_dejado        DECIMAL(12,2) NULL,     -- lo que queda en el cajón para el siguiente turno
    -- derivada de las dos anteriores: columna generada, no se puede desincronizar (3FN)
    diferencia          DECIMAL(12,2) GENERATED ALWAYS AS
                        (ROUND(monto_declarado - monto_esperado, 2)) STORED,
    estado              ENUM('ABIERTA','CERRADA') NOT NULL DEFAULT 'ABIERTA',
    observacion         VARCHAR(255) NULL,      -- al abrir
    observacion_cierre  VARCHAR(255) NULL,      -- al cerrar: explica la diferencia
    PRIMARY KEY (id),
    KEY ix_sesiones_caja      (caja_id, estado),
    KEY ix_sesiones_usuario   (usuario_apertura_id),
    KEY ix_sesiones_fecha     (fecha_apertura),
    CONSTRAINT fk_sesion_caja      FOREIGN KEY (caja_id)             REFERENCES cajas (id),
    CONSTRAINT fk_sesion_usr_ap    FOREIGN KEY (usuario_apertura_id) REFERENCES usuarios (id),
    CONSTRAINT fk_sesion_usr_ci    FOREIGN KEY (usuario_cierre_id)   REFERENCES usuarios (id),
    CONSTRAINT ck_sesion_inicial   CHECK (monto_inicial >= 0)
) ENGINE=InnoDB;

-- Solo una sesión ABIERTA por caja: se garantiza con esta columna generada + índice único.
ALTER TABLE sesiones_caja
    ADD COLUMN caja_abierta_uk TINYINT UNSIGNED
        GENERATED ALWAYS AS (IF(estado = 'ABIERTA', caja_id, NULL)) VIRTUAL,
    ADD UNIQUE KEY uq_sesion_caja_abierta (caja_abierta_uk);

-- Y solo una sesión ABIERTA por usuario: sin esto, `Cajas::abrir()` solo se
-- protegía con un SELECT-antes-de-INSERT en PHP (una condición de carrera
-- real bajo dos peticiones simultáneas), y un mismo cajero podía terminar con
-- dos turnos abiertos en dos cajas físicas a la vez. `Cajas::sesionDe()` usa
-- `->first()` sin criterio de desempate, así que con dos sesiones abiertas
-- todas las ventas del cajero se atribuían siempre a una sola de las dos, de
-- forma no determinista, mientras el efectivo real quedaba repartido entre
-- dos cajones — el arqueo del cierre no cuadraba por un motivo que no era
-- culpa del cajero.
ALTER TABLE sesiones_caja
    ADD COLUMN usuario_abierta_uk INT UNSIGNED
        GENERATED ALWAYS AS (IF(estado = 'ABIERTA', usuario_apertura_id, NULL)) VIRTUAL,
    ADD UNIQUE KEY uq_sesion_usuario_abierta (usuario_abierta_uk);

CREATE TABLE movimientos_caja (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sesion_caja_id  INT UNSIGNED NOT NULL,
    usuario_id      INT UNSIGNED NOT NULL,
    tipo            ENUM('INGRESO','EGRESO') NOT NULL,
    concepto        VARCHAR(120)  NOT NULL,
    monto           DECIMAL(12,2) NOT NULL,
    fecha           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_movcaja_sesion (sesion_caja_id),
    CONSTRAINT fk_movcaja_sesion  FOREIGN KEY (sesion_caja_id) REFERENCES sesiones_caja (id),
    CONSTRAINT fk_movcaja_usuario FOREIGN KEY (usuario_id)     REFERENCES usuarios (id),
    CONSTRAINT ck_movcaja_monto   CHECK (monto > 0)
) ENGINE=InnoDB;

-- =============================================================================
--  5. COMPROBANTES Y MÉTODOS DE PAGO
-- =============================================================================

-- Define qué documento se emite y a qué tipo de cliente corresponde:
--   FAC (Factura) -> solo persona JURIDICA, exige cliente con NIT y dirección fiscal
--   REC (Recibo)  -> solo persona NATURAL
--   NV  (Nota de venta, uso interno) -> AMBAS, sin exigencia de cliente
CREATE TABLE tipos_comprobante (
    id              TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo          VARCHAR(10) NOT NULL,   -- FAC, REC, NV
    nombre          VARCHAR(40) NOT NULL,
    aplica_persona  ENUM('NATURAL','JURIDICA','AMBAS') NOT NULL DEFAULT 'AMBAS',
    exige_cliente   TINYINT(1)  NOT NULL DEFAULT 0,
    exige_documento TINYINT(1)  NOT NULL DEFAULT 0,
    activo          TINYINT(1)  NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tipocomp_codigo (codigo)
) ENGINE=InnoDB;

CREATE TABLE series_comprobante (
    id                  SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo_comprobante_id TINYINT UNSIGNED NOT NULL,
    serie               VARCHAR(6)  NOT NULL,       -- B001, F001
    correlativo_actual  INT UNSIGNED NOT NULL DEFAULT 0,
    longitud            TINYINT UNSIGNED NOT NULL DEFAULT 6,
    activo              TINYINT(1)  NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_series (tipo_comprobante_id, serie),
    CONSTRAINT fk_series_tipo FOREIGN KEY (tipo_comprobante_id) REFERENCES tipos_comprobante (id)
) ENGINE=InnoDB;

CREATE TABLE metodos_pago (
    id              TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo          VARCHAR(15) NOT NULL,   -- EFECTIVO, TARJETA, YAPE...
    nombre          VARCHAR(40) NOT NULL,
    afecta_caja     TINYINT(1)  NOT NULL DEFAULT 1,  -- 1 = suma al efectivo esperado
    activo          TINYINT(1)  NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_metpago_codigo (codigo)
) ENGINE=InnoDB;

-- =============================================================================
--  6. VENTAS
-- =============================================================================

-- `ventas` guarda la operación comercial. El documento entregado al cliente
-- (factura o recibo) vive en la tabla `comprobantes`, relación 1 a 1.
CREATE TABLE ventas (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cliente_id          INT UNSIGNED NULL,          -- NULL = cliente varios
    usuario_id          INT UNSIGNED NOT NULL,      -- cajero que vendió
    sesion_caja_id      INT UNSIGNED NOT NULL,
    fecha               DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- En los dos modos de precio, las columnas guardan lo mismo:
    --   subtotal = base imponible (suma del detalle, sin impuesto)
    --   descuento = la parte del descuento que baja la base
    --   total    = subtotal - descuento + impuesto
    -- Con `impuesto_incluido` = 1 el precio de cada línea ya trae el impuesto y
    -- el cliente ve el descuento sobre el precio final (`descuento_precio_final`):
    -- sp_recalcular_venta reparte ese descuento entre base e impuesto.
    subtotal            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    descuento           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    impuesto            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    impuesto_incluido   TINYINT(1)    NOT NULL DEFAULT 0,
    descuento_precio_final DECIMAL(12,2) NULL,
    -- derivada de las tres anteriores: columna generada (3FN)
    total               DECIMAL(12,2) GENERATED ALWAYS AS
                        (ROUND(subtotal - descuento + impuesto, 2)) STORED,
    total_devuelto      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    estado              ENUM('COMPLETADA','ANULADA','DEVUELTA_PARCIAL','DEVUELTA') NOT NULL DEFAULT 'COMPLETADA',
    observacion         VARCHAR(255) NULL,
    -- anulación
    anulada_en          DATETIME     NULL,
    anulada_por         INT UNSIGNED NULL,
    motivo_anulacion    VARCHAR(255) NULL,
    creado_en           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_ventas_fecha    (fecha),
    KEY ix_ventas_cliente  (cliente_id),
    KEY ix_ventas_usuario  (usuario_id),
    KEY ix_ventas_sesion   (sesion_caja_id),
    KEY ix_ventas_estado   (estado, fecha),
    CONSTRAINT fk_ventas_cliente FOREIGN KEY (cliente_id)     REFERENCES clientes (id),
    CONSTRAINT fk_ventas_usuario FOREIGN KEY (usuario_id)     REFERENCES usuarios (id),
    CONSTRAINT fk_ventas_sesion  FOREIGN KEY (sesion_caja_id) REFERENCES sesiones_caja (id),
    CONSTRAINT fk_ventas_anulada FOREIGN KEY (anulada_por)    REFERENCES usuarios (id),
    CONSTRAINT ck_ventas_montos  CHECK (subtotal >= 0 AND descuento >= 0 AND impuesto >= 0
                                        AND descuento <= subtotal)
) ENGINE=InnoDB;

CREATE TABLE venta_detalle (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    venta_id            BIGINT UNSIGNED NOT NULL,
    producto_id         INT UNSIGNED NOT NULL,
    descripcion         VARCHAR(120)  NOT NULL,      -- copia histórica del nombre
    unidad              VARCHAR(10)   NULL,          -- copia histórica de la unidad: UND, KG, LT
    cantidad            DECIMAL(12,3) NOT NULL,
    precio_unitario     DECIMAL(12,2) NOT NULL,      -- copia histórica del precio
    descuento           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    -- Costo del producto el día de la venta: la ganancia de un mes pasado no
    -- cambia porque el proveedor subió el precio después. NULL solo en ventas
    -- anteriores al 15/09/2026 que no se pudieron completar.
    costo_unitario      DECIMAL(12,2) NULL,
    -- Base de la línea, sin impuesto. Con el impuesto incluido en el precio,
    -- es lo cobrado menos el impuesto que lleva adentro.
    importe             DECIMAL(12,2) GENERATED ALWAYS AS
                        (ROUND(cantidad * precio_unitario - descuento, 2) - IF(impuesto_incluido = 1, ROUND(ROUND(cantidad * precio_unitario - descuento, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0) / (1 + IF(afecto_impuesto = 1, tasa_impuesto, 0)), 2), 0)) STORED,
    -- Desglose de impuesto por línea: es lo que imprime la FACTURA.
    -- Se copian del producto, de la configuración y de la venta al insertar
    -- (ver trigger BEFORE INSERT).
    afecto_impuesto     TINYINT(1)    NOT NULL DEFAULT 1,
    tasa_impuesto       DECIMAL(6,4)  NOT NULL DEFAULT 0.0000,
    -- 1 = el precio unitario ya incluye el impuesto (se calcula «por dentro»).
    impuesto_incluido   TINYINT(1)    NOT NULL DEFAULT 0,
    impuesto_linea      DECIMAL(12,2) GENERATED ALWAYS AS
                        (IF(impuesto_incluido = 1, ROUND(ROUND(cantidad * precio_unitario - descuento, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0) / (1 + IF(afecto_impuesto = 1, tasa_impuesto, 0)), 2), ROUND(ROUND(cantidad * precio_unitario - descuento, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0), 2))) STORED,
    -- Lo que paga el cliente por la línea.
    total_linea         DECIMAL(12,2) GENERATED ALWAYS AS
                        (ROUND(cantidad * precio_unitario - descuento, 2) + IF(impuesto_incluido = 1, 0, ROUND(ROUND(cantidad * precio_unitario - descuento, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0), 2))) STORED,
    cantidad_devuelta   DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    PRIMARY KEY (id),
    -- Un producto, una sola línea por venta. El mostrador ya lo exigía, pero la
    -- regla vivía solo en la validación HTTP: con dos líneas del mismo producto,
    -- `sp_anular_venta` reponía el stock de una sola de ellas.
    UNIQUE KEY uq_detalle_venta_producto (venta_id, producto_id),
    KEY ix_detalle_venta    (venta_id),
    KEY ix_detalle_producto (producto_id),
    CONSTRAINT fk_detalle_venta    FOREIGN KEY (venta_id)    REFERENCES ventas (id) ON DELETE CASCADE,
    CONSTRAINT fk_detalle_producto FOREIGN KEY (producto_id) REFERENCES productos (id),
    CONSTRAINT ck_detalle_cantidad CHECK (cantidad > 0),
    CONSTRAINT ck_detalle_precio   CHECK (precio_unitario >= 0 AND descuento >= 0),
    CONSTRAINT ck_detalle_devuelta CHECK (cantidad_devuelta >= 0 AND cantidad_devuelta <= cantidad)
) ENGINE=InnoDB;

CREATE TABLE venta_pagos (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    venta_id        BIGINT UNSIGNED NOT NULL,
    metodo_pago_id  TINYINT UNSIGNED NOT NULL,
    monto           DECIMAL(12,2) NOT NULL,   -- lo que se aplica a la venta
    monto_recibido  DECIMAL(12,2) NULL,       -- lo que entregó el cliente (solo efectivo)
    -- derivada de las dos anteriores: columna generada (3FN)
    vuelto          DECIMAL(12,2) GENERATED ALWAYS AS
                    (IF(monto_recibido IS NULL, 0.00, ROUND(monto_recibido - monto, 2))) STORED,
    referencia      VARCHAR(60)   NULL,       -- nro de operación / voucher
    PRIMARY KEY (id),
    KEY ix_pagos_venta  (venta_id),
    KEY ix_pagos_metodo (metodo_pago_id),
    CONSTRAINT fk_pagos_venta  FOREIGN KEY (venta_id)       REFERENCES ventas (id) ON DELETE CASCADE,
    CONSTRAINT fk_pagos_metodo FOREIGN KEY (metodo_pago_id) REFERENCES metodos_pago (id),
    CONSTRAINT ck_pagos_monto  CHECK (monto > 0
                                      AND (monto_recibido IS NULL OR monto_recibido >= monto))
) ENGINE=InnoDB;

-- =============================================================================
--  6.a.bis COBROS POR QR
--
--  Un cobro con el importe ya puesto, para que el cliente escanee y pague
--  exactamente lo que debe.
--
--  `venta_id` es NULL hasta que el pago se confirma, y es deliberado: el QR se
--  genera contra el CARRITO, antes de que la venta exista. Si colgara de la
--  venta habría que registrarla primero, y una venta creada antes de cobrar ya
--  descontó stock y ya emitió comprobante; si el cliente entonces no paga,
--  queda una venta fantasma con mercadería que nadie se llevó.
--
--  `confirmado_por` distingue si lo dio por pagado la pasarela o una persona.
--  Confirmar a mano es legítimo —la API del banco se cae— pero tiene que
--  quedar con nombre: es el punto por donde se colaría un cobro que no entró.
-- =============================================================================

CREATE TABLE cobros_qr (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    sesion_caja_id      INT UNSIGNED    NOT NULL,
    usuario_id          INT UNSIGNED    NOT NULL,
    venta_id            BIGINT UNSIGNED NULL,        -- BIGINT: `ventas.id` lo es

    monto               DECIMAL(12,2)   NOT NULL,
    moneda              CHAR(3)         NOT NULL DEFAULT 'BOB',
    glosa               VARCHAR(120)    NULL,

    pasarela            VARCHAR(30)     NOT NULL,    -- 'simulado' o el código del banco
    id_externo          VARCHAR(80)     NULL,        -- el identificador que devuelve el banco
    payload             MEDIUMTEXT      NULL,        -- lo que se codifica en el QR, o la imagen del banco (data URL)

    estado              ENUM('PENDIENTE','PAGADO','EXPIRADO','ANULADO')
                        NOT NULL DEFAULT 'PENDIENTE',

    expira_en           DATETIME        NULL,
    pagado_en           DATETIME        NULL,
    confirmado_por      ENUM('PASARELA','MANUAL') NULL,
    confirmado_por_id   INT UNSIGNED    NULL,
    referencia_bancaria VARCHAR(80)     NULL,
    respuesta           TEXT            NULL,

    creado_en           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_cobro_externo (pasarela, id_externo),
    KEY ix_cobros_qr_estado (estado),
    KEY ix_cobros_qr_sesion (sesion_caja_id),
    KEY ix_cobros_qr_venta  (venta_id),

    CONSTRAINT fk_cobros_qr_sesion  FOREIGN KEY (sesion_caja_id)    REFERENCES sesiones_caja (id),
    CONSTRAINT fk_cobros_qr_usuario FOREIGN KEY (usuario_id)        REFERENCES usuarios (id),
    CONSTRAINT fk_cobros_qr_venta   FOREIGN KEY (venta_id)          REFERENCES ventas (id),
    CONSTRAINT fk_cobros_qr_conf    FOREIGN KEY (confirmado_por_id) REFERENCES usuarios (id),

    CONSTRAINT ck_cobros_qr_monto CHECK (monto > 0),
    CONSTRAINT ck_cobros_qr_pagado CHECK (
        (estado = 'PAGADO'  AND pagado_en IS NOT NULL AND confirmado_por IS NOT NULL)
     OR (estado <> 'PAGADO' AND pagado_en IS NULL)
    )
) ENGINE=InnoDB;

-- =============================================================================
--  6.b COMPROBANTES EMITIDOS (FACTURA / RECIBO)
-- =============================================================================
-- Apartado donde se guarda el documento entregado al cliente. Una venta tiene como
-- máximo UN comprobante VIGENTE, y el tipo depende del cliente
-- (persona jurídica -> FACTURA, persona natural -> RECIBO).
--
-- Un documento puede ser sustituido por otro (típicamente: se entregó recibo y el
-- cliente después pide factura). El sustituido queda con estado SUSTITUIDO y el nuevo
-- lo referencia en `sustituye_a`: nada se borra y la cadena queda auditable.
--
-- Guarda una FOTO de los datos al momento de emitir (nombre, documento, dirección
-- fiscal e importes). Si el cliente después cambia de razón social o de dirección,
-- el documento ya emitido no se altera: es un requisito contable, no una redundancia.

-- Nota de normalización: NO se guarda `tipo_comprobante_id`. La serie ya determina el
-- tipo (`series_comprobante.tipo_comprobante_id`), así que tenerlo aquí sería una
-- dependencia transitiva (3FN) y dos fuentes de verdad que pueden contradecirse.
-- El tipo se obtiene con un JOIN a `series_comprobante`, que es una tabla diminuta.

CREATE TABLE comprobantes (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    venta_id                BIGINT UNSIGNED NOT NULL,
    serie_id                SMALLINT UNSIGNED NOT NULL,
    numero                  INT UNSIGNED NOT NULL,
    numero_completo         VARCHAR(20)  NOT NULL,      -- "F001-000126" / "R001-000341"
    fecha_emision           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- foto de los datos del cliente al emitir
    cliente_id              INT UNSIGNED NULL,
    tipo_persona            ENUM('NATURAL','JURIDICA') NULL,
    cliente_nombre          VARCHAR(150) NOT NULL,      -- razón social o nombre completo
    cliente_tipo_documento  ENUM('CI','CE','PAS','NIT','SIN') NOT NULL DEFAULT 'SIN',
    cliente_documento       VARCHAR(20)  NULL,
    cliente_direccion       VARCHAR(200) NULL,          -- dirección fiscal (factura)
    representante_legal     VARCHAR(120) NULL,          -- solo persona jurídica
    -- foto de los importes
    subtotal                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    descuento               DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    impuesto                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total                   DECIMAL(12,2) GENERATED ALWAYS AS
                            (ROUND(subtotal - descuento + impuesto, 2)) STORED,
    moneda                  VARCHAR(3)   NOT NULL DEFAULT 'BOB',
    -- estado del documento
    estado                  ENUM('EMITIDO','ANULADO','SUSTITUIDO') NOT NULL DEFAULT 'EMITIDO',
    anulado_en              DATETIME     NULL,
    motivo_anulacion        VARCHAR(255) NULL,
    -- sustitución (recibo -> factura)
    sustituye_a             BIGINT UNSIGNED NULL,       -- documento al que reemplaza
    sustituido_en           DATETIME     NULL,          -- cuándo dejó de ser el vigente
    motivo_emision          VARCHAR(255) NULL,          -- por qué se emitió este reemplazo
    emitido_por             INT UNSIGNED NULL,          -- usuario que emitió
    archivo_pdf             VARCHAR(255) NULL,          -- ruta del PDF generado
    observacion             VARCHAR(255) NULL,
    creado_en               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Solo un comprobante VIGENTE (EMITIDO) por venta. Los sustituidos y los anulados
    -- quedan en la tabla como historial y no ocupan el lugar del vigente.
    venta_vigente_uk        BIGINT UNSIGNED
                            GENERATED ALWAYS AS (IF(estado = 'EMITIDO', venta_id, NULL)) VIRTUAL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_comprobante_vigente (venta_vigente_uk),
    UNIQUE KEY uq_comprobante_numero  (serie_id, numero),   -- sin duplicados de correlativo
    KEY ix_comprobante_venta     (venta_id),
    KEY ix_comprobante_serie     (serie_id, fecha_emision),
    KEY ix_comprobante_cliente   (cliente_id),
    KEY ix_comprobante_documento (cliente_documento),
    KEY ix_comprobante_fecha     (fecha_emision),
    KEY ix_comprobante_sustituye (sustituye_a),
    CONSTRAINT fk_comprobante_venta   FOREIGN KEY (venta_id)            REFERENCES ventas (id),
    CONSTRAINT fk_comprobante_serie   FOREIGN KEY (serie_id)            REFERENCES series_comprobante (id),
    CONSTRAINT fk_comprobante_cliente FOREIGN KEY (cliente_id)          REFERENCES clientes (id),
    CONSTRAINT fk_comprobante_sustit  FOREIGN KEY (sustituye_a)         REFERENCES comprobantes (id),
    CONSTRAINT fk_comprobante_usuario FOREIGN KEY (emitido_por)         REFERENCES usuarios (id),
    CONSTRAINT ck_comprobante_montos  CHECK (subtotal >= 0 AND descuento >= 0 AND impuesto >= 0
                                             AND descuento <= subtotal)
) ENGINE=InnoDB;

-- =============================================================================
--  7. DEVOLUCIONES
-- =============================================================================

CREATE TABLE devoluciones (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    venta_id            BIGINT UNSIGNED NOT NULL,
    usuario_id          INT UNSIGNED NOT NULL,      -- quien registra
    autorizado_por      INT UNSIGNED NULL,          -- administrador que autoriza
    sesion_caja_id      INT UNSIGNED NULL,
    fecha               DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tipo                ENUM('TOTAL','PARCIAL') NOT NULL,
    motivo              VARCHAR(255)  NOT NULL,
    total               DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    -- Cómo se le devolvió la plata al cliente y cuánto salió del cajón. En un
    -- minimarket lo pagado por QR o billetera casi siempre se devuelve en
    -- efectivo: sin esto el arqueo solo restaba la parte cobrada en efectivo y
    -- el cajero cerraba con faltante. NULL en las anteriores al 14/09/2026.
    reembolso           ENUM('EFECTIVO','MISMO_MEDIO') NULL,
    efectivo            DECIMAL(12,2) NULL,
    PRIMARY KEY (id),
    KEY ix_devol_venta (venta_id),
    KEY ix_devol_fecha (fecha),
    CONSTRAINT fk_devol_venta   FOREIGN KEY (venta_id)       REFERENCES ventas (id),
    CONSTRAINT fk_devol_usuario FOREIGN KEY (usuario_id)     REFERENCES usuarios (id),
    CONSTRAINT fk_devol_autoriz FOREIGN KEY (autorizado_por) REFERENCES usuarios (id),
    CONSTRAINT fk_devol_sesion  FOREIGN KEY (sesion_caja_id) REFERENCES sesiones_caja (id),
    CONSTRAINT ck_devol_efectivo CHECK (efectivo IS NULL OR (efectivo >= 0 AND efectivo <= total))
) ENGINE=InnoDB;

-- Se devuelve lo que el cliente pagó, impuesto incluido. Por eso la línea
-- lleva el mismo desglose que `venta_detalle`: `precio_unitario` es la base,
-- e `impuesto_linea` y `total_linea` salen de la tasa que quedó congelada en
-- la venta original y que copia el trigger BEFORE INSERT.
CREATE TABLE devolucion_detalle (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    devolucion_id       BIGINT UNSIGNED NOT NULL,
    venta_detalle_id    BIGINT UNSIGNED NOT NULL,
    producto_id         INT UNSIGNED NOT NULL,
    cantidad            DECIMAL(12,3) NOT NULL,
    -- El precio que pagó el cliente por unidad: sin impuesto si la venta lo
    -- sumaba encima, CON impuesto adentro si el precio ya lo incluía. El modo
    -- se copia de la línea de venta (ver trigger BEFORE INSERT), igual que la
    -- tasa: así se devuelve exactamente lo cobrado, sin inflarlo un 13 %.
    precio_unitario     DECIMAL(12,2) NOT NULL,
    importe             DECIMAL(12,2) GENERATED ALWAYS AS
                        (ROUND(cantidad * precio_unitario, 2) - IF(impuesto_incluido = 1, ROUND(ROUND(cantidad * precio_unitario, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0) / (1 + IF(afecto_impuesto = 1, tasa_impuesto, 0)), 2), 0)) STORED,
    afecto_impuesto     TINYINT(1)    NOT NULL DEFAULT 1,
    tasa_impuesto       DECIMAL(6,4)  NOT NULL DEFAULT 0.0000,
    impuesto_incluido   TINYINT(1)    NOT NULL DEFAULT 0,
    impuesto_linea      DECIMAL(12,2) GENERATED ALWAYS AS
                        (IF(impuesto_incluido = 1, ROUND(ROUND(cantidad * precio_unitario, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0) / (1 + IF(afecto_impuesto = 1, tasa_impuesto, 0)), 2), ROUND(ROUND(cantidad * precio_unitario, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0), 2))) STORED,
    total_linea         DECIMAL(12,2) GENERATED ALWAYS AS
                        (ROUND(cantidad * precio_unitario, 2) + IF(impuesto_incluido = 1, 0, ROUND(ROUND(cantidad * precio_unitario, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0), 2))) STORED,
    reingresa_stock     TINYINT(1) NOT NULL DEFAULT 1,   -- 0 si el producto vino dañado
    PRIMARY KEY (id),
    KEY ix_devdet_devolucion (devolucion_id),
    KEY ix_devdet_vdetalle   (venta_detalle_id),
    CONSTRAINT fk_devdet_devolucion FOREIGN KEY (devolucion_id)    REFERENCES devoluciones (id) ON DELETE CASCADE,
    CONSTRAINT fk_devdet_vdetalle   FOREIGN KEY (venta_detalle_id) REFERENCES venta_detalle (id),
    CONSTRAINT fk_devdet_producto   FOREIGN KEY (producto_id)      REFERENCES productos (id),
    CONSTRAINT ck_devdet_cantidad   CHECK (cantidad > 0)
) ENGINE=InnoDB;

-- =============================================================================
--  8. INVENTARIO (KARDEX)
-- =============================================================================

-- Cabecera de una compra a proveedor (una guía o factura, con varias líneas
-- de producto). `proveedor_id` y `documento_externo` viven aquí, no en cada
-- línea de kardex: son datos de LA COMPRA, no de cada movimiento (2FN). Antes
-- de esta tabla, una compra de 5 productos repetía esos dos datos 5 veces en
-- `movimientos_inventario`.
CREATE TABLE compras (
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

CREATE TABLE compra_detalle (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    compra_id       INT UNSIGNED NOT NULL,
    producto_id     INT UNSIGNED NOT NULL,
    cantidad        DECIMAL(12,3) NOT NULL,
    -- Lo que de esta línea se le devolvió al proveedor. Se acumula aquí, y no
    -- se calcula sumando las devoluciones, por el mismo motivo que en
    -- `venta_detalle`: un CHECK no puede consultar otra tabla, y hace falta
    -- para impedir que se devuelvan 30 unidades de una línea que trajo 24.
    cantidad_devuelta DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    costo_unitario  DECIMAL(12,2) NOT NULL,
    -- derivada de las dos anteriores: columna generada, no se puede desincronizar (3FN)
    importe         DECIMAL(12,2) GENERATED ALWAYS AS (ROUND(cantidad * costo_unitario, 2)) STORED,
    PRIMARY KEY (id),
    KEY ix_compradet_compra   (compra_id),
    KEY ix_compradet_producto (producto_id),
    CONSTRAINT fk_compradet_compra   FOREIGN KEY (compra_id)   REFERENCES compras (id) ON DELETE CASCADE,
    CONSTRAINT fk_compradet_producto FOREIGN KEY (producto_id) REFERENCES productos (id),
    CONSTRAINT ck_compradet_cantidad CHECK (cantidad > 0),
    CONSTRAINT ck_compradet_costo    CHECK (costo_unitario >= 0),
    CONSTRAINT ck_compradet_devuelta CHECK (cantidad_devuelta >= 0 AND cantidad_devuelta <= cantidad)
) ENGINE=InnoDB;

CREATE TABLE lotes (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    producto_id         INT UNSIGNED  NOT NULL,
    codigo              VARCHAR(30)   NULL,          -- el lote impreso por el fabricante
    -- NULL = stock cuya fecha no se conoce: el que ya estaba cuando se encendió
    -- el control, y el que devuelve un cliente días después de llevárselo.
    -- Decir «sin fecha registrada» es más honesto que inventar una.
    fecha_vencimiento   DATE          NULL,
    cantidad_inicial    DECIMAL(12,3) NOT NULL,      -- lo que entró; no se toca nunca
    cantidad_actual     DECIMAL(12,3) NOT NULL,      -- lo que queda
    compra_detalle_id   BIGINT UNSIGNED NULL,
    creado_en           TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- El índice del despacho: se sale por el que vence antes (FEFO).
    KEY ix_lotes_fefo        (producto_id, fecha_vencimiento),
    KEY ix_lotes_vencimiento (fecha_vencimiento),
    KEY ix_lotes_compra      (compra_detalle_id),
    CONSTRAINT fk_lotes_producto FOREIGN KEY (producto_id) REFERENCES productos (id),
    -- SET NULL y no CASCADE: si se borrara la compra, el stock que entró por
    -- ella sigue en el estante y su lote no puede irse con el papel.
    CONSTRAINT fk_lotes_compra   FOREIGN KEY (compra_detalle_id) REFERENCES compra_detalle (id) ON DELETE SET NULL,
    CONSTRAINT ck_lotes_cantidades CHECK (
        cantidad_inicial > 0
        AND cantidad_actual >= 0
        AND cantidad_actual <= cantidad_inicial
    )
) ENGINE=InnoDB;

-- De qué lote salió cada línea de venta. Sin esto, lo anulado o devuelto
-- volvía al lote que vence antes entre los abiertos —o a uno que vence en un
-- año— y las unidades perdían su fecha real: dejaban de aparecer en la alerta
-- de vencimientos. `repuesta` evita devolver dos veces lo mismo.
CREATE TABLE lote_salidas (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lote_id             BIGINT UNSIGNED NOT NULL,
    venta_detalle_id    BIGINT UNSIGNED NOT NULL,
    cantidad            DECIMAL(12,3)   NOT NULL,
    repuesta            DECIMAL(12,3)   NOT NULL DEFAULT 0.000,
    PRIMARY KEY (id),
    KEY ix_lote_salidas_linea (venta_detalle_id),
    KEY ix_lote_salidas_lote  (lote_id),
    CONSTRAINT fk_lote_salidas_lote  FOREIGN KEY (lote_id)          REFERENCES lotes (id),
    CONSTRAINT fk_lote_salidas_linea FOREIGN KEY (venta_detalle_id) REFERENCES venta_detalle (id),
    CONSTRAINT ck_lote_salidas CHECK (cantidad > 0 AND repuesta >= 0 AND repuesta <= cantidad)
) ENGINE=InnoDB;

-- Mercadería que se le devuelve al proveedor, colgada de la compra por la que
-- entró. No es lo mismo que `devoluciones`, que es del cliente hacia la tienda:
-- una suma al stock y la otra lo resta, una la firma el cajero y la otra el
-- almacenero, y los reportes tienen que poder contarlas por separado.
--
-- Sin esta tabla la única salida era un ajuste de inventario con el motivo
-- escrito a mano: bajaba el stock, sí, pero quedaba mezclado con la merma y la
-- rotura, y nadie podía responder después «cuánto le devolví a este proveedor».
CREATE TABLE devoluciones_compra (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    compra_id           INT UNSIGNED NOT NULL,
    usuario_id          INT UNSIGNED NOT NULL,
    fecha               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Por qué se devuelve. Separado del texto libre porque es lo que después
    -- permite contar «cuánto devolví por vencimiento este trimestre», que es
    -- justo la cifra que dice si hay que comprar menos o rotar mejor.
    motivo              ENUM('DEFECTO','VENCIMIENTO','ERROR','OTRO') NOT NULL,
    -- En qué se quedó con el proveedor. Son TRES finales y no dos, que es lo
    -- que un booleano no podía expresar:
    --
    --   REPUESTO      lo cambió en el momento: sale lo fallado y entra lo
    --                 bueno en este mismo documento, el stock queda igual que
    --                 antes, pero registrado. El cambio no es un módulo
    --                 aparte; es este valor.
    --   PENDIENTE     se llevó la mercadería y traerá el reemplazo. El stock
    --                 bajó hoy y subirá cuando llegue. Mientras tanto el
    --                 proveedor DEBE mercadería, y eso hay que poder verlo.
    --   NOTA_CREDITO  no repone: queda a cuenta.
    espera              ENUM('REPUESTO','PENDIENTE','NOTA_CREDITO') NOT NULL DEFAULT 'NOTA_CREDITO',
    documento_externo   VARCHAR(30)  NULL,       -- nota de crédito o guía de devolución
    observacion         VARCHAR(255) NULL,
    creado_en           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_devcompra_compra (compra_id),
    KEY ix_devcompra_fecha  (fecha),
    -- «¿Qué me deben todavía?» es una consulta de todos los días en el almacén.
    KEY ix_devcompra_espera (espera, fecha),
    CONSTRAINT fk_devcompra_compra  FOREIGN KEY (compra_id)  REFERENCES compras (id),
    CONSTRAINT fk_devcompra_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE devolucion_compra_detalle (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    devolucion_compra_id  INT UNSIGNED    NOT NULL,
    compra_detalle_id     BIGINT UNSIGNED NOT NULL,
    producto_id           INT UNSIGNED    NOT NULL,
    -- La tanda que se devuelve, cuando el producto lleva control de
    -- vencimiento. Es lo que permite devolver EL lote vencido y no el que
    -- tocaría por orden de salida.
    lote_id               BIGINT UNSIGNED NULL,
    cantidad              DECIMAL(12,3)   NOT NULL,
    -- Lo que el proveedor ya repuso de esta línea. Mismo motivo que los otros
    -- acumulados del esquema: el reemplazo puede llegar en partes —trae 6 de
    -- las 10 que debe— y una restricción no puede consultar otra tabla, así que
    -- el tope vive en la propia línea.
    cantidad_repuesta     DECIMAL(12,3)   NOT NULL DEFAULT 0.000,
    costo_unitario        DECIMAL(12,2)   NOT NULL,
    importe               DECIMAL(12,2) GENERATED ALWAYS AS (ROUND(cantidad * costo_unitario, 2)) STORED,
    PRIMARY KEY (id),
    KEY ix_devcompradet_cabecera (devolucion_compra_id),
    KEY ix_devcompradet_linea    (compra_detalle_id),
    KEY ix_devcompradet_producto (producto_id),
    CONSTRAINT fk_devcompradet_cabecera FOREIGN KEY (devolucion_compra_id) REFERENCES devoluciones_compra (id) ON DELETE CASCADE,
    CONSTRAINT fk_devcompradet_linea    FOREIGN KEY (compra_detalle_id)    REFERENCES compra_detalle (id),
    CONSTRAINT fk_devcompradet_producto FOREIGN KEY (producto_id)          REFERENCES productos (id),
    -- SET NULL y no CASCADE: el lote puede desaparecer del control, pero lo
    -- que se devolvió pasó y su línea tiene que seguir ahí.
    CONSTRAINT fk_devcompradet_lote     FOREIGN KEY (lote_id)              REFERENCES lotes (id) ON DELETE SET NULL,
    CONSTRAINT ck_devcompradet_cantidad CHECK (cantidad > 0 AND costo_unitario >= 0),
    CONSTRAINT ck_devcompradet_repuesta CHECK (cantidad_repuesta >= 0 AND cantidad_repuesta <= cantidad)
) ENGINE=InnoDB;

-- El documento que originó el movimiento se referencia con una FOREIGN KEY por origen,
-- no con un par (tabla, id) sin integridad referencial. Un CHECK garantiza que cada
-- origen traiga exactamente la referencia que le corresponde y ninguna otra.
--
-- `proveedor_id` y `documento_externo` siguen aquí, sueltos, para la pantalla
-- de "Ingresar mercadería" (una línea a la vez, sin compra multi-línea detrás).
-- `compra_id` es para cuando el origen SÍ viene de una compra con cabecera en
-- `compras`; puede convivir con los dos campos sueltos o reemplazarlos según
-- por dónde se haya registrado el ingreso.
CREATE TABLE movimientos_inventario (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    producto_id         INT UNSIGNED NOT NULL,
    usuario_id          INT UNSIGNED NULL,
    tipo                ENUM('ENTRADA','SALIDA','AJUSTE') NOT NULL,
    origen              ENUM('VENTA','COMPRA','DEVOLUCION','DEVOLUCION_COMPRA','ANULACION','AJUSTE','INICIAL') NOT NULL,
    -- referencias al documento de origen (una sola según `origen`)
    venta_id            BIGINT UNSIGNED NULL,   -- VENTA, ANULACION
    devolucion_id       BIGINT UNSIGNED NULL,   -- DEVOLUCION
    proveedor_id        INT UNSIGNED NULL,      -- COMPRA, DEVOLUCION_COMPRA
    compra_id           INT UNSIGNED NULL,      -- COMPRA con cabecera (opcional)
    devolucion_compra_id INT UNSIGNED NULL,     -- DEVOLUCION_COMPRA
    documento_externo   VARCHAR(30)  NULL,      -- COMPRA: guía o factura del proveedor
    cantidad            DECIMAL(12,3) NOT NULL, -- siempre positiva
    stock_anterior      DECIMAL(12,3) NOT NULL,
    stock_resultante    DECIMAL(12,3) NOT NULL,
    costo_unitario      DECIMAL(12,2) NULL,
    motivo              VARCHAR(255) NULL,
    fecha               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_movinv_producto   (producto_id, fecha),
    KEY ix_movinv_venta      (venta_id),
    KEY ix_movinv_devolucion (devolucion_id),
    KEY ix_movinv_proveedor  (proveedor_id),
    KEY ix_movinv_compra     (compra_id),
    KEY ix_movinv_devcompra  (devolucion_compra_id),
    KEY ix_movinv_fecha      (fecha),
    CONSTRAINT fk_movinv_producto   FOREIGN KEY (producto_id)   REFERENCES productos (id),
    CONSTRAINT fk_movinv_usuario    FOREIGN KEY (usuario_id)    REFERENCES usuarios (id),
    CONSTRAINT fk_movinv_venta      FOREIGN KEY (venta_id)      REFERENCES ventas (id),
    CONSTRAINT fk_movinv_devolucion FOREIGN KEY (devolucion_id) REFERENCES devoluciones (id),
    CONSTRAINT fk_movinv_proveedor  FOREIGN KEY (proveedor_id)  REFERENCES proveedores (id),
    CONSTRAINT fk_movinv_compra     FOREIGN KEY (compra_id)     REFERENCES compras (id),
    CONSTRAINT fk_movinv_devcompra  FOREIGN KEY (devolucion_compra_id) REFERENCES devoluciones_compra (id),
    CONSTRAINT ck_movinv_cantidad   CHECK (cantidad > 0),
    -- cada origen con su referencia, y sin las ajenas
    CONSTRAINT ck_movinv_origen CHECK (
        (origen IN ('VENTA','ANULACION')
             AND venta_id IS NOT NULL AND devolucion_id IS NULL AND proveedor_id IS NULL
             AND devolucion_compra_id IS NULL)
     OR (origen = 'DEVOLUCION'
             AND devolucion_id IS NOT NULL AND venta_id IS NULL AND proveedor_id IS NULL
             AND devolucion_compra_id IS NULL)
     OR (origen = 'COMPRA'
             AND venta_id IS NULL AND devolucion_id IS NULL AND devolucion_compra_id IS NULL)
     OR (origen = 'DEVOLUCION_COMPRA'
             AND devolucion_compra_id IS NOT NULL AND venta_id IS NULL AND devolucion_id IS NULL)
     OR (origen IN ('AJUSTE','INICIAL')
             AND venta_id IS NULL AND devolucion_id IS NULL AND proveedor_id IS NULL
             AND devolucion_compra_id IS NULL AND documento_externo IS NULL)
    ),
    -- un ajuste sin explicación es un descuadre sin responsable
    CONSTRAINT ck_movinv_motivo CHECK (origen <> 'AJUSTE' OR motivo IS NOT NULL)
) ENGINE=InnoDB;

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
CREATE TABLE tomas_inventario (
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

CREATE TABLE toma_inventario_detalle (
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

-- =============================================================================
--  9. CONFIGURACIÓN Y AUDITORÍA
-- =============================================================================

CREATE TABLE configuracion (
    clave           VARCHAR(50)  NOT NULL,
    valor           VARCHAR(255) NOT NULL,
    descripcion     VARCHAR(200) NULL,
    actualizado_en  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (clave)
) ENGINE=InnoDB;

CREATE TABLE auditoria (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id      INT UNSIGNED NULL,
    accion          VARCHAR(50)  NOT NULL,   -- LOGIN, ANULAR_VENTA, CAMBIO_PRECIO...
    entidad         VARCHAR(50)  NULL,
    entidad_id      BIGINT UNSIGNED NULL,
    detalle         JSON         NULL,
    ip              VARCHAR(45)  NULL,
    fecha           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_auditoria_usuario (usuario_id, fecha),
    KEY ix_auditoria_entidad (entidad, entidad_id),
    CONSTRAINT fk_auditoria_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;

-- =============================================================================
--  10. TRIGGERS
-- =============================================================================

DELIMITER $$

-- 10.-1 Al cesar o suspender a un empleado, su cuenta pierde el acceso.
--       Separar `empleados.estado` de `usuarios.activo` sirve justamente para esto:
--       el vínculo laboral manda sobre el acceso, no al revés.
CREATE TRIGGER trg_empleados_after_update
AFTER UPDATE ON empleados
FOR EACH ROW
BEGIN
    IF NEW.estado IN ('CESADO','SUSPENDIDO') AND OLD.estado = 'ACTIVO' THEN
        UPDATE usuarios SET activo = 0 WHERE empleado_id = NEW.id;
    END IF;
END$$

-- 10.0 Al insertar una línea de venta: copiar del producto el régimen de impuesto
--      y la tasa vigente, para que la factura tenga su desglose por línea.
--      Si la aplicación envía una tasa explícita (> 0), esa manda.
CREATE TRIGGER trg_venta_detalle_before_insert
BEFORE INSERT ON venta_detalle
FOR EACH ROW
BEGIN
    DECLARE v_afecto TINYINT(1);

    -- La línea sigue el modo de precio de su venta: todas iguales.
    SET NEW.impuesto_incluido = IFNULL((SELECT impuesto_incluido FROM ventas WHERE id = NEW.venta_id), 0);

    IF NEW.tasa_impuesto = 0 THEN
        SELECT afecto_impuesto INTO v_afecto FROM productos WHERE id = NEW.producto_id;
        SET NEW.afecto_impuesto = IFNULL(v_afecto, 0);
        SET NEW.tasa_impuesto = IF(NEW.afecto_impuesto = 1,
            IFNULL((SELECT CAST(valor AS DECIMAL(6,4)) FROM configuracion
                     WHERE clave = 'tasa_impuesto'), 0), 0);
    END IF;
END$$

-- 10.1 Al vender: validar stock, descontarlo y registrar el movimiento (HU-17, HU-18)
CREATE TRIGGER trg_venta_detalle_after_insert
AFTER INSERT ON venta_detalle
FOR EACH ROW
BEGIN
    DECLARE v_stock  DECIMAL(12,3);
    DECLARE v_nombre VARCHAR(120);
    DECLARE v_usuario INT UNSIGNED;

    SELECT stock_actual, nombre INTO v_stock, v_nombre
      FROM productos WHERE id = NEW.producto_id FOR UPDATE;

    IF v_stock < NEW.cantidad THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Stock insuficiente para el producto de la venta';
    END IF;

    UPDATE productos
       SET stock_actual = stock_actual - NEW.cantidad
     WHERE id = NEW.producto_id;

    SELECT usuario_id INTO v_usuario FROM ventas WHERE id = NEW.venta_id;

    INSERT INTO movimientos_inventario
        (producto_id, usuario_id, tipo, origen, venta_id,
         cantidad, stock_anterior, stock_resultante, motivo)
    VALUES
        (NEW.producto_id, v_usuario, 'SALIDA', 'VENTA', NEW.venta_id,
         NEW.cantidad, v_stock, v_stock - NEW.cantidad, 'Venta de productos');
END$$

-- 10.1.b Al devolver una línea: copiar el régimen de impuesto de la línea de
--        venta original. La tasa vigente hoy puede no ser la de aquel día, y
--        al cliente se le devuelve lo que pagó.
CREATE TRIGGER trg_devolucion_detalle_before_insert
BEFORE INSERT ON devolucion_detalle
FOR EACH ROW
BEGIN
    DECLARE v_afecto TINYINT(1);
    DECLARE v_tasa   DECIMAL(6,4);

    -- El modo de precio de la venta, siempre: si el precio llevaba el impuesto
    -- adentro, la devolución lo separa igual y devuelve lo mismo que se cobró.
    SET NEW.impuesto_incluido = IFNULL((SELECT impuesto_incluido FROM venta_detalle
                                         WHERE id = NEW.venta_detalle_id), 0);

    IF NEW.tasa_impuesto = 0 THEN
        SELECT afecto_impuesto, tasa_impuesto
          INTO v_afecto, v_tasa
          FROM venta_detalle WHERE id = NEW.venta_detalle_id;

        SET NEW.afecto_impuesto = IFNULL(v_afecto, 0);
        SET NEW.tasa_impuesto   = IF(NEW.afecto_impuesto = 1, IFNULL(v_tasa, 0), 0);
    END IF;
END$$

-- 10.2 Al devolver: reingresar stock y registrar el movimiento (HU-30)
CREATE TRIGGER trg_devolucion_detalle_after_insert
AFTER INSERT ON devolucion_detalle
FOR EACH ROW
BEGIN
    DECLARE v_stock    DECIMAL(12,3);
    DECLARE v_usuario  INT UNSIGNED;
    DECLARE v_venta_id BIGINT UNSIGNED;
    DECLARE v_pend     INT;

    -- acumula lo devuelto en la línea de venta original
    UPDATE venta_detalle
       SET cantidad_devuelta = cantidad_devuelta + NEW.cantidad
     WHERE id = NEW.venta_detalle_id;

    SELECT venta_id INTO v_venta_id FROM devoluciones WHERE id = NEW.devolucion_id;

    -- Total de la devolución = suma de su detalle CON impuesto. Es el dinero
    -- que sale del cajón, y por eso `sp_cerrar_caja` puede restarlo tal cual
    -- del efectivo esperado. Comparable con `ventas.total`, que también lo lleva.
    UPDATE devoluciones
       SET total = (SELECT IFNULL(SUM(total_linea), 0)
                      FROM devolucion_detalle WHERE devolucion_id = NEW.devolucion_id)
     WHERE id = NEW.devolucion_id;

    -- acumulado devuelto de la venta
    UPDATE ventas
       SET total_devuelto = (SELECT IFNULL(SUM(total), 0)
                               FROM devoluciones WHERE venta_id = v_venta_id)
     WHERE id = v_venta_id;

    -- estado de la venta: DEVUELTA si ya no queda nada por devolver, si no PARCIAL.
    -- Una venta ANULADA no cambia de estado.
    SELECT COUNT(*) INTO v_pend
      FROM venta_detalle
     WHERE venta_id = v_venta_id AND cantidad_devuelta < cantidad;

    UPDATE ventas
       SET estado = IF(v_pend = 0, 'DEVUELTA', 'DEVUELTA_PARCIAL')
     WHERE id = v_venta_id
       AND estado IN ('COMPLETADA', 'DEVUELTA_PARCIAL', 'DEVUELTA');

    IF NEW.reingresa_stock = 1 THEN
        SELECT stock_actual INTO v_stock
          FROM productos WHERE id = NEW.producto_id FOR UPDATE;

        UPDATE productos
           SET stock_actual = stock_actual + NEW.cantidad
         WHERE id = NEW.producto_id;

        SELECT usuario_id INTO v_usuario
          FROM devoluciones WHERE id = NEW.devolucion_id;

        INSERT INTO movimientos_inventario
            (producto_id, usuario_id, tipo, origen, devolucion_id,
             cantidad, stock_anterior, stock_resultante, motivo)
        VALUES
            (NEW.producto_id, v_usuario, 'ENTRADA', 'DEVOLUCION', NEW.devolucion_id,
             NEW.cantidad, v_stock, v_stock + NEW.cantidad, 'Devolución de cliente');
    END IF;
END$$

-- 10.3 Las ventas no se eliminan: se anulan
CREATE TRIGGER trg_ventas_before_delete
BEFORE DELETE ON ventas
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Las ventas no se eliminan. Use la anulación (RNF6).';
END$$

-- 10.4 El comprobante debe corresponder al tipo de persona del cliente:
--      FACTURA solo a persona jurídica con NIT, RECIBO solo a persona natural.
CREATE TRIGGER trg_comprobantes_before_insert
BEFORE INSERT ON comprobantes
FOR EACH ROW
BEGIN
    DECLARE v_aplica     VARCHAR(10);
    DECLARE v_ex_cliente TINYINT(1);
    DECLARE v_ex_doc     TINYINT(1);

    -- el tipo se deduce de la serie: no hay dos fuentes que puedan contradecirse
    SELECT tc.aplica_persona, tc.exige_cliente, tc.exige_documento
      INTO v_aplica, v_ex_cliente, v_ex_doc
      FROM series_comprobante s
      JOIN tipos_comprobante tc ON tc.id = s.tipo_comprobante_id
     WHERE s.id = NEW.serie_id;

    IF v_aplica IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La serie del comprobante no existe';
    END IF;

    IF v_ex_cliente = 1 AND NEW.cliente_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Este tipo de comprobante exige un cliente registrado';
    END IF;

    IF v_ex_doc = 1 AND (NEW.cliente_documento IS NULL OR NEW.cliente_documento = '') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Este tipo de comprobante exige el documento del cliente';
    END IF;

    -- Si hay cliente identificado, su tipo de persona debe coincidir con el documento.
    -- Sin cliente (venta al paso) solo pasan los tipos que no lo exigen: recibo y nota de venta.
    -- La excepción: una persona natural con NIT (unipersonal) recibe factura.
    IF v_aplica <> 'AMBAS' AND NEW.cliente_id IS NOT NULL
       AND IFNULL(NEW.tipo_persona, '') <> v_aplica
       AND NOT (v_aplica = 'JURIDICA' AND NEW.cliente_tipo_documento = 'NIT') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El tipo de comprobante no corresponde al tipo de persona del cliente';
    END IF;
END$$

DELIMITER ;

-- =============================================================================
--  11. PROCEDIMIENTOS ALMACENADOS
-- =============================================================================

DELIMITER $$

-- 11.1 Siguiente correlativo con bloqueo de fila (HU-13)
CREATE PROCEDURE sp_siguiente_comprobante (
    IN  p_serie_id     SMALLINT UNSIGNED,
    OUT p_numero       INT UNSIGNED,
    OUT p_comprobante  VARCHAR(20)
)
BEGIN
    DECLARE v_serie     VARCHAR(6);
    DECLARE v_longitud  TINYINT UNSIGNED;

    SELECT serie, correlativo_actual + 1, longitud
      INTO v_serie, p_numero, v_longitud
      FROM series_comprobante
     WHERE id = p_serie_id
     FOR UPDATE;

    UPDATE series_comprobante
       SET correlativo_actual = p_numero
     WHERE id = p_serie_id;

    SET p_comprobante = CONCAT(v_serie, '-', LPAD(p_numero, v_longitud, '0'));
END$$

-- 11.2 Recalcular los totales de una venta a partir de su detalle.
--      En los dos modos:
--          subtotal  = suma de importes del detalle (base imponible, sin impuesto)
--          total     = subtotal - descuento + impuesto
--      Impuesto encima del precio: impuesto = impuesto de las líneas, en la
--      proporción de la base que deja el descuento.
--      Impuesto incluido: el total es lo cobrado por las líneas menos el
--      descuento que vio el cliente; el impuesto baja en la misma proporción y
--      `descuento` guarda la parte de ese descuento que corresponde a la base.
--      El descuento de cabecera se prorratea entre la base afecta y la inafecta.
CREATE PROCEDURE sp_recalcular_venta (IN p_venta_id BIGINT UNSIGNED)
BEGIN
    DECLARE v_base_total     DECIMAL(12,2);
    DECLARE v_impuesto_bruto DECIMAL(12,2);
    DECLARE v_total_lineas   DECIMAL(12,2);
    DECLARE v_descuento      DECIMAL(12,2);
    DECLARE v_impuesto       DECIMAL(12,2);
    DECLARE v_incluido       TINYINT(1);
    DECLARE v_desc_final     DECIMAL(12,2);

    -- el impuesto por línea ya está calculado y guardado en venta_detalle
    SELECT IFNULL(SUM(importe), 0), IFNULL(SUM(impuesto_linea), 0), IFNULL(SUM(total_linea), 0)
      INTO v_base_total, v_impuesto_bruto, v_total_lineas
      FROM venta_detalle
     WHERE venta_id = p_venta_id;

    SELECT descuento, impuesto_incluido, IFNULL(descuento_precio_final, 0)
      INTO v_descuento, v_incluido, v_desc_final
      FROM ventas WHERE id = p_venta_id;

    IF v_incluido = 1 THEN
        IF v_desc_final > v_total_lineas THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'El descuento no puede superar el total de la venta';
        END IF;

        SET v_impuesto = IF(v_total_lineas > 0,
                            ROUND(v_impuesto_bruto * (v_total_lineas - v_desc_final) / v_total_lineas, 2),
                            0);

        UPDATE ventas
           SET subtotal  = v_base_total,
               descuento = v_desc_final - v_impuesto_bruto + v_impuesto,
               impuesto  = v_impuesto
         WHERE id = p_venta_id;
    ELSE
        IF v_descuento > v_base_total THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'El descuento no puede superar el subtotal de la venta';
        END IF;

        -- El impuesto baja en la proporción de la base que deja el descuento de
        -- cabecera. En una sola cuenta, sin guardar antes el factor: con el factor
        -- en una variable de 6 decimales, 0.51 × 3.30 / 3.96 daba 0.42 y no 0.43, y
        -- el procedimiento y el modo PHP no cobraban el mismo impuesto.
        SET v_impuesto = IF(v_base_total > 0,
                            ROUND(v_impuesto_bruto * (v_base_total - v_descuento) / v_base_total, 2),
                            0);

        -- `total` es columna generada: se recalcula sola a partir de estos tres valores
        UPDATE ventas
           SET subtotal = v_base_total,
               impuesto = v_impuesto
         WHERE id = p_venta_id;
    END IF;
END$$

-- 11.2.b Emitir el comprobante de una venta: FACTURA para persona jurídica,
--        RECIBO para persona natural. Toma el correlativo y congela los datos
--        del cliente y los importes en `comprobantes`.
--        Basta la serie: ella determina el tipo de documento.
CREATE PROCEDURE sp_emitir_comprobante (
    IN  p_venta_id            BIGINT UNSIGNED,
    IN  p_serie_id            SMALLINT UNSIGNED,
    OUT p_comprobante_id      BIGINT UNSIGNED,
    OUT p_numero_completo     VARCHAR(20)
)
BEGIN
    DECLARE v_estado      VARCHAR(20);
    DECLARE v_cliente_id  INT UNSIGNED;
    DECLARE v_numero      INT UNSIGNED;
    DECLARE v_moneda      VARCHAR(3);
    DECLARE v_generico    VARCHAR(150);

    SELECT estado, cliente_id INTO v_estado, v_cliente_id
      FROM ventas WHERE id = p_venta_id FOR UPDATE;

    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La venta no existe';
    END IF;
    IF v_estado <> 'COMPLETADA' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se emite comprobante de una venta COMPLETADA';
    END IF;
    -- solo se bloquea si hay un comprobante VIGENTE; los sustituidos y anulados no cuentan
    IF EXISTS (SELECT 1 FROM comprobantes
                WHERE venta_id = p_venta_id AND estado = 'EMITIDO') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La venta ya tiene un comprobante vigente. Use sp_sustituir_comprobante.';
    END IF;

    -- correlativo con bloqueo de fila
    CALL sp_siguiente_comprobante(p_serie_id, v_numero, p_numero_completo);

    SET v_moneda = IFNULL((SELECT valor FROM configuracion WHERE clave = 'moneda_codigo'), 'BOB');

    -- Venta al paso: sin cliente registrado el documento sale a nombre genérico.
    SET v_generico = IFNULL((SELECT valor FROM configuracion
                              WHERE clave = 'cliente_generico_nombre'), 'Cliente varios');

    -- El trigger trg_comprobantes_before_insert valida que el tipo de documento
    -- corresponda al tipo de persona del cliente.
    INSERT INTO comprobantes (
        venta_id, serie_id, numero, numero_completo,
        cliente_id, tipo_persona, cliente_nombre, cliente_tipo_documento,
        cliente_documento, cliente_direccion, representante_legal,
        subtotal, descuento, impuesto, moneda, emitido_por
    )
    SELECT v.id, p_serie_id, v_numero, p_numero_completo,
           c.id, c.tipo_persona,
           IFNULL(c.nombre, v_generico),
           IFNULL(c.tipo_documento, 'SIN'),
           c.documento, c.direccion, c.representante_legal,
           v.subtotal, v.descuento, v.impuesto, v_moneda, v.usuario_id
      FROM ventas v
      LEFT JOIN clientes c ON c.id = v.cliente_id
     WHERE v.id = p_venta_id;

    SET p_comprobante_id = LAST_INSERT_ID();
END$$

-- 11.2.c Sustituir el comprobante de una venta ya cobrada (HU-42).
--        Caso típico: se entregó un RECIBO y el cliente vuelve pidiendo FACTURA.
--        No se toca la venta ni el stock: solo cambia el documento.
--        El anterior queda SUSTITUIDO (no se borra) y el nuevo lo referencia.
CREATE PROCEDURE sp_sustituir_comprobante (
    IN  p_comprobante_id      BIGINT UNSIGNED,
    IN  p_serie_id            SMALLINT UNSIGNED,  -- serie del nuevo documento (define el tipo)
    IN  p_cliente_id          INT UNSIGNED,   -- cliente a asignar a la venta (NULL = no cambia)
    IN  p_usuario_id          INT UNSIGNED,
    IN  p_motivo              VARCHAR(255),
    OUT p_nuevo_id            BIGINT UNSIGNED,
    OUT p_numero_completo     VARCHAR(20)
)
BEGIN
    DECLARE v_estado_doc   VARCHAR(15);
    DECLARE v_venta_id     BIGINT UNSIGNED;
    DECLARE v_numero_ant   VARCHAR(20);
    DECLARE v_estado_venta VARCHAR(20);
    DECLARE v_fecha_venta  DATETIME;
    DECLARE v_dias_max     INT;

    SELECT estado, venta_id, numero_completo
      INTO v_estado_doc, v_venta_id, v_numero_ant
      FROM comprobantes WHERE id = p_comprobante_id FOR UPDATE;

    IF v_estado_doc IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El comprobante no existe';
    END IF;
    IF v_estado_doc <> 'EMITIDO' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Solo se puede sustituir un comprobante vigente (EMITIDO)';
    END IF;

    SELECT estado, fecha INTO v_estado_venta, v_fecha_venta
      FROM ventas WHERE id = v_venta_id FOR UPDATE;

    IF v_estado_venta <> 'COMPLETADA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'No se sustituye el comprobante de una venta anulada o devuelta';
    END IF;

    -- ventana de tiempo permitida (configurable)
    SET v_dias_max = IFNULL((SELECT CAST(valor AS SIGNED) FROM configuracion
                              WHERE clave = 'dias_max_sustitucion'), 1);
    IF DATEDIFF(NOW(), v_fecha_venta) > v_dias_max THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La venta excede el plazo permitido para sustituir su comprobante';
    END IF;

    -- si se va a facturar, la venta debe quedar asociada al cliente jurídico
    IF p_cliente_id IS NOT NULL THEN
        UPDATE ventas SET cliente_id = p_cliente_id WHERE id = v_venta_id;
    END IF;

    -- el anterior deja de ser el vigente (libera el índice único)
    UPDATE comprobantes
       SET estado        = 'SUSTITUIDO',
           sustituido_en = NOW()
     WHERE id = p_comprobante_id;

    -- el nuevo documento toma su propio correlativo; el trigger valida que el tipo
    -- corresponda al tipo de persona del cliente
    CALL sp_emitir_comprobante(v_venta_id, p_serie_id, p_nuevo_id, p_numero_completo);

    UPDATE comprobantes
       SET sustituye_a    = p_comprobante_id,
           motivo_emision = p_motivo,
           emitido_por    = p_usuario_id
     WHERE id = p_nuevo_id;

    INSERT INTO auditoria (usuario_id, accion, entidad, entidad_id, detalle)
    VALUES (p_usuario_id, 'SUSTITUIR_COMPROBANTE', 'comprobantes', p_nuevo_id,
            JSON_OBJECT('venta_id',   v_venta_id,
                        'sustituye_a', p_comprobante_id,
                        'anterior',    v_numero_ant,
                        'nuevo',       p_numero_completo,
                        'motivo',      p_motivo));
END$$

-- 11.3 Anular una venta: revierte el stock y marca el estado (HU-29)
CREATE PROCEDURE sp_anular_venta (
    IN p_venta_id   BIGINT UNSIGNED,
    IN p_usuario_id INT UNSIGNED,
    IN p_motivo     VARCHAR(255)
)
BEGIN
    DECLARE v_estado VARCHAR(20);

    SELECT estado INTO v_estado FROM ventas WHERE id = p_venta_id FOR UPDATE;

    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La venta no existe';
    END IF;
    IF v_estado <> 'COMPLETADA' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se puede anular una venta COMPLETADA';
    END IF;

    -- Reingreso de stock + kardex, agrupando por producto: un UPDATE con JOIN
    -- toca cada fila UNA vez, así que con el mismo producto en dos líneas
    -- reponía solo una de las dos. Agrupado, repone la suma aunque el índice
    -- único de arriba falte en una base vieja.
    INSERT INTO movimientos_inventario
        (producto_id, usuario_id, tipo, origen, venta_id,
         cantidad, stock_anterior, stock_resultante, motivo)
    SELECT t.producto_id, p_usuario_id, 'ENTRADA', 'ANULACION', p_venta_id,
           t.cantidad, p.stock_actual, p.stock_actual + t.cantidad,
           CONCAT('Anulación de venta: ', p_motivo)
      FROM (SELECT producto_id, SUM(cantidad) AS cantidad
                   FROM venta_detalle WHERE venta_id = p_venta_id
                  GROUP BY producto_id) t
      JOIN productos p ON p.id = t.producto_id;

    UPDATE productos p
      JOIN (SELECT producto_id, SUM(cantidad) AS cantidad
                   FROM venta_detalle WHERE venta_id = p_venta_id
                  GROUP BY producto_id) t ON t.producto_id = p.id
       SET p.stock_actual = p.stock_actual + t.cantidad;

    UPDATE ventas
       SET estado           = 'ANULADA',
           anulada_en       = NOW(),
           anulada_por      = p_usuario_id,
           motivo_anulacion = p_motivo
     WHERE id = p_venta_id;

    -- el comprobante vigente queda anulado, pero no se borra: el correlativo se conserva.
    -- Los sustituidos previos mantienen su estado: son historial.
    UPDATE comprobantes
       SET estado           = 'ANULADO',
           anulado_en       = NOW(),
           motivo_anulacion = p_motivo
     WHERE venta_id = p_venta_id
       AND estado   = 'EMITIDO';

    INSERT INTO auditoria (usuario_id, accion, entidad, entidad_id, detalle)
    VALUES (p_usuario_id, 'ANULAR_VENTA', 'ventas', p_venta_id,
            JSON_OBJECT('motivo', p_motivo));
END$$

-- 11.4 Cerrar caja calculando el esperado y la diferencia (HU-27)
CREATE PROCEDURE sp_cerrar_caja (
    IN p_sesion_id  INT UNSIGNED,
    IN p_usuario_id INT UNSIGNED,
    IN p_declarado  DECIMAL(12,2),
    IN p_observacion VARCHAR(255)
)
BEGIN
    DECLARE v_inicial   DECIMAL(12,2);
    DECLARE v_ventas    DECIMAL(12,2);
    DECLARE v_ingresos  DECIMAL(12,2);
    DECLARE v_egresos   DECIMAL(12,2);
    DECLARE v_devuelto  DECIMAL(12,2);
    DECLARE v_esperado  DECIMAL(12,2);

    SELECT monto_inicial INTO v_inicial
      FROM sesiones_caja WHERE id = p_sesion_id AND estado = 'ABIERTA' FOR UPDATE;

    IF v_inicial IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La sesión de caja no existe o ya está cerrada';
    END IF;

    -- solo los pagos en métodos que afectan la caja física
    -- El efectivo que queda en el cajón es `monto`: por definición
    -- monto_recibido - vuelto = monto. Restar el vuelto aquí lo descontaría dos veces.
    SELECT IFNULL(SUM(vp.monto), 0) INTO v_ventas
      FROM venta_pagos vp
      JOIN ventas v        ON v.id = vp.venta_id
      JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id
     WHERE v.sesion_caja_id = p_sesion_id
       AND v.estado <> 'ANULADA'
       AND mp.afecta_caja = 1;

    SELECT IFNULL(SUM(IF(tipo = 'INGRESO', monto, 0)), 0),
           IFNULL(SUM(IF(tipo = 'EGRESO',  monto, 0)), 0)
      INTO v_ingresos, v_egresos
      FROM movimientos_caja WHERE sesion_caja_id = p_sesion_id;

    -- De cada devolución sale del cajón solo la fracción que en su día entró
    -- en efectivo. Una venta cobrada con tarjeta se reembolsa por el mismo
    -- medio: descontarla del cajón dejaría al cajero con un sobrante.
    -- Desde el 14/09/2026 cada devolución guarda en `efectivo` lo que salió
    -- del cajón según el medio de reembolso elegido. Las anteriores no lo
    -- tienen y siguen con la proporción de siempre.
    SELECT IFNULL(SUM(IFNULL(d.efectivo,
               ROUND(d.total * IFNULL(
                   (SELECT SUM(vp.monto)
                      FROM venta_pagos vp
                      JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id
                     WHERE vp.venta_id = d.venta_id
                       AND mp.afecta_caja = 1)
                   / NULLIF(v.total, 0), 0), 2))
           ), 0) INTO v_devuelto
      FROM devoluciones d
      JOIN ventas v ON v.id = d.venta_id
     WHERE d.sesion_caja_id = p_sesion_id;

    SET v_esperado = v_inicial + v_ventas + v_ingresos - v_egresos - v_devuelto;

    -- `diferencia` es columna generada: sale sola de esperado y declarado
    UPDATE sesiones_caja
       SET fecha_cierre      = NOW(),
           usuario_cierre_id = p_usuario_id,
           monto_esperado    = v_esperado,
           monto_declarado   = p_declarado,
           estado            = 'CERRADA',
           -- en su propia columna: la nota de apertura no se pisa
           observacion_cierre = p_observacion
     WHERE id = p_sesion_id;

    SELECT v_esperado AS monto_esperado,
           p_declarado AS monto_declarado,
           ROUND(p_declarado - v_esperado, 2) AS diferencia;
END$$

DELIMITER ;

-- =============================================================================
--  12. VISTAS DE APOYO A REPORTES
-- =============================================================================

-- Personal: la persona, su cargo y (si la tiene) su cuenta de acceso con su rol
CREATE OR REPLACE VIEW v_empleados AS
SELECT e.id AS empleado_id, e.documento, e.nombre_completo,
       c.nombre  AS cargo,
       e.fecha_ingreso, e.fecha_cese, e.tipo_contrato, e.estado AS estado_laboral,
       u.id      AS usuario_id,
       u.usuario,
       r.nombre  AS rol_acceso,
       u.activo  AS acceso_habilitado,
       u.ultimo_acceso
  FROM empleados e
  JOIN cargos c   ON c.id = e.cargo_id
  LEFT JOIN usuarios u ON u.empleado_id = e.id
  LEFT JOIN roles r    ON r.id = u.rol_id;

-- Productos en o por debajo del stock mínimo (HU-22)
CREATE OR REPLACE VIEW v_alertas_stock AS
SELECT p.id, p.codigo, p.nombre, c.nombre AS categoria,
       p.stock_actual, p.stock_minimo,
       (p.stock_minimo - p.stock_actual) AS faltante
  FROM productos p
  JOIN categorias c ON c.id = p.categoria_id
 WHERE p.activo = 1
   AND p.stock_actual <= p.stock_minimo;

-- Ventas por día (HU-31, HU-32)
CREATE OR REPLACE VIEW v_ventas_por_dia AS
SELECT DATE(v.fecha)      AS dia,
       COUNT(*)           AS cantidad_ventas,
       SUM(v.total)       AS monto_total,
       ROUND(AVG(v.total), 2) AS ticket_promedio
  FROM ventas v
 WHERE v.estado <> 'ANULADA'
 GROUP BY DATE(v.fecha);

-- Productos más vendidos (HU-33)
-- Las tres cifras son NETAS de devoluciones: lo que el negocio se quedó.
-- El importe de la línea se prorratea por la fracción no devuelta, igual que
-- `sp_recalcular_venta` prorratea el impuesto; así el descuento de línea se
-- reparte solo y no hace falta repetir su fórmula.
-- Un producto devuelto por completo queda en cero, no con unidades cero e
-- importe entero.
-- Neto del descuento de la venta (repartido entre sus líneas) y de lo
-- devuelto, y con el costo que tenía el producto el día que se vendió.
CREATE OR REPLACE VIEW v_productos_mas_vendidos AS
SELECT p.id, p.codigo, p.nombre, c.nombre AS categoria,
       SUM(n.unidades_netas)                                     AS unidades_vendidas,
       SUM(n.monto_neto)                                         AS monto_vendido,
       SUM(n.monto_neto - ROUND(n.unidades_netas * n.costo, 2))  AS margen_estimado
  FROM (
        SELECT d.producto_id,
               (d.cantidad - d.cantidad_devuelta) AS unidades_netas,
               ROUND(d.importe
                     * IF(v.subtotal > 0, (v.subtotal - v.descuento) / v.subtotal, 1)
                     * IF(d.cantidad > 0, (d.cantidad - d.cantidad_devuelta) / d.cantidad, 0), 2) AS monto_neto,
               COALESCE(d.costo_unitario, pc.precio_compra) AS costo
          FROM venta_detalle d
          JOIN ventas v ON v.id = d.venta_id AND v.estado <> 'ANULADA'
          JOIN productos pc ON pc.id = d.producto_id
       ) AS n
  JOIN productos  p ON p.id = n.producto_id
  JOIN categorias c ON c.id = p.categoria_id
 GROUP BY p.id, p.codigo, p.nombre, c.nombre;

-- Kardex legible por producto (HU-21)
-- Con FK por origen el kardex puede mostrar el documento real, no un par (tabla, id)
CREATE OR REPLACE VIEW v_kardex AS
SELECT m.id, m.fecha, p.codigo, p.nombre AS producto,
       m.tipo, m.origen, m.cantidad, m.stock_anterior, m.stock_resultante,
       e.nombre_completo AS usuario,
       CASE m.origen
            WHEN 'VENTA'      THEN co.numero_completo
            WHEN 'ANULACION'  THEN co.numero_completo
            WHEN 'DEVOLUCION' THEN CONCAT('DEV-', LPAD(m.devolucion_id, 6, '0'))
            WHEN 'COMPRA'     THEN CONCAT_WS(' ', pr.razon_social, m.documento_externo)
            -- La nota de crédito cuando la hay, y el número del documento
            -- interno cuando todavía no llegó: un movimiento del kardex sin
            -- papel que señalar no se puede contrastar contra nada.
            WHEN 'DEVOLUCION_COMPRA' THEN CONCAT_WS(' ', pr.razon_social,
                     COALESCE(m.documento_externo, CONCAT('DEVC-', LPAD(m.devolucion_compra_id, 6, '0'))))
            ELSE NULL
       END AS documento,
       m.venta_id, m.devolucion_id, m.proveedor_id, m.devolucion_compra_id, m.motivo
  FROM movimientos_inventario m
  JOIN productos p ON p.id = m.producto_id
  LEFT JOIN usuarios u     ON u.id  = m.usuario_id
  LEFT JOIN empleados e    ON e.id  = u.empleado_id
  LEFT JOIN proveedores pr ON pr.id = m.proveedor_id
  LEFT JOIN comprobantes co ON co.venta_id = m.venta_id AND co.estado <> 'SUSTITUIDO';

-- Ventas con su comprobante y el tipo de cliente (listados y reportes)
-- Se une solo al comprobante vigente o anulado: los SUSTITUIDOS son historial y
-- duplicarían la venta en los listados.
CREATE OR REPLACE VIEW v_ventas_comprobante AS
SELECT v.id AS venta_id, v.fecha, v.estado AS estado_venta,
       v.subtotal, v.descuento, v.impuesto, v.total,
       co.numero_completo, tc.codigo AS tipo_documento, tc.nombre AS nombre_documento,
       co.estado AS estado_comprobante,
       co.tipo_persona,
       co.cliente_nombre, co.cliente_tipo_documento, co.cliente_documento,
       co.sustituye_a,
       e.nombre_completo AS cajero, cg.nombre AS cargo_cajero
  FROM ventas v
  LEFT JOIN comprobantes co       ON co.venta_id = v.id AND co.estado <> 'SUSTITUIDO'
  LEFT JOIN series_comprobante s  ON s.id  = co.serie_id
  LEFT JOIN tipos_comprobante tc  ON tc.id = s.tipo_comprobante_id
  JOIN usuarios  u  ON u.id  = v.usuario_id
  JOIN empleados e  ON e.id  = u.empleado_id
  JOIN cargos    cg ON cg.id = e.cargo_id;

-- Cadena de sustituciones: qué documento reemplazó a cuál, quién y por qué (auditoría)
CREATE OR REPLACE VIEW v_comprobantes_sustituidos AS
SELECT ant.numero_completo AS documento_anterior,
       tca.codigo          AS tipo_anterior,
       ant.sustituido_en,
       nue.numero_completo AS documento_nuevo,
       tcn.codigo          AS tipo_nuevo,
       nue.fecha_emision   AS emitido_en,
       nue.cliente_nombre,
       nue.motivo_emision,
       e.nombre_completo AS emitido_por,
       ant.venta_id
  FROM comprobantes nue
  JOIN comprobantes ant       ON ant.id  = nue.sustituye_a
  JOIN series_comprobante sa  ON sa.id   = ant.serie_id
  JOIN tipos_comprobante tca  ON tca.id  = sa.tipo_comprobante_id
  JOIN series_comprobante sn  ON sn.id   = nue.serie_id
  JOIN tipos_comprobante tcn  ON tcn.id  = sn.tipo_comprobante_id
  LEFT JOIN usuarios u        ON u.id    = nue.emitido_por
  LEFT JOIN empleados e       ON e.id    = u.empleado_id;

-- Facturación por tipo de documento y tipo de persona (reporte contable)
CREATE OR REPLACE VIEW v_comprobantes_emitidos AS
SELECT DATE(co.fecha_emision) AS dia,
       tc.codigo   AS tipo_documento,
       co.tipo_persona,
       COUNT(*)    AS cantidad,
       SUM(co.subtotal) AS base_imponible,
       SUM(co.impuesto) AS impuesto,
       SUM(co.total)    AS total
  FROM comprobantes co
  JOIN series_comprobante s ON s.id  = co.serie_id
  JOIN tipos_comprobante tc ON tc.id = s.tipo_comprobante_id
 WHERE co.estado = 'EMITIDO'
 GROUP BY DATE(co.fecha_emision), tc.codigo, co.tipo_persona;

-- Ventas por método de pago (HU-32, cierre de caja)
CREATE OR REPLACE VIEW v_ventas_por_metodo_pago AS
SELECT DATE(v.fecha) AS dia, mp.nombre AS metodo_pago,
       COUNT(DISTINCT v.id) AS cantidad_ventas,
       SUM(vp.monto) AS monto
  FROM venta_pagos vp
  JOIN ventas v        ON v.id  = vp.venta_id AND v.estado <> 'ANULADA'
  JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id
 GROUP BY DATE(v.fecha), mp.nombre;
