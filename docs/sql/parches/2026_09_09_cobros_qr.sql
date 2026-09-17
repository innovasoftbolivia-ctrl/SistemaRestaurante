-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Cobros por QR con monto (2026-09-09)
--
--  Qué agrega
--  ----------
--  La tabla `cobros_qr`: un cobro por código QR con el importe ya puesto, para
--  que el cliente escanee y pague exactamente lo que debe, sin teclear nada.
--
--  Por qué el cobro NO cuelga de la venta
--  -------------------------------------
--  `venta_id` es NULL hasta que el pago se confirma, y esto es deliberado. El
--  QR se genera contra el CARRITO, antes de que la venta exista: si colgara de
--  la venta habría que registrarla primero, y una venta creada antes de cobrar
--  ya descontó stock y ya emitió comprobante. Si el cliente entonces no paga
--  —se le acabó el saldo, se arrepintió, se fue— queda una venta fantasma con
--  mercadería descontada que nadie se llevó.
--
--  Así, el cobro vive solo mientras se espera; cuando el banco confirma, la
--  venta se registra y recién ahí el cobro queda ligado a ella.
--
--  Estados
--  -------
--  PENDIENTE  esperando que el cliente pague
--  PAGADO     confirmado (por el banco o a mano por el cajero)
--  EXPIRADO   se venció sin pagarse
--  ANULADO    el cajero lo canceló antes de cobrar
--
--  `confirmado_por` distingue quién lo dio por pagado. Cuando la API del banco
--  no responde, el cajero puede confirmar mirando el comprobante en el celular
--  del cliente; eso es legítimo pero tiene que quedar con nombre y apellido,
--  porque es el punto por donde se podría colar un cobro que nunca entró.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS cobros_qr (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,

    -- Contexto de quién y desde dónde se cobra.
    sesion_caja_id      INT UNSIGNED    NOT NULL,
    usuario_id          INT UNSIGNED    NOT NULL,

    -- La venta llega DESPUÉS de que el pago se confirma (ver cabecera).
    -- BIGINT y no INT: `ventas.id` es BIGINT, y una clave foránea exige que los
    -- dos lados sean del mismo tipo exacto.
    venta_id            BIGINT UNSIGNED NULL,

    monto               DECIMAL(12,2)   NOT NULL,
    moneda              CHAR(3)         NOT NULL DEFAULT 'BOB',
    glosa               VARCHAR(120)    NULL,

    -- Qué pasarela lo generó: 'simulado' mientras no haya banco, o el código
    -- del banco cuando se contrate.
    pasarela            VARCHAR(30)     NOT NULL,

    -- El identificador que devuelve el banco. Único por pasarela: es la clave
    -- con la que se consulta el estado y con la que llega el webhook, así que
    -- dos cobros no pueden compartirlo.
    id_externo          VARCHAR(80)     NULL,

    -- El contenido que se codifica en el QR, tal como lo devolvió la pasarela.
    payload             TEXT            NULL,

    estado              ENUM('PENDIENTE','PAGADO','EXPIRADO','ANULADO')
                        NOT NULL DEFAULT 'PENDIENTE',

    expira_en           DATETIME        NULL,
    pagado_en           DATETIME        NULL,

    -- Quién lo dio por pagado: la pasarela o una persona.
    confirmado_por      ENUM('PASARELA','MANUAL') NULL,
    confirmado_por_id   INT UNSIGNED    NULL,

    -- El número de operación del banco, para poder rastrearlo después.
    referencia_bancaria VARCHAR(80)     NULL,

    -- La respuesta cruda de la pasarela, por si hay que reclamar.
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

    -- Un cobro de cero o negativo no es un cobro.
    CONSTRAINT ck_cobros_qr_monto CHECK (monto > 0),

    -- Si está pagado tiene que saberse cuándo y quién lo confirmó; y al revés,
    -- lo que no está pagado no puede tener fecha de pago.
    CONSTRAINT ck_cobros_qr_pagado CHECK (
        (estado = 'PAGADO'  AND pagado_en IS NOT NULL AND confirmado_por IS NOT NULL)
     OR (estado <> 'PAGADO' AND pagado_en IS NULL)
    )
-- Sin CHARSET ni COLLATE: se heredan de la base, como TODAS las demás tablas.
-- Declararlos acá fue un error que costó caro. La base se crea con
-- utf8mb4_0900_ai_ci; esta tabla salía con utf8mb4_unicode_ci y quedaba
-- descolgada del resto. Un procedimiento almacenado fija su colación al
-- crearse, así que en una base con las dos mezcladas cualquier comparación
-- dentro de un procedimiento revienta con «Illegal mix of collations» —y el
-- error aparece al cobrar, no al aplicar el parche.
) ENGINE=InnoDB;

-- El medio de pago con el que se registra un cobro por QR. No afecta caja: el
-- dinero cae en la cuenta del banco, no en el cajón, así que no entra al
-- arqueo del turno.
INSERT IGNORE INTO metodos_pago (codigo, nombre, afecta_caja, activo)
VALUES ('QR', 'Pago por QR', 0, 1);

INSERT IGNORE INTO parches_aplicados (archivo) VALUES ('2026_09_09_cobros_qr.sql');
