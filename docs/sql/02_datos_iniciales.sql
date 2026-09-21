-- =============================================================================
--  SISTEMA DEL RESTAURANTE
--  Script 02 - Datos iniciales (catálogos base y ejemplo)
--  Ejecutar después de 01_schema_mysql.sql
-- =============================================================================

SET NAMES utf8mb4;

USE ventas_db;

-- ---------------------------------- Cargos -----------------------------------
-- El cargo es la función laboral, distinta del rol de acceso al sistema.
INSERT INTO cargos (id, nombre, descripcion) VALUES
    (1, 'Gerente',        'Dueño o administrador del negocio'),
    (2, 'Cajero',         'Atiende al público y maneja la caja'),
    (4, 'Ayudante',       'Apoyo general en el local'),
    (5, 'Cocinero',       'Prepara los platos en la cocina');

-- ------------------------------ Roles y permisos -----------------------------
-- El rol define qué puede hacer la cuenta dentro del sistema.
INSERT INTO roles (id, nombre, descripcion) VALUES
    (1, 'Administrador', 'Acceso total al sistema'),
    (2, 'Cajero',        'Registra ventas y maneja su caja'),
    (4, 'Cocina',        'Ve los pedidos y su preparación');

INSERT INTO permisos (codigo, modulo, descripcion) VALUES
    ('usuarios.gestionar',   'Usuarios',   'Crear, editar y desactivar usuarios'),
    ('empleados.gestionar',  'Personal',   'Registrar empleados, cargos, ingresos y ceses'),
    ('productos.gestionar',  'Menú',       'Crear y editar los platos del menú y sus precios'),
    ('ventas.registrar',     'Ventas',     'Registrar una venta'),
    ('ventas.anular',        'Ventas',     'Anular una venta'),
    ('ventas.descuento',     'Ventas',     'Aplicar descuentos sobre el umbral'),
    ('pedidos.registrar',    'Pedidos',    'Cancelar un pedido por cobrar o uno de sus platos'),
    ('cocina.ver',           'Cocina',     'Ver y actualizar el estado de preparación'),
    ('caja.abrir',           'Caja',       'Abrir sesión de caja'),
    ('caja.cerrar',          'Caja',       'Cerrar sesión de caja'),
    ('reportes.ver',         'Reportes',   'Consultar reportes y dashboard'),
    ('configuracion.editar', 'Sistema',    'Editar parámetros del sistema'),
    ('bitacora.ver',         'Sistema',    'Consultar la bitácora de operaciones'),
    ('clientes.editar',      'Ventas',     'Editar los datos de un cliente ya registrado'),
    ('registros.eliminar',   'Sistema',    'Eliminar productos, categorías, clientes y personal');

-- Administrador: todos los permisos
INSERT INTO rol_permiso (rol_id, permiso_id) SELECT 1, id FROM permisos;
-- Cajero: abre su turno y vende, pero NO lo cierra — el cierre lo hace un
-- administrador, que cuenta el efectivo junto al cajero y deja el resumen
-- firmado. Es control, no desconfianza porque sí: el arqueo lo hace quien no
-- tuvo la mano en el cajón durante el turno. También toma los pedidos: el
-- local no tiene mozos, y el que está en la caja es quien atiende al cliente.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 2, id FROM permisos
 WHERE codigo IN ('ventas.registrar','caja.abrir','pedidos.registrar');

-- Cocina: ve lo que hay que preparar y mueve su estado. Nada de dinero.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 4, id FROM permisos WHERE codigo IN ('cocina.ver');

-- El rol 3 (Mozo) y el cargo 3 (Mesero) existieron hasta el 2026-09-18: el
-- local no trabaja con mozos. Sus números quedan sin usar para que los de los
-- demás sigan siendo los mismos en todas las bases.

-- Nótese que los roles (Administrador, Cajero, Cocina) no tienen por qué
-- coincidir con los cargos: el gerente Ana tiene rol Administrador, pero un
-- cajero de confianza podría tener rol Administrador sin dejar de ser cajero.

