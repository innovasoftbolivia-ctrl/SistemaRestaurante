-- =============================================================================
--  SISTEMA DEL RESTAURANTE (pedidos de mostrador, cocina, caja y comprobantes)
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
--   cargo     -> qué hace la persona en el negocio (Cajero, Cocinero, Gerente)
--   empleado  -> la persona y su vínculo laboral (ingreso, cese, contrato)
--   usuario   -> la cuenta con la que entra al sistema, y su rol de acceso
-- Un empleado puede no tener usuario (trabaja pero no usa el sistema).
-- Un usuario siempre pertenece a un empleado.

-- Los documentos de identidad: CI, NIT, carné de extranjería... Hasta el
-- 2026-09-19 eran tres ENUM repetidos (clientes, empleados y comprobantes).
-- La clave es el código, el mismo que se imprime y se busca: clientes y
-- empleados siguen guardando `CI` o `NIT`, y un documento nuevo es una fila y
-- no un ALTER. `aplica_*` dice para quién vale cada uno, y clientes y empleados
-- lo exigen con una FK compuesta contra (codigo, aplica_*) —ver sus columnas
-- `tipodoc_*`—: la regla vive en esta tabla y en ningún otro lado.
-- `comprobantes.cliente_tipo_documento` NO apunta aquí: es la foto del código
-- tal como era al emitir.
CREATE TABLE tipos_documento (
    codigo          VARCHAR(5)   NOT NULL,
    nombre          VARCHAR(60)  NOT NULL,              -- como se ofrece en pantalla
    aplica_natural  TINYINT(1)   NOT NULL DEFAULT 0,    -- cliente persona natural
    aplica_juridica TINYINT(1)   NOT NULL DEFAULT 0,    -- cliente persona jurídica
    aplica_empleado TINYINT(1)   NOT NULL DEFAULT 0,
    orden           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (codigo),
    UNIQUE KEY uq_tipodoc_natural  (codigo, aplica_natural),
    UNIQUE KEY uq_tipodoc_juridica (codigo, aplica_juridica),
    UNIQUE KEY uq_tipodoc_empleado (codigo, aplica_empleado)
) ENGINE=InnoDB;

-- Los de Bolivia. El NIT vale también para la persona natural unipersonal
-- (recibe factura a su nombre); la empresa solo se identifica con NIT.
INSERT INTO tipos_documento (codigo, nombre, aplica_natural, aplica_juridica, aplica_empleado, orden) VALUES
    ('CI',  'CI (cédula de identidad)', 1, 0, 1, 1),
    ('NIT', 'NIT',                      1, 1, 0, 2),
    ('CE',  'Carné de extranjería',     1, 0, 1, 3),
    ('PAS', 'Pasaporte',                1, 0, 1, 4),
    ('SIN', 'Sin documento',            1, 0, 0, 5);

CREATE TABLE cargos (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre          VARCHAR(50)  NOT NULL,      -- Cajero, Cocinero, Ayudante...
    descripcion     VARCHAR(150) NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cargos_nombre (nombre)
) ENGINE=InnoDB;

CREATE TABLE empleados (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cargo_id            INT UNSIGNED NOT NULL,
    tipo_documento      VARCHAR(5)   NOT NULL DEFAULT 'CI',
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
    -- Siempre 1: con `tipo_documento` forma la FK que exige un documento que
    -- valga para empleados (`tipos_documento.aplica_empleado`).
    tipodoc_empleado    TINYINT(1)   GENERATED ALWAYS AS (1) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_empleados_documento (tipo_documento, documento),
    KEY ix_empleados_cargo  (cargo_id),
    KEY ix_empleados_estado (estado),
    KEY ix_empleados_nombre (nombre_completo),
    KEY ix_empleados_tipodoc (tipo_documento, tipodoc_empleado),
    CONSTRAINT fk_empleados_cargo FOREIGN KEY (cargo_id) REFERENCES cargos (id),
    CONSTRAINT fk_empleados_tipodoc FOREIGN KEY (tipo_documento, tipodoc_empleado)
        REFERENCES tipos_documento (codigo, aplica_empleado),
    -- cesado exige fecha de cese, y una fecha de cese exige estado cesado
    CONSTRAINT ck_empleados_cese CHECK (
        (estado =  'CESADO' AND fecha_cese IS NOT NULL) OR
        (estado <> 'CESADO' AND fecha_cese IS NULL)
    ),
    CONSTRAINT ck_empleados_fechas CHECK (fecha_cese IS NULL OR fecha_cese >= fecha_ingreso)
) ENGINE=InnoDB;

