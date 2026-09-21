-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Mesas, pedidos y cocina (2026-09-17)
--
--  El local pasa a atender mesas y pedidos para llevar:
--
--  - `mesas`, `pedidos` y `pedido_detalle`, con la guarda de «una sola cuenta
--    abierta por mesa» (columna generada + índice único, igual que los turnos
--    de caja) y la de «un pedido se cobra una sola vez» (`venta_id` único).
--  - Dos triggers: a un pedido cerrado no se le agregan platos, y los pedidos
--    no se borran.
--  - Permisos `pedidos.registrar` y `cocina.ver`, roles `Mozo` y `Cocina`, y
--    los cargos `Mesero` y `Cocinero`.
--
--  El cobro no necesita procedimiento propio: `App\Services\Pedidos::cobrar`
--  arma la venta con `Ventas::registrar`, que ya resuelve comprobante, pagos y
--  caja por las dos vías (procedimientos o `ReglasEnPhp`).
--
--  Idempotente: `CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE` y los triggers se
--  reemplazan. Se aplica después de 2026_09_17_eliminar_inventario_y_devoluciones.sql.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

-- --------------------------------------------------------------------- tablas
CREATE TABLE IF NOT EXISTS mesas (
    id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    numero          SMALLINT UNSIGNED NOT NULL,      -- lo que dice el cartelito
    nombre          VARCHAR(40)  NULL,               -- "Ventana", "Barra 1"
    zona            VARCHAR(40)  NULL,               -- Salón, Terraza, Patio
    capacidad       TINYINT UNSIGNED NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mesas_numero (numero),
    KEY ix_mesas_zona (zona)
) ENGINE=InnoDB;

-- La cuenta abierta de una mesa, o un pedido para llevar.
--
-- `venta_id` es NULL hasta que se cobra, y es UNIQUE: el mismo pedido no se
-- puede cobrar dos veces ni aunque dos cajeros pulsen a la vez.
CREATE TABLE IF NOT EXISTS pedidos (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo                ENUM('MESA','LLEVAR') NOT NULL DEFAULT 'MESA',
    mesa_id             SMALLINT UNSIGNED NULL,
    cliente_id          INT UNSIGNED NULL,
    usuario_id          INT UNSIGNED NOT NULL,       -- el mozo que lo abrió
    -- Para llevar casi nunca hay cliente registrado: alcanza con un nombre y
    -- un teléfono para cantar el pedido cuando esté.
    nombre_cliente      VARCHAR(80)  NULL,
    telefono_cliente    VARCHAR(20)  NULL,
    estado              ENUM('ABIERTO','CERRADO','CANCELADO') NOT NULL DEFAULT 'ABIERTO',
    observacion         VARCHAR(255) NULL,
    motivo_cancelacion  VARCHAR(255) NULL,
    venta_id            BIGINT UNSIGNED NULL,        -- se llena al cobrar
    fecha_apertura      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_cierre        DATETIME     NULL,
    cerrado_por         INT UNSIGNED NULL,
    creado_en           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Una mesa no puede tener dos cuentas abiertas a la vez. Mismo patrón que
    -- `sesiones_caja.caja_abierta_uk`: la columna vale NULL cuando el pedido
    -- ya se cerró, y un índice único admite tantos NULL como haga falta. Los
    -- pedidos para llevar no tienen mesa, así que tampoco chocan entre ellos.
    mesa_abierta_uk     SMALLINT UNSIGNED
                        GENERATED ALWAYS AS (IF(estado = 'ABIERTO', mesa_id, NULL)) VIRTUAL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pedido_mesa_abierta (mesa_abierta_uk),
    UNIQUE KEY uq_pedido_venta (venta_id),
    KEY ix_pedidos_estado (estado, fecha_apertura),
    KEY ix_pedidos_mesa   (mesa_id),
    KEY ix_pedidos_usuario (usuario_id),
    CONSTRAINT fk_pedidos_mesa    FOREIGN KEY (mesa_id)     REFERENCES mesas (id),
    CONSTRAINT fk_pedidos_cliente FOREIGN KEY (cliente_id)  REFERENCES clientes (id),
    CONSTRAINT fk_pedidos_usuario FOREIGN KEY (usuario_id)  REFERENCES usuarios (id),
    CONSTRAINT fk_pedidos_venta   FOREIGN KEY (venta_id)    REFERENCES ventas (id),
    CONSTRAINT fk_pedidos_cierre  FOREIGN KEY (cerrado_por) REFERENCES usuarios (id),
    -- Un pedido de mesa tiene mesa; uno para llevar, no.
    CONSTRAINT ck_pedidos_mesa   CHECK ((tipo = 'MESA') = (mesa_id IS NOT NULL)),
    -- Abierto es no tener fecha de cierre: son la misma cosa dicha dos veces.
    CONSTRAINT ck_pedidos_cierre CHECK ((estado = 'ABIERTO') = (fecha_cierre IS NULL)),
    -- Con venta detrás, el pedido está cobrado y por lo tanto cerrado.
    CONSTRAINT ck_pedidos_venta  CHECK (venta_id IS NULL OR estado = 'CERRADO'),
    CONSTRAINT ck_pedidos_cancel CHECK (estado <> 'CANCELADO' OR motivo_cancelacion IS NOT NULL)
) ENGINE=InnoDB;