-- --------------------------------- Empleados ---------------------------------
-- La persona y su vínculo laboral. Nótese el empleado 4: trabaja en el negocio
-- pero no tiene cuenta en el sistema, algo que antes era imposible representar.
INSERT INTO empleados (id, cargo_id, tipo_documento, documento, nombres, apellidos,
                       telefono, email, fecha_ingreso, tipo_contrato, estado) VALUES
    (1, 1, 'CI', '10000001', 'Ana',   'Quispe Torres',  '987000111', 'ana@elfogon.com',   '2024-01-15', 'INDEFINIDO', 'ACTIVO'),
    (2, 2, 'CI', '10000002', 'Luis',  'Ramos Vega',     '987000222', 'luis@elfogon.com',  '2025-03-01', 'INDEFINIDO', 'ACTIVO'),
    (4, 4, 'CI', '10000004', 'Jorge', 'Ccama Mamani',   '987000444', NULL,                '2026-02-01', 'PARCIAL',    'ACTIVO'),
    (5, 5, 'CI', '10000005', 'Raúl',  'Choque Apaza',   '987000555', NULL,                '2026-03-01', 'INDEFINIDO', 'ACTIVO');

-- Ejemplo de empleado cesado (el trigger le desactiva la cuenta automáticamente):
-- UPDATE empleados SET estado = 'CESADO', fecha_cese = CURDATE(),
--        motivo_cese = 'Renuncia voluntaria' WHERE id = 2;

-- ---------------------------------- Usuarios ---------------------------------
-- Solo las credenciales y el rol de acceso. Los datos de la persona están arriba.
-- password_hash de ejemplo (bcrypt de "admin123"); reemplazar en producción.
INSERT INTO usuarios (empleado_id, rol_id, usuario, password_hash) VALUES
    (1, 1, 'admin',   '$2y$10$abcdefghijklmnopqrstuv0123456789ABCDEFGHIJKLMNOPQRSTU'),
    (2, 2, 'cajero1', '$2y$10$abcdefghijklmnopqrstuv0123456789ABCDEFGHIJKLMNOPQRSTU'),
    (5, 4, 'cocina1', '$2y$10$abcdefghijklmnopqrstuv0123456789ABCDEFGHIJKLMNOPQRSTU');
-- El empleado 4 (Jorge, ayudante) no tiene usuario: trabaja sin usar el sistema.

-- ------------------------------- Catálogos base ------------------------------
-- Las secciones de la carta.
-- Las bebidas no pasan por la cocina: se cobran, pero no van a su pantalla
-- ni a la comanda. Se cambia en Menú → Categorías.
INSERT INTO categorias (id, nombre, descripcion, pasa_por_cocina) VALUES
    (1, 'Entradas',         'Para picar mientras llega el plato', 1),
    (2, 'Platos de fondo',  'El plato principal',                 1),
    (3, 'Bebidas',          'Refrescos, jugos y gaseosas',        0),
    (4, 'Postres',          'Para terminar',                      1);

-- Factura -> persona jurídica (exige cliente con NIT). Recibo -> persona natural.
INSERT INTO tipos_comprobante (id, codigo, nombre, aplica_persona, exige_cliente, exige_documento) VALUES
    (1, 'FAC', 'Factura',       'JURIDICA', 1, 1),
    (2, 'REC', 'Recibo',        'NATURAL',  0, 0),
    (3, 'NV',  'Nota de venta', 'AMBAS',    0, 0);

INSERT INTO series_comprobante (tipo_comprobante_id, serie, correlativo_actual, longitud) VALUES
    (1, 'F001', 0, 6),
    (2, 'R001', 0, 6),
    (3, 'NV01', 0, 6);

-- La serie con que se numera cada tipo de documento. Hasta el 2026-09-19 eran
-- `configuracion.serie_factura` y `serie_recibo`, y la de la nota de venta salía
-- de otra consulta; ahora es una FK que la base obliga a ser del mismo tipo.
UPDATE tipos_comprobante t
  JOIN series_comprobante s ON s.tipo_comprobante_id = t.id
   SET t.serie_por_omision_id = s.id
 WHERE s.serie IN ('F001', 'R001', 'NV01');