CREATE TABLE roles (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
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
    rol_id          INT  UNSIGNED NOT NULL,
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
    rol_id              INT UNSIGNED NOT NULL,
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

-- `pasa_por_cocina`: si lo de esta categoría se prepara en la cocina. Las
-- bebidas no: se cobran igual, pero no van a la pantalla de la cocina ni a la
-- comanda. Cada línea del pedido lo copia al pedirse (`pedido_detalle`), así
-- que cambiarlo no mueve lo ya pedido.
CREATE TABLE categorias (
    id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre          VARCHAR(60)  NOT NULL,
    descripcion     VARCHAR(200) NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    pasa_por_cocina TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categorias_nombre (nombre)
) ENGINE=InnoDB;

-- El negocio no lleva inventario: no hay stock, ni lotes, ni proveedores. Un
-- producto es lo que se sirve y a qué precio, y ese precio es uno solo: el
-- plato se hace en la casa, no se compra, así que no hay costo que registrar
-- ni margen que calcular.
--
-- Tampoco lleva código de barras: nadie escanea un plato. Lo que se teclea
-- para encontrarlo rápido es el código interno, y por eso ese sí se queda.
--
-- Tampoco lleva unidad de medida: todo se despacha por porción, así que la
-- cantidad es siempre entera y la valida la aplicación (ver `Ventas` y
-- `Pedidos`). Una unidad por producto solo obligaba a elegir «UND» en cada
-- plato de la carta para no volver a mirarla nunca.
CREATE TABLE productos (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    categoria_id        SMALLINT UNSIGNED NOT NULL,
    codigo              VARCHAR(30)  NOT NULL,          -- código interno / SKU
    nombre              VARCHAR(120) NOT NULL,
    descripcion         VARCHAR(255) NULL,
    -- Lo que significa depende de `configuracion.precios_incluyen_impuesto`:
    -- con 0 es la base SIN impuesto (el impuesto se suma encima al vender);
    -- con 1 es el precio final, con el impuesto ya dentro. Cada venta guarda
    -- el modo con que se calculó (`ventas.impuesto_incluido`).
    precio_venta        DECIMAL(12,2) NOT NULL,
    afecto_impuesto     TINYINT(1)   NOT NULL DEFAULT 1,       -- 1 = se le agrega el impuesto al vender
    imagen              VARCHAR(255) NULL,
    activo              TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_productos_codigo  (codigo),
    KEY ix_productos_categoria (categoria_id),
    KEY ix_productos_nombre    (nombre),
    KEY ix_productos_activo    (activo),
    CONSTRAINT fk_productos_categoria FOREIGN KEY (categoria_id)     REFERENCES categorias (id),
    CONSTRAINT ck_productos_precios   CHECK (precio_venta >= 0)
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
    tipo_documento      VARCHAR(5)   NOT NULL DEFAULT 'CI',
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
    -- 1 en la columna de su tipo de persona y NULL en la otra: con
    -- `tipo_documento` forman las FK que exigen un documento que valga para
    -- ese tipo (`tipos_documento.aplica_natural` / `aplica_juridica`). La FK
    -- con NULL no se comprueba, así que cada cliente pasa por la suya.
    tipodoc_natural     TINYINT(1)   GENERATED ALWAYS AS (IF(tipo_persona = 'NATURAL', 1, NULL)) STORED,
    tipodoc_juridica    TINYINT(1)   GENERATED ALWAYS AS (IF(tipo_persona = 'JURIDICA', 1, NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_clientes_documento (tipo_documento, documento),
    KEY ix_clientes_nombre  (nombre),
    KEY ix_clientes_persona (tipo_persona),
    KEY ix_clientes_tipodoc_natural  (tipo_documento, tipodoc_natural),
    KEY ix_clientes_tipodoc_juridica (tipo_documento, tipodoc_juridica),
    CONSTRAINT fk_clientes_tipodoc_natural  FOREIGN KEY (tipo_documento, tipodoc_natural)
        REFERENCES tipos_documento (codigo, aplica_natural),
    CONSTRAINT fk_clientes_tipodoc_juridica FOREIGN KEY (tipo_documento, tipodoc_juridica)
        REFERENCES tipos_documento (codigo, aplica_juridica),
    -- Qué documento vale para cada tipo de persona lo dice `tipos_documento`.
    -- Una persona natural puede tener NIT (unipersonal, profesional
    -- independiente): con él recibe factura a su nombre, y entonces el número
    -- es obligatorio.
    CONSTRAINT ck_clientes_natural CHECK (
        tipo_persona <> 'NATURAL' OR (
            nombres   IS NOT NULL AND
            apellidos IS NOT NULL AND
            razon_social IS NULL  AND
            (tipo_documento <> 'NIT' OR documento IS NOT NULL)
        )
    ),
    CONSTRAINT ck_clientes_juridica CHECK (
        tipo_persona <> 'JURIDICA' OR (
            razon_social IS NOT NULL AND
            documento    IS NOT NULL AND
            direccion    IS NOT NULL AND
            nombres      IS NULL     AND
            apellidos    IS NULL
        )
    ),
    -- «Sin documento» es eso: no lleva número.
    CONSTRAINT ck_clientes_sin_doc CHECK (tipo_documento <> 'SIN' OR documento IS NULL)
) ENGINE=InnoDB;

-- =============================================================================
--  4. CAJA Y TURNOS
-- =============================================================================

CREATE TABLE cajas (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre          VARCHAR(40) NOT NULL,       -- "Caja 1"
    ubicacion       VARCHAR(60) NULL,
    activo          TINYINT(1)  NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cajas_nombre (nombre)
) ENGINE=InnoDB;

CREATE TABLE sesiones_caja (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    caja_id             INT UNSIGNED NOT NULL,
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
    CONSTRAINT ck_sesion_inicial   CHECK (monto_inicial >= 0),
    -- Abierta es no tener fecha de cierre; cerrada es tener quién cerró y el
    -- arqueo firmado. Sin triggers (LOGICA_EN_PHP) son la única guarda.
    CONSTRAINT ck_sesion_cierre    CHECK ((estado = 'ABIERTA') = (fecha_cierre IS NULL)),
    CONSTRAINT ck_sesion_cerrada   CHECK (estado <> 'CERRADA' OR (usuario_cierre_id IS NOT NULL
                                          AND monto_esperado IS NOT NULL AND monto_declarado IS NOT NULL)),
    -- Lo que queda en el cajón sale de lo contado: ni negativo ni más.
    CONSTRAINT ck_sesion_fondo     CHECK (fondo_dejado IS NULL OR (fondo_dejado >= 0 AND fondo_dejado <= monto_declarado)),
    -- El efectivo contado al cerrar no puede ser negativo.
    CONSTRAINT ck_sesion_declarado CHECK (monto_declarado IS NULL OR monto_declarado >= 0)
) ENGINE=InnoDB;

-- Solo una sesión ABIERTA por caja: se garantiza con esta columna generada + índice único.
ALTER TABLE sesiones_caja
    ADD COLUMN caja_abierta_uk INT UNSIGNED
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
--
-- `serie_por_omision_id`: la serie con que se numera cada tipo. Antes eran dos
-- claves de texto en `configuracion` (`serie_factura`, `serie_recibo`) —claves
-- foráneas sin FK— y la de la nota de venta salía de otra consulta. La FK es
-- compuesta, (serie, tipo) contra `series_comprobante (id, tipo_comprobante_id)`:
-- así la base impide elegir la serie de los recibos como serie de facturas.
-- La clave se agrega más abajo, cuando `series_comprobante` ya existe.
CREATE TABLE tipos_comprobante (
    id              TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo          VARCHAR(10) NOT NULL,   -- FAC, REC, NV
    nombre          VARCHAR(40) NOT NULL,
    aplica_persona  ENUM('NATURAL','JURIDICA','AMBAS') NOT NULL DEFAULT 'AMBAS',
    exige_cliente   TINYINT(1)  NOT NULL DEFAULT 0,
    exige_documento TINYINT(1)  NOT NULL DEFAULT 0,
    activo          TINYINT(1)  NOT NULL DEFAULT 1,
    serie_por_omision_id SMALLINT UNSIGNED NULL,
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
    CONSTRAINT fk_series_tipo FOREIGN KEY (tipo_comprobante_id) REFERENCES tipos_comprobante (id),
    -- El número impreso nunca se corta: LPAD truncaba el 1000000 a 100000, un
    -- número repetido. Al llegar al tope, emitir falla en vez de repetir.
    -- serie (6) + guion + número (13) = los 20 de `numero_completo`.
    CONSTRAINT ck_series_longitud CHECK (longitud BETWEEN 1 AND 13),
    CONSTRAINT ck_series_serie    CHECK (CHAR_LENGTH(serie) BETWEEN 1 AND 6),
    CONSTRAINT ck_series_tope     CHECK (correlativo_actual < POW(10, longitud))
) ENGINE=InnoDB;

-- La serie por omisión de cada tipo, y que sea de ESE tipo.
ALTER TABLE series_comprobante
    ADD UNIQUE KEY uq_series_id_tipo (id, tipo_comprobante_id);
ALTER TABLE tipos_comprobante
    ADD KEY ix_tipocomp_serie (serie_por_omision_id, id),
    ADD CONSTRAINT fk_tipocomp_serie FOREIGN KEY (serie_por_omision_id, id)
        REFERENCES series_comprobante (id, tipo_comprobante_id);

CREATE TABLE metodos_pago (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
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
--
-- `pedido_id` es el pedido que cobró la venta (ver 7. PEDIDOS). La clave vive
-- aquí y no en `pedidos`, porque un pedido puede tener varias ventas —la que
-- se anuló y la que lo volvió a cobrar— y la anulada tiene que seguir diciendo
-- de qué pedido era. Una sola VIGENTE por pedido: lo garantiza
-- `uq_venta_pedido_cobrado`. NULL solo en las ventas de antes de los pedidos.
-- El cliente es de la venta: el pedido no guarda uno propio.
CREATE TABLE ventas (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cliente_id          INT UNSIGNED NULL,          -- NULL = cliente varios
    usuario_id          INT UNSIGNED NOT NULL,      -- cajero que vendió
    sesion_caja_id      INT UNSIGNED NOT NULL,
    pedido_id           BIGINT UNSIGNED NULL,       -- el pedido que cobró
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
    -- Una venta mal cobrada se anula entera, con su motivo y su responsable:
    -- no hay devoluciones parciales. Es lo que hace un restaurante, donde lo
    -- que se sirvió no vuelve a la carta.
    estado              ENUM('COMPLETADA','ANULADA') NOT NULL DEFAULT 'COMPLETADA',
    observacion         VARCHAR(255) NULL,
    -- anulación
    anulada_en          DATETIME     NULL,
    anulada_por         INT UNSIGNED NULL,
    motivo_anulacion    VARCHAR(255) NULL,
    creado_en           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Una sola venta vigente por pedido: la columna vale el pedido mientras la
    -- venta está COMPLETADA y NULL si se anuló (las anuladas no ocupan lugar).
    pedido_cobrado_uk   BIGINT UNSIGNED
                        GENERATED ALWAYS AS (IF(estado = 'COMPLETADA', pedido_id, NULL)) VIRTUAL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_venta_pedido_cobrado (pedido_cobrado_uk),
    KEY ix_ventas_fecha    (fecha),
    KEY ix_ventas_cliente  (cliente_id),
    KEY ix_ventas_sesion   (sesion_caja_id),
    KEY ix_ventas_estado   (estado, fecha),
    CONSTRAINT fk_ventas_cliente FOREIGN KEY (cliente_id)     REFERENCES clientes (id),
    CONSTRAINT fk_ventas_usuario FOREIGN KEY (usuario_id)     REFERENCES usuarios (id),
    CONSTRAINT fk_ventas_sesion  FOREIGN KEY (sesion_caja_id) REFERENCES sesiones_caja (id),
    CONSTRAINT fk_ventas_anulada FOREIGN KEY (anulada_por)    REFERENCES usuarios (id),
    CONSTRAINT ck_ventas_montos  CHECK (subtotal >= 0 AND descuento >= 0 AND impuesto >= 0
                                        AND descuento <= subtotal),
    -- Anulada es tener cuándo, quién y por qué; una completada no tiene nada de eso.
    CONSTRAINT ck_ventas_anulacion CHECK ((estado = 'ANULADA') = (anulada_en IS NOT NULL)
                                          AND (anulada_en IS NULL) = (anulada_por IS NULL)
                                          AND (anulada_en IS NULL) = (motivo_anulacion IS NULL)),
    -- El descuento sobre el precio final solo existe con el impuesto incluido.
    CONSTRAINT ck_ventas_precio_final CHECK (impuesto_incluido = 1 OR descuento_precio_final IS NULL)
) ENGINE=InnoDB;

CREATE TABLE venta_detalle (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    venta_id            BIGINT UNSIGNED NOT NULL,
    producto_id         INT UNSIGNED NOT NULL,
    descripcion         VARCHAR(120)  NOT NULL,      -- copia histórica del nombre
    cantidad            DECIMAL(12,3) NOT NULL,
    precio_unitario     DECIMAL(12,2) NOT NULL,      -- copia histórica del precio
    -- Sin descuento por línea: el descuento se aplica al total de la venta
    -- (`ventas.descuento`), nunca por plato. La columna valía siempre 0 y se
    -- retiró el 2026-09-19.
    -- Base de la línea, sin impuesto. Con el impuesto incluido en el precio,
    -- es lo cobrado menos el impuesto que lleva adentro.
    importe             DECIMAL(12,2) GENERATED ALWAYS AS
                        (ROUND(cantidad * precio_unitario, 2) - IF(impuesto_incluido = 1, ROUND(ROUND(cantidad * precio_unitario, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0) / (1 + IF(afecto_impuesto = 1, tasa_impuesto, 0)), 2), 0)) STORED,
    -- Desglose de impuesto por línea: es lo que imprime la FACTURA.
    -- Se copian del producto, de la configuración y de la venta al insertar
    -- (ver trigger BEFORE INSERT).
    afecto_impuesto     TINYINT(1)    NOT NULL DEFAULT 1,
    tasa_impuesto       DECIMAL(6,4)  NOT NULL DEFAULT 0.0000,
    -- 1 = el precio unitario ya incluye el impuesto (se calcula «por dentro»).
    impuesto_incluido   TINYINT(1)    NOT NULL DEFAULT 0,
    impuesto_linea      DECIMAL(12,2) GENERATED ALWAYS AS
                        (IF(impuesto_incluido = 1, ROUND(ROUND(cantidad * precio_unitario, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0) / (1 + IF(afecto_impuesto = 1, tasa_impuesto, 0)), 2), ROUND(ROUND(cantidad * precio_unitario, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0), 2))) STORED,
    -- Lo que paga el cliente por la línea.
    total_linea         DECIMAL(12,2) GENERATED ALWAYS AS
                        (ROUND(cantidad * precio_unitario, 2) + IF(impuesto_incluido = 1, 0, ROUND(ROUND(cantidad * precio_unitario, 2) * IF(afecto_impuesto = 1, tasa_impuesto, 0), 2))) STORED,
    PRIMARY KEY (id),
    -- Un producto, una sola línea por venta. El mostrador ya lo exigía, pero la
    -- regla vivía solo en la validación HTTP, y con el mismo producto repetido
    -- en dos líneas los recuentos por producto (anulación, ranking) contaban una
    -- sola de ellas.
    -- (Sin un índice aparte por `venta_id`: este lo cubre, porque empieza por ella.)
    UNIQUE KEY uq_detalle_venta_producto (venta_id, producto_id),
    KEY ix_detalle_producto (producto_id),
    -- RESTRICT y no CASCADE: una venta no se borra nunca (se anula), y sin
    -- triggers (LOGICA_EN_PHP) nada impediría que un DELETE se llevara el detalle.
    CONSTRAINT fk_detalle_venta    FOREIGN KEY (venta_id)    REFERENCES ventas (id) ON DELETE RESTRICT,
    CONSTRAINT fk_detalle_producto FOREIGN KEY (producto_id) REFERENCES productos (id),
    CONSTRAINT ck_detalle_cantidad CHECK (cantidad > 0),
    CONSTRAINT ck_detalle_precio   CHECK (precio_unitario >= 0)
) ENGINE=InnoDB;

CREATE TABLE venta_pagos (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    venta_id        BIGINT UNSIGNED NOT NULL,
    metodo_pago_id  INT UNSIGNED NOT NULL,
    monto           DECIMAL(12,2) NOT NULL,   -- lo que se aplica a la venta
    monto_recibido  DECIMAL(12,2) NULL,       -- lo que entregó el cliente (solo efectivo)
    -- derivada de las dos anteriores: columna generada (3FN)
    vuelto          DECIMAL(12,2) GENERATED ALWAYS AS
                    (IF(monto_recibido IS NULL, 0.00, ROUND(monto_recibido - monto, 2))) STORED,
    referencia      VARCHAR(60)   NULL,       -- nro de operación / voucher
    PRIMARY KEY (id),
    KEY ix_pagos_venta  (venta_id),
    KEY ix_pagos_metodo (metodo_pago_id),
    CONSTRAINT fk_pagos_venta  FOREIGN KEY (venta_id)       REFERENCES ventas (id) ON DELETE RESTRICT,
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
--  emitió su comprobante y ya entró al arqueo del turno; si el cliente entonces
--  no paga, queda una venta fantasma que nadie cobró.
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
    ),
    -- Solo un cobro pagado respalda una venta.
    CONSTRAINT ck_cobros_qr_venta CHECK (venta_id IS NULL OR estado = 'PAGADO'),
    -- Lo que se confirmó a mano tiene nombre: es por donde se colaría un cobro que no entró.
    CONSTRAINT ck_cobros_qr_manual CHECK (confirmado_por <> 'MANUAL' OR confirmado_por_id IS NOT NULL)
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
    -- foto de los datos del negocio al emitir: si mañana cambia el nombre, el
    -- NIT o la dirección, lo ya emitido se sigue imprimiendo como se entregó
    emisor_nombre           VARCHAR(120) NULL,
    emisor_documento        VARCHAR(20)  NULL,
    emisor_direccion        VARCHAR(200) NULL,
    emisor_telefono         VARCHAR(30)  NULL,
    -- foto de los datos del cliente al emitir
    cliente_id              INT UNSIGNED NULL,
    tipo_persona            ENUM('NATURAL','JURIDICA') NULL,
    cliente_nombre          VARCHAR(150) NOT NULL,      -- razón social o nombre completo
    -- El código tal como era al emitir (CI, NIT...), sin FK a `tipos_documento`:
    -- es una foto, no una referencia.
    cliente_tipo_documento  VARCHAR(5)   NOT NULL DEFAULT 'SIN',
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
    emitido_por             INT UNSIGNED NOT NULL,      -- usuario que emitió
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
    UNIQUE KEY uq_comprobante_sustituye (sustituye_a),  -- se sustituye una sola vez
    CONSTRAINT fk_comprobante_venta   FOREIGN KEY (venta_id)            REFERENCES ventas (id),
    CONSTRAINT fk_comprobante_serie   FOREIGN KEY (serie_id)            REFERENCES series_comprobante (id),
    CONSTRAINT fk_comprobante_cliente FOREIGN KEY (cliente_id)          REFERENCES clientes (id),
    CONSTRAINT fk_comprobante_sustit  FOREIGN KEY (sustituye_a)         REFERENCES comprobantes (id),
    CONSTRAINT fk_comprobante_usuario FOREIGN KEY (emitido_por)         REFERENCES usuarios (id),
    CONSTRAINT ck_comprobante_montos  CHECK (subtotal >= 0 AND descuento >= 0 AND impuesto >= 0
                                             AND descuento <= subtotal),
    -- El estado y su fecha son lo mismo dicho dos veces: no pueden contradecirse.
    CONSTRAINT ck_comprobante_anulado    CHECK ((estado = 'ANULADO') = (anulado_en IS NOT NULL)),
    CONSTRAINT ck_comprobante_sustituido CHECK ((estado = 'SUSTITUIDO') = (sustituido_en IS NOT NULL)),
    -- El tipo de persona es el del cliente: sin cliente, no hay.
    CONSTRAINT ck_comprobante_persona CHECK ((cliente_id IS NULL) = (tipo_persona IS NULL))
) ENGINE=InnoDB;

-- =============================================================================
--  7. PEDIDOS
-- =============================================================================
--
--  El local atiende en el mostrador: el cliente pide y paga en la caja, se
--  lleva un ticket con el número del pedido, se sienta donde quiera (no hay
--  mesas numeradas) o espera para llevar, la cocina prepara, y quien lleva
--  los platos canta el número. El pedido se toma y se cobra en el mismo acto
--  (`App\Services\Pedidos::venderEnMostrador`). Cobrar NO es un circuito
--  aparte: se traduce el pedido a una venta normal (`Pedidos::cobrar`), con
--  sus pagos, su comprobante y su efecto en el arqueo.
--
--  El local no tiene mesas ni cuentas abiertas: se cobra al pedir, y el pedido
--  solo queda abierto cuando su cobro se anuló y hay que rehacerlo.

-- Un pedido, para comer aquí (LOCAL) o para llevar.
--
-- No guarda su venta: la venta apunta al pedido (`ventas.pedido_id`), porque
-- un pedido puede tener varias —la anulada y la que lo volvió a cobrar— y una
-- sola vigente (`uq_venta_pedido_cobrado`): el mismo pedido no se cobra dos
-- veces ni aunque dos cajeros pulsen a la vez. Tampoco guarda cliente: el
-- cliente es de la venta, y al pedido le basta un nombre para llamarlo.
--
-- «CERRADO ⇔ tiene una venta COMPLETADA» ya no cabe en un CHECK, porque son
-- dos tablas. Lo garantiza la aplicación (`App\Services\Pedidos`): cierra el
-- pedido en la misma transacción en que registra su venta y lo reabre en la
-- misma en que la anula.
--
-- Solo queda ABIERTO —por cobrar— el que se reabrió al anular su venta, para
-- cobrarlo de nuevo con su mismo número (el cliente ya tiene el ticket) o
-- cancelarlo.
CREATE TABLE pedidos (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- LOCAL es «comer aquí», sentado donde quiera; LLEVAR, «para llevar».
    tipo                ENUM('LOCAL','LLEVAR') NOT NULL DEFAULT 'LOCAL',
    -- El número que se canta en la barra: 1, 2, 3… y vuelve a 1 en cada
    -- jornada. El `id` sigue siendo la clave y lo que va en las URLs, pero no
    -- se le puede leer en voz alta a nadie: a los pocos meses va por «4812».
    -- Lo asigna `App\Services\Pedidos::abrir()` bajo el bloqueo de
    -- `uq_pedido_numero_dia`.
    numero_dia          SMALLINT UNSIGNED NOT NULL,
    -- La jornada del local a la que pertenece el pedido: la fecha de la
    -- apertura menos `configuracion.hora_corte_jornada` horas (5 por omisión).
    -- El local cierra pasada la medianoche, y el pedido de la 01:30 del 19 es
    -- de la noche del 18: sigue su numeración, no empieza otra. La escribe
    -- `Pedidos::abrir()`; no es una columna generada porque una columna
    -- generada no puede leer la configuración.
    jornada             DATE NOT NULL,
    usuario_id          INT UNSIGNED NOT NULL,       -- quien lo abrió
    -- Alcanza con un nombre para llamarlo cuando esté. El cliente registrado,
    -- si lo hay, es de la venta.
    nombre_cliente      VARCHAR(80)  NULL,
    estado              ENUM('ABIERTO','CERRADO','CANCELADO') NOT NULL DEFAULT 'ABIERTO',
    observacion         VARCHAR(255) NULL,
    motivo_cancelacion  VARCHAR(255) NULL,
    fecha_apertura      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_cierre        DATETIME     NULL,
    cerrado_por         INT UNSIGNED NULL,
    creado_en           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_pedidos_estado (estado, fecha_apertura),
    KEY ix_pedidos_usuario (usuario_id),
    -- Dos cajeros abriendo un pedido en el mismo segundo no pueden sacar el
    -- mismo número: quien lo garantiza es este índice, no el SELECT de PHP. El
    -- servicio toma el siguiente con `FOR UPDATE` y, si aun así choca,
    -- reintenta —igual que `Ventas::registrar()` ante un deadlock.
    UNIQUE KEY uq_pedido_numero_dia (jornada, numero_dia),
    CONSTRAINT fk_pedidos_usuario FOREIGN KEY (usuario_id)  REFERENCES usuarios (id),
    CONSTRAINT fk_pedidos_cierre  FOREIGN KEY (cerrado_por) REFERENCES usuarios (id),
    -- Abierto es no tener fecha de cierre: son la misma cosa dicha dos veces.
    CONSTRAINT ck_pedidos_cierre CHECK ((estado = 'ABIERTO') = (fecha_cierre IS NULL)),
    -- Y cerrado es tener quién lo cerró.
    CONSTRAINT ck_pedidos_cerrador CHECK ((estado = 'ABIERTO') = (cerrado_por IS NULL)),
    CONSTRAINT ck_pedidos_cancel CHECK (estado <> 'CANCELADO' OR motivo_cancelacion IS NOT NULL),
    -- El contador de la jornada empieza en 1: un «pedido 0» no se canta.
    CONSTRAINT ck_pedidos_numero CHECK (numero_dia > 0)
) ENGINE=InnoDB;

-- La venta apunta a su pedido; la clave se agrega aquí porque `ventas` se crea
-- antes que `pedidos`.
ALTER TABLE ventas
    ADD KEY ix_ventas_pedido (pedido_id),
    -- La portada y «mis ventas» filtran por quién vendió y cuándo. La FK a
    -- usuarios usa este mismo índice (su prefijo).
    ADD KEY ix_ventas_usuario (usuario_id, fecha),
    ADD CONSTRAINT fk_ventas_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos (id);

-- Lo que se pidió, línea por línea.
--
-- A propósito SIN el `UNIQUE (pedido_id, producto_id)` que sí tiene
-- `venta_detalle`: dos porciones del mismo plato con notas distintas son dos
-- líneas, cada una con su nota y su estado en cocina. El cobro las agrupa por
-- producto antes de pasarlas a la venta (ver `Pedidos::cobrar`).
CREATE TABLE pedido_detalle (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pedido_id           BIGINT UNSIGNED NOT NULL,
    producto_id         INT UNSIGNED NOT NULL,
    descripcion         VARCHAR(120)  NOT NULL,      -- copia histórica del nombre
    cantidad            DECIMAL(12,3) NOT NULL,
    -- Copiado al pedir: si el precio del menú sube a mitad de turno, el
    -- pedido conserva el que se le cantó al cliente.
    precio_unitario     DECIMAL(12,2) NOT NULL,
    importe             DECIMAL(12,2) GENERATED ALWAYS AS (ROUND(cantidad * precio_unitario, 2)) STORED,
    nota                VARCHAR(255)  NULL,          -- "sin cebolla", "término medio"
    -- Copiado de `categorias.pasa_por_cocina` al pedirse: la gaseosa se cobra
    -- pero no va a la cocina. Lo que no pasa por ella queda PENDIENTE mientras
    -- el pedido está abierto y ENTREGADO al cobrarse (se entrega con el
    -- ticket): nunca «pendiente» para siempre.
    pasa_por_cocina     TINYINT(1)    NOT NULL DEFAULT 1,
    estado_cocina       ENUM('PENDIENTE','EN_PREPARACION','LISTO','ENTREGADO','CANCELADO')
                        NOT NULL DEFAULT 'PENDIENTE',
    usuario_id          INT UNSIGNED NOT NULL,       -- quién la agregó
    actualizado_por     INT UNSIGNED NULL,           -- quién movió su estado
    -- La comanda impresa para la cocina (`App\Services\Comandas`): cuándo salió
    -- esta línea en papel, y cuándo salió el aviso de que se canceló. Sirven
    -- para no volver a mandar lo que ya salió y para avisar en papel lo que se
    -- canceló después de salir: si no, el cocinero lo prepara igual. Una
    -- reimpresión no los toca.
    comandado_en        TIMESTAMP     NULL,
    cancelacion_comandada_en TIMESTAMP NULL,
    creado_en           TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_pedidodet_pedido   (pedido_id),
    KEY ix_pedidodet_producto (producto_id),
    -- La pantalla de cocina lee por estado y en orden de llegada.
    KEY ix_pedidodet_cocina   (estado_cocina, creado_en),
    CONSTRAINT fk_pedidodet_pedido    FOREIGN KEY (pedido_id)       REFERENCES pedidos (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pedidodet_producto  FOREIGN KEY (producto_id)     REFERENCES productos (id),
    CONSTRAINT fk_pedidodet_usuario   FOREIGN KEY (usuario_id)      REFERENCES usuarios (id),
    CONSTRAINT fk_pedidodet_actualiza FOREIGN KEY (actualizado_por) REFERENCES usuarios (id),
    CONSTRAINT ck_pedidodet_cantidad CHECK (cantidad > 0),
    CONSTRAINT ck_pedidodet_precio   CHECK (precio_unitario >= 0),
    -- Todo va por porción. La tabla nació con el restaurante, sin historial
    -- pesado al gramo (a diferencia de `venta_detalle`).
    CONSTRAINT ck_pedidodet_entera   CHECK (cantidad = FLOOR(cantidad))
) ENGINE=InnoDB;

-- =============================================================================
--  8. CONFIGURACIÓN Y AUDITORÍA
-- =============================================================================

-- Clave y valor, todo como texto. Donde el tipo importa, un CHECK por clave lo
-- exige: un valor mal escrito a mano (o por un script) no llega a los cálculos.
-- Las series de los comprobantes ya no viven aquí (ver `tipos_comprobante`),
-- ni el símbolo de la moneda, que sale del código (`Config::simbolo`).
CREATE TABLE configuracion (
    clave           VARCHAR(50)  NOT NULL,
    valor           VARCHAR(255) NOT NULL,
    descripcion     VARCHAR(200) NULL,
    actualizado_en  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (clave),
    CONSTRAINT ck_config_banderas CHECK (clave NOT IN ('precios_incluyen_impuesto', 'exigir_referencia_pago')
                                         OR valor IN ('0', '1')),
    -- Fracción: 0.13 es el 13 %.
    CONSTRAINT ck_config_tasa     CHECK (clave <> 'tasa_impuesto'
                                         OR (valor REGEXP '^[0-9]+([.][0-9]+)?$' AND CAST(valor AS DECIMAL(10,4)) <= 1)),
    CONSTRAINT ck_config_hora     CHECK (clave <> 'hora_corte_jornada'
                                         OR (valor REGEXP '^[0-9]{1,2}$' AND CAST(valor AS UNSIGNED) <= 12)),
    CONSTRAINT ck_config_descuento CHECK (clave <> 'descuento_max_cajero'
                                         OR (valor REGEXP '^[0-9]{1,3}$' AND CAST(valor AS UNSIGNED) <= 100)),
    CONSTRAINT ck_config_dias     CHECK (clave <> 'dias_max_sustitucion' OR valor REGEXP '^[0-9]{1,3}$'),
    CONSTRAINT ck_config_egreso   CHECK (clave <> 'egreso_max_cajero' OR valor REGEXP '^[0-9]{1,10}([.][0-9]{1,2})?$'),
    -- El código ISO, en mayúsculas: es lo que se congela en cada comprobante.
    CONSTRAINT ck_config_moneda   CHECK (clave <> 'moneda_codigo' OR REGEXP_LIKE(valor, '^[A-Z]{3}$', 'c')),
    -- Los datos del negocio y del cliente genérico se copian al comprobante:
    -- tienen que caber en sus columnas, o emitir aborta y no se puede vender.
    CONSTRAINT ck_config_largo    CHECK (CHAR_LENGTH(valor) <= CASE clave
        WHEN 'negocio_nombre' THEN 120 WHEN 'negocio_documento' THEN 20
        WHEN 'negocio_direccion' THEN 200 WHEN 'negocio_telefono' THEN 30
        WHEN 'cliente_generico_nombre' THEN 150 ELSE 255 END)
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
    -- La bitácora se lee de la más nueva a la más vieja y se filtra por acción:
    -- sin estos dos, cada página ordenaba la tabla entera.
    KEY ix_auditoria_fecha   (fecha, id),
    KEY ix_auditoria_accion  (accion, fecha),
    CONSTRAINT fk_auditoria_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;

-- =============================================================================
--  9. TRIGGERS
-- =============================================================================

DELIMITER $$

-- 9.1 Al cesar o suspender a un empleado, su cuenta pierde el acceso.
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

-- 9.2 Al insertar una línea de venta: copiar del producto el régimen de impuesto
--      y la tasa vigente, para que la factura tenga su desglose por línea.
--      Siempre: lo que venga en el INSERT no manda. Antes una tasa explícita
--      (> 0) se respetaba; nadie la usaba y solo servía para que un INSERT a
--      mano pusiera cualquier tasa.
CREATE TRIGGER trg_venta_detalle_before_insert
BEFORE INSERT ON venta_detalle
FOR EACH ROW
BEGIN
    DECLARE v_afecto TINYINT(1);

    -- Una venta anulada, o que ya tiene su comprobante, está cerrada: una
    -- línea más descuadraría el subtotal guardado y lo impreso.
    IF (SELECT estado FROM ventas WHERE id = NEW.venta_id) <> 'COMPLETADA'
       OR EXISTS (SELECT 1 FROM comprobantes WHERE venta_id = NEW.venta_id) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La venta ya está cerrada: no admite más líneas.';
    END IF;

    -- La línea sigue el modo de precio de su venta: todas iguales.
    SET NEW.impuesto_incluido = IFNULL((SELECT impuesto_incluido FROM ventas WHERE id = NEW.venta_id), 0);

    SELECT afecto_impuesto INTO v_afecto FROM productos WHERE id = NEW.producto_id;
    SET NEW.afecto_impuesto = IFNULL(v_afecto, 0);
    -- NULLIF: un valor vacío cuenta como ausente, igual que en PHP.
    SET NEW.tasa_impuesto = IF(NEW.afecto_impuesto = 1,
        IFNULL((SELECT CAST(NULLIF(valor, '') AS DECIMAL(6,4)) FROM configuracion
                 WHERE clave = 'tasa_impuesto'), 0), 0);
END$$

-- 9.3 Las ventas no se eliminan: se anulan
CREATE TRIGGER trg_ventas_before_delete
BEFORE DELETE ON ventas
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Las ventas no se eliminan. Use la anulación (RNF6).';
END$$

-- 9.4 Una venta solo entra en un turno de caja ABIERTO. La aplicación ya
--      lo comprobaba, pero una venta cargada por script en un turno cerrado
--      cambiaba el arqueo de un cierre que ya se firmó.
CREATE TRIGGER trg_ventas_before_insert
BEFORE INSERT ON ventas
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM sesiones_caja
                    WHERE id = NEW.sesion_caja_id AND estado = 'ABIERTA') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La venta debe registrarse en un turno de caja abierto';
    END IF;
END$$

-- 9.5 El comprobante debe corresponder al tipo de persona del cliente:
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

    -- Lo pagado tiene que sumar el total de la venta. El comprobante es el
    -- último paso de registrar una venta: si llega hasta acá descuadrada, el
    -- arqueo y el comprobante dirían cifras distintas.
    IF (SELECT COALESCE(SUM(monto), 0) FROM venta_pagos WHERE venta_id = NEW.venta_id)
       <> (SELECT total FROM ventas WHERE id = NEW.venta_id) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Lo pagado no coincide con el total de la venta';
    END IF;
END$$

-- 9.6 A un pedido cerrado o cancelado no se le agregan platos. La puerta real
--      es `App\Services\Pedidos`, que valida lo mismo en PHP (y es la única
--      que existe con LOGICA_EN_PHP=true); esto es la guarda de la base, para
--      lo que entre por fuera de la aplicación.
CREATE TRIGGER trg_pedido_detalle_before_insert
BEFORE INSERT ON pedido_detalle
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pedidos WHERE id = NEW.pedido_id AND estado = 'ABIERTO') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El pedido ya no está abierto: no admite más platos';
    END IF;
END$$

-- 9.7 Los pedidos no se eliminan: se cancelan con su motivo, igual que las
--      ventas se anulan. Un pedido borrado se lleva por delante lo que la
--      cocina preparó y nadie puede explicar después qué pasó con ese pedido.
CREATE TRIGGER trg_pedidos_before_delete
BEFORE DELETE ON pedidos
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Los pedidos no se eliminan. Use la cancelación.';
END$$

-- 9.8 Lo documentado no se borra, lo cerrado no crece, y en un turno cerrado
--      no entra nada más. Impiden lo que la aplicación
--      nunca hace: no tienen réplica en `ReglasEnPhp`, igual que
--      `trg_ventas_before_delete`.
CREATE TRIGGER trg_comprobantes_before_delete
BEFORE DELETE ON comprobantes
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Los comprobantes no se eliminan: se anulan o se sustituyen, conservando su número.';
END$$

CREATE TRIGGER trg_venta_detalle_before_delete
BEFORE DELETE ON venta_detalle
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El detalle de una venta no se elimina. Use la anulación (RNF6).';
END$$

CREATE TRIGGER trg_venta_pagos_before_delete
BEFORE DELETE ON venta_pagos
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Los pagos de una venta no se eliminan. Use la anulación (RNF6).';
END$$

CREATE TRIGGER trg_pedido_detalle_before_delete
BEFORE DELETE ON pedido_detalle
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Los platos de un pedido no se eliminan: se cancelan, con su motivo.';
END$$

CREATE TRIGGER trg_venta_pagos_before_insert
BEFORE INSERT ON venta_pagos
FOR EACH ROW
BEGIN
    IF (SELECT estado FROM ventas WHERE id = NEW.venta_id) <> 'COMPLETADA'
       OR EXISTS (SELECT 1 FROM comprobantes WHERE venta_id = NEW.venta_id) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La venta ya está cerrada: no admite más pagos.';
    END IF;
END$$

CREATE TRIGGER trg_movimientos_caja_before_insert
BEFORE INSERT ON movimientos_caja
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM sesiones_caja
                    WHERE id = NEW.sesion_caja_id AND estado = 'ABIERTA') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El movimiento debe registrarse en un turno de caja abierto';
    END IF;
END$$

CREATE TRIGGER trg_cobros_qr_before_insert
BEFORE INSERT ON cobros_qr
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM sesiones_caja
                    WHERE id = NEW.sesion_caja_id AND estado = 'ABIERTA') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El cobro QR debe generarse en un turno de caja abierto';
    END IF;
END$$

DELIMITER ;

-- =============================================================================
--  10. PROCEDIMIENTOS ALMACENADOS
-- =============================================================================

DELIMITER $$

-- 10.1 Siguiente correlativo con bloqueo de fila (HU-13)
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

-- 10.2 Recalcular los totales de una venta a partir de su detalle.
--      En los dos modos:
--          subtotal  = suma de importes del detalle (base imponible, sin impuesto)
--          total     = subtotal - descuento + impuesto
--      Impuesto encima del precio: impuesto = impuesto de las líneas, en la
--      proporción de la base que deja el descuento.
--      Impuesto incluido: el total es lo cobrado por las líneas menos el
--      descuento que vio el cliente; el impuesto baja en la misma proporción y
--      `descuento` guarda la parte de ese descuento que corresponde a la base.
--      El descuento de cabecera se prorratea entre la base afecta y la inafecta.
--      Es el único descuento: las líneas no llevan uno propio.
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

-- 10.2.b Emitir el comprobante de una venta: FACTURA para persona jurídica,
--        RECIBO para persona natural. Toma el correlativo y congela los datos
--        del negocio, del cliente y los importes en `comprobantes`.
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
    DECLARE v_emisor_nombre    VARCHAR(120);
    DECLARE v_emisor_documento VARCHAR(20);
    DECLARE v_emisor_direccion VARCHAR(200);
    DECLARE v_emisor_telefono  VARCHAR(30);

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

    -- NULLIF: un valor vacío cuenta como ausente, igual que en PHP
    -- (`ReglasEnPhp::config`).
    SET v_moneda = IFNULL((SELECT NULLIF(valor, '') FROM configuracion WHERE clave = 'moneda_codigo'), 'BOB');

    -- Venta al paso: sin cliente registrado el documento sale a nombre genérico.
    SET v_generico = IFNULL((SELECT NULLIF(valor, '') FROM configuracion
                              WHERE clave = 'cliente_generico_nombre'), 'Cliente varios');

    -- Los datos del negocio, congelados: el documento se reimprime como se entregó.
    SET v_emisor_nombre    = (SELECT NULLIF(valor, '') FROM configuracion WHERE clave = 'negocio_nombre');
    SET v_emisor_documento = (SELECT NULLIF(valor, '') FROM configuracion WHERE clave = 'negocio_documento');
    SET v_emisor_direccion = (SELECT NULLIF(valor, '') FROM configuracion WHERE clave = 'negocio_direccion');
    SET v_emisor_telefono  = (SELECT NULLIF(valor, '') FROM configuracion WHERE clave = 'negocio_telefono');

    -- El trigger trg_comprobantes_before_insert valida que el tipo de documento
    -- corresponda al tipo de persona del cliente.
    INSERT INTO comprobantes (
        venta_id, serie_id, numero, numero_completo,
        emisor_nombre, emisor_documento, emisor_direccion, emisor_telefono,
        cliente_id, tipo_persona, cliente_nombre, cliente_tipo_documento,
        cliente_documento, cliente_direccion, representante_legal,
        subtotal, descuento, impuesto, moneda, emitido_por
    )
    SELECT v.id, p_serie_id, v_numero, p_numero_completo,
           v_emisor_nombre, v_emisor_documento, v_emisor_direccion, v_emisor_telefono,
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

-- 10.2.c Sustituir el comprobante de una venta ya cobrada (HU-42).
--        Caso típico: se entregó un RECIBO y el cliente vuelve pidiendo FACTURA.
--        No se toca la venta: solo cambia el documento.
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
            SET MESSAGE_TEXT = 'No se sustituye el comprobante de una venta anulada';
    END IF;

    -- ventana de tiempo permitida (configurable)
    SET v_dias_max = IFNULL((SELECT CAST(NULLIF(valor, '') AS SIGNED) FROM configuracion
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

-- 10.3 Anular una venta: marca el estado y anula su comprobante (HU-29).
--      Solo mientras su turno de caja sigue abierto: el dinero de un turno
--      cerrado ya se contó en su arqueo, y anular cambiaría un cierre firmado.
--      La aplicación lo avisa antes con un mensaje propio (`Ventas::anular`),
--      pero la última palabra es de la base.
CREATE PROCEDURE sp_anular_venta (
    IN p_venta_id   BIGINT UNSIGNED,
    IN p_usuario_id INT UNSIGNED,
    IN p_motivo     VARCHAR(255)
)
BEGIN
    DECLARE v_estado VARCHAR(20);
    DECLARE v_sesion INT UNSIGNED;
    DECLARE v_turno  VARCHAR(20);

    SELECT estado, sesion_caja_id INTO v_estado, v_sesion
      FROM ventas WHERE id = p_venta_id FOR UPDATE;

    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La venta no existe';
    END IF;
    IF v_estado <> 'COMPLETADA' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se puede anular una venta COMPLETADA';
    END IF;

    -- Con el turno bloqueado en modo compartido: un cierre en curso espera.
    SELECT estado INTO v_turno FROM sesiones_caja WHERE id = v_sesion FOR SHARE;

    IF v_turno <> 'ABIERTA' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El turno de caja de esta venta ya cerró: no se anula, su dinero ya se contó en el arqueo';
    END IF;

    -- Sin inventario no hay stock que reponer: anular es cambiar el estado de
    -- la venta y dejar su comprobante anulado, con el correlativo intacto.
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

-- 10.4 Cerrar caja calculando el esperado y la diferencia (HU-27)
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

    -- Del cajón entra lo cobrado en efectivo y salen los egresos: no hay
    -- devoluciones que restar, porque una venta mal cobrada se anula y su
    -- efectivo deja de contarse arriba (`v.estado <> 'ANULADA'`).
    SET v_esperado = v_inicial + v_ventas + v_ingresos - v_egresos;

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
--  11. VISTAS DE APOYO A REPORTES
--
--  Solo las que el sistema usa: son la definición oficial de cada cifra de
--  los reportes (y `ReportesTest` las compara con lo que calcula PHP).
--  `v_empleados`, `v_ventas_comprobante`, `v_comprobantes_sustituidos` y
--  `v_comprobantes_emitidos` se retiraron el 2026-09-19: nadie las leía.
-- =============================================================================

-- Ventas por JORNADA (HU-31, HU-32): el local cierra pasada la medianoche, y
-- la venta de la 01:30 es de la noche anterior. La jornada es la fecha menos
-- `configuracion.hora_corte_jornada` horas (5 si no está), la misma cuenta que
-- `App\Support\Config::jornadaSql()` y que numera los pedidos. La columna se
-- sigue llamando `dia`: es la fecha de la jornada.
CREATE OR REPLACE VIEW v_ventas_por_dia AS
SELECT j.dia                  AS dia,
       COUNT(*)               AS cantidad_ventas,
       SUM(j.total)           AS monto_total,
       ROUND(AVG(j.total), 2) AS ticket_promedio
  FROM (
        SELECT DATE(v.fecha - INTERVAL IFNULL((SELECT CAST(NULLIF(c.valor, '') AS UNSIGNED)
                                                  FROM configuracion c
                                                 WHERE c.clave = 'hora_corte_jornada'), 5) HOUR) AS dia,
               v.total
          FROM ventas v
         WHERE v.estado <> 'ANULADA'
       ) AS j
 GROUP BY j.dia;

-- Productos más vendidos (HU-33)
-- El importe de cada línea va neto del descuento de la venta, repartido entre
-- sus líneas en la misma proporción en que `sp_recalcular_venta` reparte el
-- impuesto: así el descuento de cabecera no hace falta repetirlo aquí.
-- Las ventas anuladas no cuentan.
CREATE OR REPLACE VIEW v_productos_mas_vendidos AS
SELECT p.id, p.codigo, p.nombre, c.nombre AS categoria,
       SUM(n.unidades_netas)  AS unidades_vendidas,
       SUM(n.monto_neto)      AS monto_vendido
  FROM (
        SELECT d.producto_id,
               d.cantidad AS unidades_netas,
               ROUND(d.importe
                     * IF(v.subtotal > 0, (v.subtotal - v.descuento) / v.subtotal, 1), 2) AS monto_neto
          FROM venta_detalle d
          JOIN ventas v ON v.id = d.venta_id AND v.estado <> 'ANULADA'
       ) AS n
  JOIN productos  p ON p.id = n.producto_id
  JOIN categorias c ON c.id = p.categoria_id
 GROUP BY p.id, p.codigo, p.nombre, c.nombre;

-- Ventas por método de pago (HU-32, cierre de caja). Por JORNADA, como
-- `v_ventas_por_dia`: una venta de las 02:00 es de la noche anterior.
CREATE OR REPLACE VIEW v_ventas_por_metodo_pago AS
SELECT j.dia                   AS dia,
       j.metodo_pago           AS metodo_pago,
       COUNT(DISTINCT j.venta) AS cantidad_ventas,
       SUM(j.monto)            AS monto
  FROM (
        SELECT DATE(v.fecha - INTERVAL IFNULL((SELECT CAST(NULLIF(c.valor, '') AS UNSIGNED)
                                                  FROM configuracion c
                                                 WHERE c.clave = 'hora_corte_jornada'), 5) HOUR) AS dia,
               mp.nombre AS metodo_pago,
               v.id      AS venta,
               vp.monto  AS monto
          FROM venta_pagos vp
          JOIN ventas v        ON v.id  = vp.venta_id AND v.estado <> 'ANULADA'
          JOIN metodos_pago mp ON mp.id = vp.metodo_pago_id
       ) AS j
 GROUP BY j.dia, j.metodo_pago;

-- =============================================================================
--  12. REGISTRO DE PARCHES
--
--  `scripts/aplicar-parches.sh` anota aquí cada parche que aplica, y lee de
--  aquí lo que ya está hecho. Una base creada con este archivo nace con el
--  esquema completo, así que los parches que ese esquema ya incorpora tienen
--  que nacer registrados: sin eso, el script los vería pendientes y los
--  aplicaría a ciegas.
--
--  Hoy la lista está vacía: este esquema es el punto de partida. Cada parche
--  nuevo de docs/sql/parches se agrega también aquí (lo exige
--  MenoresDeCierreTest).
-- =============================================================================

CREATE TABLE parches_aplicados (
    archivo     VARCHAR(150) NOT NULL PRIMARY KEY,
    aplicado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