-- Lo que se pidió, línea por línea.
--
-- A propósito SIN el `UNIQUE (pedido_id, producto_id)` que sí tiene
-- `venta_detalle`: una mesa pide dos cervezas y más tarde otra, y cada tanda
-- lleva su propia nota, su hora y su estado en cocina. El cobro las agrupa por
-- producto antes de pasarlas a la venta (ver `Pedidos::cobrar`).
CREATE TABLE IF NOT EXISTS pedido_detalle (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pedido_id           BIGINT UNSIGNED NOT NULL,
    producto_id         INT UNSIGNED NOT NULL,
    descripcion         VARCHAR(120)  NOT NULL,      -- copia histórica del nombre
    cantidad            DECIMAL(12,3) NOT NULL,
    -- Copiado al agregar: si el precio del catálogo sube a mitad de turno, la
    -- mesa paga lo que se le cantó al pedir.
    precio_unitario     DECIMAL(12,2) NOT NULL,
    importe             DECIMAL(12,2) GENERATED ALWAYS AS (ROUND(cantidad * precio_unitario, 2)) STORED,
    nota                VARCHAR(255)  NULL,          -- "sin cebolla", "término medio"
    estado_cocina       ENUM('PENDIENTE','EN_PREPARACION','LISTO','ENTREGADO','CANCELADO')
                        NOT NULL DEFAULT 'PENDIENTE',
    usuario_id          INT UNSIGNED NOT NULL,       -- quién la agregó
    actualizado_por     INT UNSIGNED NULL,           -- quién movió su estado
    creado_en           TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_pedidodet_pedido   (pedido_id),
    KEY ix_pedidodet_producto (producto_id),
    -- La pantalla de cocina lee por estado y en orden de llegada.
    KEY ix_pedidodet_cocina   (estado_cocina, creado_en),
    CONSTRAINT fk_pedidodet_pedido    FOREIGN KEY (pedido_id)       REFERENCES pedidos (id) ON DELETE CASCADE,
    CONSTRAINT fk_pedidodet_producto  FOREIGN KEY (producto_id)     REFERENCES productos (id),
    CONSTRAINT fk_pedidodet_usuario   FOREIGN KEY (usuario_id)      REFERENCES usuarios (id),
    CONSTRAINT fk_pedidodet_actualiza FOREIGN KEY (actualizado_por) REFERENCES usuarios (id),
    CONSTRAINT ck_pedidodet_cantidad CHECK (cantidad > 0),
    CONSTRAINT ck_pedidodet_precio   CHECK (precio_unitario >= 0)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------- triggers
DROP TRIGGER IF EXISTS trg_pedido_detalle_before_insert;
DROP TRIGGER IF EXISTS trg_pedidos_before_delete;

DELIMITER $$

-- 10.5 A un pedido cerrado o cancelado no se le agregan platos. La puerta real
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

-- 10.6 Los pedidos no se eliminan: se cancelan con su motivo, igual que las
--      ventas se anulan. Un pedido borrado se lleva por delante lo que la
--      cocina preparó y nadie puede explicar después qué pasó con esa mesa.
CREATE TRIGGER trg_pedidos_before_delete
BEFORE DELETE ON pedidos
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Los pedidos no se eliminan. Use la cancelación.';
END$$


DELIMITER ;

-- ------------------------------------------------- permisos, roles y cargos
INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
    ('pedidos.registrar', 'Pedidos', 'Abrir mesas y agregar líneas a un pedido'),
    ('cocina.ver',        'Cocina',  'Ver y actualizar el estado de preparación');

INSERT IGNORE INTO roles (nombre, descripcion) VALUES
    ('Mozo',   'Abre mesas y toma pedidos, pero no cobra'),
    ('Cocina', 'Ve los pedidos y su preparación');

INSERT IGNORE INTO cargos (nombre, descripcion) VALUES
    ('Mesero',   'Atiende las mesas y toma los pedidos'),
    ('Cocinero', 'Prepara los platos en la cocina');

-- El administrador tiene todo, siempre.
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r, permisos p
 WHERE r.nombre = 'Administrador' AND p.codigo IN ('pedidos.registrar', 'cocina.ver');

-- El cajero también toma pedidos: en un local chico es quien atiende el
-- mostrador de «para llevar».
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r, permisos p
 WHERE r.nombre = 'Cajero' AND p.codigo = 'pedidos.registrar';

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r, permisos p
 WHERE r.nombre = 'Mozo' AND p.codigo = 'pedidos.registrar';

INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r, permisos p
 WHERE r.nombre = 'Cocina' AND p.codigo = 'cocina.ver';