INSERT INTO metodos_pago (id, codigo, nombre, afecta_caja) VALUES
    (1, 'EFECTIVO', 'Efectivo',            1),
    (2, 'TARJETA',  'Tarjeta débito/crédito', 0),
    (3, 'BILLETERA','Billetera digital',   0),
    (4, 'TRANSFER', 'Transferencia bancaria', 0),
    -- El QR no entra al cajón: el dinero cae en la cuenta del banco.
    (5, 'QR',       'Pago por QR',          0);

INSERT INTO cajas (id, nombre, ubicacion) VALUES
    (1, 'Caja 1', 'Mostrador principal');

-- --------------------------------- Clientes ----------------------------------
-- NO existe un registro "Cliente varios": la venta al paso se guarda con
-- ventas.cliente_id = NULL. Ver `configuracion.cliente_generico_nombre`.
-- Registrar al cliente es OPCIONAL, salvo que pida factura.

-- Personas naturales: nombres + apellidos, documento CI/CE/PAS (o SIN). Reciben RECIBO.
INSERT INTO clientes (id, tipo_persona, tipo_documento, documento, nombres, apellidos, direccion, telefono) VALUES
    (1, 'NATURAL', 'CI', '45678901', 'Carlos', 'Mendoza Ríos', 'Jr. Los Olivos 456',     '987111222'),
    (2, 'NATURAL', 'CI', '41236598', 'Rosa',   'Huamán Pérez', 'Av. Los Álamos 88',      '987111333'),
    (3, 'NATURAL', 'CE',  '001234567','Miguel', 'Duarte Silva', 'Calle Las Gardenias 12', '987111444');

-- Personas jurídicas: razón social + NIT + dirección fiscal. Reciben FACTURA.
INSERT INTO clientes (id, tipo_persona, tipo_documento, documento, razon_social, nombre_comercial, representante_legal, direccion, telefono, email) VALUES
    (4, 'JURIDICA', 'NIT', '5123456', 'Servicios Generales del Oriente S.R.L.', 'SerOriente',
        'Julia Ortega Salas',  'Av. Industrial 1420, Santa Cruz', '33561230', 'compras@seroriente.com'),
    (5, 'JURIDICA', 'NIT', '4876543', 'Restaurante El Fogón S.R.L.',   'El Fogón',
        'Pedro Cárdenas Loza', 'Av. Grigotá 233, Santa Cruz', '33561231', 'admin@elfogon.com');

-- --------------------------------- Productos ---------------------------------
-- Importante: el plato tiene un solo precio, `precio_venta`, y se registra SIN
-- impuesto. El impuesto (el IVA boliviano, 13%) se agrega sobre la base al
-- calcular el total de la venta. Los precios base se eligieron de modo que el
-- precio final por porción quede redondo:
--     precio_venta = ROUND(precio_estante / 1.13, 2)
--     ROUND(precio_venta * 1.13, 2) = precio_estante
-- Hasta septiembre de 2026 la tasa sembrada era el 18% del IGV peruano, y las
-- bases estaban calculadas para ese 18%. Los precios de estante son los mismos.
--
-- Todo va por porción: no hay unidad de medida que elegir, y tampoco código de
-- barras: un plato no se escanea. El código interno es lo que se teclea para
-- encontrarlo rápido.
INSERT INTO productos
    (categoria_id, codigo, nombre,
     precio_venta) VALUES
    --                                    base   -- carta c/imp.
    (2, 'P-0001', 'Pollo a la parrilla',    3.98),  --  4.50
    (3, 'P-0002', 'Jugo de frutas',         7.26),  --  8.20
    (1, 'P-0003', 'Papas fritas',           3.72),  --  4.20
    (2, 'P-0004', 'Milanesa de pollo',      3.54),  --  4.00
    (3, 'P-0005', 'Refresco de la casa',    5.75),  --  6.50
    (3, 'P-0006', 'Mocochinchi',            1.33),  --  1.50
    (2, 'P-0007', 'Lomo a la plancha',      9.65),  -- 10.90
    (4, 'P-0008', 'Helado de canela',       3.10),  --  3.50
    (1, 'P-0009', 'Empanada de queso',      2.48),  --  2.80
    (2, 'P-0010', 'Pique macho',            5.75),  --  6.50
    (1, 'P-0011', 'Pan al ajo',             1.06),  --  1.20
    (4, 'P-0012', 'Flan de vainilla',       2.21);  --  2.50

-- Verificación: esta consulta debe devolver el precio de estante redondo de cada producto.
-- SELECT codigo, nombre, precio_venta,
--        ROUND(precio_venta * (1 + (SELECT CAST(valor AS DECIMAL(6,4))
--                                     FROM configuracion WHERE clave = 'tasa_impuesto')), 2) AS precio_estante
--   FROM productos ORDER BY codigo;

-- ------------------------------- Configuración -------------------------------
INSERT INTO configuracion (clave, valor, descripcion) VALUES
    ('negocio_nombre',      'Restaurante El Buen Sabor', 'Nombre comercial del negocio'),
    ('negocio_documento',   '1023456789',            'NIT del negocio'),
    ('negocio_direccion',   'Av. Grigotá 1420, Santa Cruz', 'Dirección del local'),
    ('negocio_telefono',    '33561200',              'Teléfono de contacto'),
    ('moneda_codigo',       'BOB',                   'Código ISO de la moneda'),
    ('tasa_impuesto',       '0.1300',                'Tasa del IVA (en Bolivia, 13 %)'),
    ('precios_incluyen_impuesto', '0',               'Los precios de venta ya incluyen el impuesto (1 = sí; 0 = se suma encima)'),
    ('descuento_max_cajero','10',                    'Descuento máximo (%) sin autorización'),
    ('egreso_max_cajero',   '200.00',                'Egreso máximo (Bs) que el cajero registra sin autorización'),
    ('cliente_generico_nombre','Cliente varios',     'Texto impreso en el comprobante cuando la venta no tiene cliente registrado'),
    ('exigir_referencia_pago', '1',                  'Pedir el número de operación en pagos con tarjeta, billetera o transferencia (1 = sí)'),
    ('dias_max_sustitucion','1',                     'Días máximos tras la venta para sustituir su comprobante (recibo -> factura)'),
    ('hora_corte_jornada',  '5',                     'Hora (0 a 12) en que empieza la jornada: lo pedido antes cuenta para la noche anterior'),
    ('cajero_cierra_su_caja', '0',                'El cajero cierra su propia caja, sin ver el esperado (1 = sí; 0 = lo cierra un administrador)');

-- =============================================================================
--  EJEMPLO A: VENTA A PERSONA NATURAL -> se emite RECIBO
-- =============================================================================
-- START TRANSACTION;
--
-- INSERT INTO sesiones_caja (caja_id, usuario_apertura_id, monto_inicial)
-- VALUES (1, 2, 100.00);
-- SET @sesion = LAST_INSERT_ID();
--
-- -- 1) cabecera de la venta (cliente 1 = Carlos Mendoza, persona natural)
-- INSERT INTO ventas (cliente_id, usuario_id, sesion_caja_id) VALUES (1, 2, @sesion);
-- SET @venta = LAST_INSERT_ID();
--
-- -- 2) detalle: el trigger copia del producto el régimen de impuesto
-- -- OJO: el detalle se inserta con VALUES, nunca con INSERT ... SELECT FROM productos.
-- -- El trigger actualiza `productos`, y MySQL prohíbe que un trigger modifique una tabla
-- -- que la sentencia invocante está leyendo (error 1442). La aplicación ya tiene estos
-- -- datos en el carrito, así que en la práctica no es una limitación.
-- SELECT id, nombre, precio_venta INTO @p1, @n1, @pv1 FROM productos WHERE codigo = 'P-0001';
-- SELECT id, nombre, precio_venta INTO @p2, @n2, @pv2 FROM productos WHERE codigo = 'P-0005';
-- INSERT INTO venta_detalle (venta_id, producto_id, descripcion, cantidad, precio_unitario)
-- VALUES (@venta, @p1, @n1, 3, @pv1), (@venta, @p2, @n2, 2, @pv2);
--
-- -- 3) totales
-- CALL sp_recalcular_venta(@venta);
-- -- 3 arroz  base 11.43 + impuesto 2.06
-- -- 2 gaseosa base 11.02 + impuesto 1.98
-- -- subtotal = 22.45 | impuesto = 4.04 | total = 26.49
-- -- Nota: 3 x 4.50 + 2 x 6.50 = 26.50. La diferencia de 0.01 es el redondeo del
-- -- impuesto sobre el importe de línea en lugar de sobre el precio unitario;
-- -- es inherente a trabajar con precios netos y se acumula como céntimos, no como error.
--
-- -- 4) cobro, ANTES del comprobante: el trigger exige que los pagos sumen el total
-- -- `vuelto` es columna generada: no se inserta, sale de monto_recibido - monto
-- INSERT INTO venta_pagos (venta_id, metodo_pago_id, monto, monto_recibido)
-- SELECT @venta, 1, total, 40.00 FROM ventas WHERE id = @venta;   -- vuelto = 13.51
--
-- -- 5) comprobante: serie 2 = R001 (la serie ya define que es RECIBO)
-- CALL sp_emitir_comprobante(@venta, 2, @comp_id, @numero);
-- SELECT @numero;   -- R001-000001
--
-- COMMIT;
--
-- SELECT numero_completo, cliente_nombre, total FROM comprobantes WHERE venta_id = @venta;

-- =============================================================================
--  EJEMPLO B: VENTA A PERSONA JURÍDICA -> se emite FACTURA
-- =============================================================================
-- START TRANSACTION;
--
-- -- cliente 4 = Servicios Generales del Oriente S.R.L. (NIT)
-- INSERT INTO ventas (cliente_id, usuario_id, sesion_caja_id) VALUES (4, 2, @sesion);
-- SET @venta2 = LAST_INSERT_ID();
--
-- SELECT id, nombre, precio_venta INTO @p, @n, @pv FROM productos WHERE codigo = 'P-0007';
-- INSERT INTO venta_detalle (venta_id, producto_id, descripcion, cantidad, precio_unitario)
-- VALUES (@venta2, @p, @n, 10, @pv);
--
-- CALL sp_recalcular_venta(@venta2);
--
-- -- serie 1 = F001 (FACTURA). El trigger valida que el cliente sea JURIDICA
-- -- y que tenga documento; en caso contrario aborta la transacción.
-- INSERT INTO venta_pagos (venta_id, metodo_pago_id, monto, referencia)
-- SELECT @venta2, 4, total, 'TRF-99881' FROM ventas WHERE id = @venta2;
--
-- CALL sp_emitir_comprobante(@venta2, 1, @comp_id2, @numero2);
-- SELECT @numero2;  -- F001-000001
--
-- COMMIT;
--
-- -- Cuerpo de la factura con el desglose de impuesto por línea:
-- SELECT descripcion, cantidad, precio_unitario, importe,
--        tasa_impuesto, impuesto_linea, total_linea
--   FROM venta_detalle WHERE venta_id = @venta2;

-- =============================================================================
--  EJEMPLO C: VENTA AL PASO, SIN REGISTRAR AL CLIENTE (el caso más frecuente)
-- =============================================================================
-- Es el flujo rápido de mostrador: escanear, cobrar, entregar el recibo.
-- No se pide ningún dato al cliente: cliente_id queda en NULL.
--
-- START TRANSACTION;
--
-- INSERT INTO ventas (cliente_id, usuario_id, sesion_caja_id) VALUES (NULL, 2, @sesion);
-- SET @venta3 = LAST_INSERT_ID();
--
-- SELECT id, nombre, precio_venta INTO @p, @n, @pv FROM productos WHERE codigo = 'P-0006';
-- INSERT INTO venta_detalle (venta_id, producto_id, descripcion, cantidad, precio_unitario)
-- VALUES (@venta3, @p, @n, 2, @pv);
--
-- CALL sp_recalcular_venta(@venta3);
--
-- -- RECIBO: exige_cliente = 0, así que no reclama cliente.
-- -- El comprobante sale a nombre de configuracion.cliente_generico_nombre.
-- INSERT INTO venta_pagos (venta_id, metodo_pago_id, monto, monto_recibido)
-- SELECT @venta3, 1, total, 5.00 FROM ventas WHERE id = @venta3;
--
-- CALL sp_emitir_comprobante(@venta3, 2, @comp_id3, @numero3);
--
-- COMMIT;
--
-- SELECT numero_completo, cliente_id, cliente_nombre, cliente_documento
--   FROM comprobantes WHERE venta_id = @venta3;
-- -- R001-000002 | NULL | Cliente varios | NULL

-- =============================================================================
--  EJEMPLO D: EL CLIENTE VUELVE Y PIDE FACTURA (sustitución del comprobante)
-- =============================================================================
-- La venta @venta3 se cobró al paso y salió con recibo R001-000002.
-- El cliente regresa: era una empresa y necesita factura.
-- No se anula la venta; solo se reemplaza el documento.
--
-- START TRANSACTION;
--
-- SELECT id INTO @recibo FROM comprobantes WHERE venta_id = @venta3 AND estado = 'EMITIDO';
--
-- -- serie 1 = F001 (FACTURA), cliente 4 = Servicios Generales Perú S.A.C., usuario 1
-- CALL sp_sustituir_comprobante(@recibo, 1, 4, 1,
--                               'El cliente solicita factura a nombre de su empresa',
--                               @nueva_factura, @numero_factura);
-- SELECT @numero_factura;   -- F001-000002
--
-- COMMIT;
--
-- -- El recibo queda como historial, la factura es el documento vigente:
-- SELECT numero_completo, estado, sustituye_a, sustituido_en
--   FROM comprobantes WHERE venta_id = @venta3 ORDER BY id;
-- -- R001-000002 | SUSTITUIDO | NULL     | 2026-...
-- -- F001-000002 | EMITIDO    | <recibo> | NULL
--
-- SELECT numero_completo, estado, sustituye_a, motivo_emision FROM comprobantes WHERE venta_id = @venta3;

-- =============================================================================
--  EJEMPLO F: LO QUE EL MODELO RECHAZA
-- =============================================================================
-- -- Factura a persona natural -> ERROR 1644:
-- --   "El tipo de comprobante no corresponde al tipo de persona del cliente"
-- -- CALL sp_emitir_comprobante(@venta, 1, @x, @y);
--
-- -- Cliente jurídico sin NIT ni dirección -> ERROR 3819 (ck_clientes_juridica):
-- -- INSERT INTO clientes (tipo_persona, tipo_documento, razon_social)
-- -- VALUES ('JURIDICA', 'CI', 'Empresa Sin NIT S.R.L.');
--
-- -- Segundo comprobante para una venta que ya tiene uno vigente -> ERROR 1644:
-- --   "La venta ya tiene un comprobante vigente. Use sp_sustituir_comprobante."
--
-- -- Factura sobre una venta sin cliente registrado -> ERROR 1644:
-- --   "Este tipo de comprobante exige un cliente registrado"
-- -- CALL sp_emitir_comprobante(@venta3, 1, @x, @y);
-- -- (para facturar hay que registrar antes al cliente jurídico: es el único
-- --  caso en que el cajero está obligado a pedir datos)
--
-- -- Sustituir un comprobante ya sustituido -> ERROR 1644:
-- --   "Solo se puede sustituir un comprobante vigente (EMITIDO)"
--
-- -- Sustituir el comprobante de una venta de hace un mes -> ERROR 1644:
-- --   "La venta excede el plazo permitido para sustituir su comprobante"
-- --   (el plazo se configura en configuracion.dias_max_sustitucion)
--
