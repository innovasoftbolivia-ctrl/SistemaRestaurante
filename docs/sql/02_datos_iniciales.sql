-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
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
    (3, 'Almacenero',     'Recibe mercadería y controla el inventario'),
    (4, 'Ayudante',       'Apoyo general en tienda y almacén');

-- ------------------------------ Roles y permisos -----------------------------
-- El rol define qué puede hacer la cuenta dentro del sistema.
INSERT INTO roles (id, nombre, descripcion) VALUES
    (1, 'Administrador', 'Acceso total al sistema'),
    (2, 'Cajero',        'Registra ventas y maneja su caja'),
    (3, 'Almacenero',    'Gestiona inventario y productos');

INSERT INTO permisos (codigo, modulo, descripcion) VALUES
    ('usuarios.gestionar',   'Usuarios',   'Crear, editar y desactivar usuarios'),
    ('empleados.gestionar',  'Personal',   'Registrar empleados, cargos, ingresos y ceses'),
    ('productos.gestionar',  'Catálogo',   'Crear y editar productos y precios'),
    ('ventas.registrar',     'Ventas',     'Registrar una venta'),
    ('ventas.anular',        'Ventas',     'Anular una venta'),
    ('ventas.descuento',     'Ventas',     'Aplicar descuentos sobre el umbral'),
    ('devoluciones.registrar','Devoluciones','Registrar devoluciones'),
    ('inventario.ingresar',  'Inventario', 'Registrar ingresos de mercadería'),
    ('inventario.ajustar',   'Inventario', 'Registrar ajustes de inventario'),
    ('caja.abrir',           'Caja',       'Abrir sesión de caja'),
    ('caja.cerrar',          'Caja',       'Cerrar sesión de caja'),
    ('reportes.ver',         'Reportes',   'Consultar reportes y dashboard'),
    ('configuracion.editar', 'Sistema',    'Editar parámetros del sistema'),
    ('bitacora.ver',         'Sistema',    'Consultar la bitácora de operaciones'),
    ('respaldos.gestionar',  'Sistema',    'Hacer y descargar respaldos de la base'),
    ('clientes.editar',      'Ventas',     'Editar los datos de un cliente ya registrado'),
    ('registros.eliminar',   'Sistema',    'Eliminar productos, categorías, unidades, proveedores, clientes y personal');

-- Administrador: todos los permisos
INSERT INTO rol_permiso (rol_id, permiso_id) SELECT 1, id FROM permisos;
-- Cajero: abre su turno y vende, pero NO lo cierra — el cierre lo hace un
-- administrador, que cuenta el efectivo junto al cajero y deja el resumen
-- firmado. Es control, no desconfianza porque sí: el arqueo lo hace quien no
-- tuvo la mano en el cajón durante el turno.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 2, id FROM permisos
 WHERE codigo IN ('ventas.registrar','caja.abrir');
-- Almacenero
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 3, id FROM permisos
 WHERE codigo IN ('productos.gestionar','inventario.ingresar','inventario.ajustar');
-- Sin `reportes.ver`: los reportes, las ventas, las cajas y las devoluciones son
-- de todos los cajeros, y eso solo lo ve el administrador. Tampoco
-- `registros.eliminar`: el almacenero da de alta y corrige, no elimina.

-- Nótese que los roles (Administrador, Cajero, Almacenero) no tienen por qué
-- coincidir con los cargos: el gerente Ana tiene rol Administrador, pero un
-- cajero de confianza podría tener rol Administrador sin dejar de ser cajero.

-- --------------------------------- Empleados ---------------------------------
-- La persona y su vínculo laboral. Nótese el empleado 4: trabaja en el negocio
-- pero no tiene cuenta en el sistema, algo que antes era imposible representar.
INSERT INTO empleados (id, cargo_id, tipo_documento, documento, nombres, apellidos,
                       telefono, email, fecha_ingreso, tipo_contrato, estado) VALUES
    (1, 1, 'CI', '10000001', 'Ana',   'Quispe Torres',  '987000111', 'ana@tienda.com',   '2024-01-15', 'INDEFINIDO', 'ACTIVO'),
    (2, 2, 'CI', '10000002', 'Luis',  'Ramos Vega',     '987000222', 'luis@tienda.com',  '2025-03-01', 'INDEFINIDO', 'ACTIVO'),
    (3, 3, 'CI', '10000003', 'Marta', 'Flores Díaz',    '987000333', 'marta@tienda.com', '2025-06-10', 'PLAZO_FIJO', 'ACTIVO'),
    (4, 4, 'CI', '10000004', 'Jorge', 'Ccama Mamani',   '987000444', NULL,               '2026-02-01', 'PARCIAL',    'ACTIVO');

-- Ejemplo de empleado cesado (el trigger le desactiva la cuenta automáticamente):
-- UPDATE empleados SET estado = 'CESADO', fecha_cese = CURDATE(),
--        motivo_cese = 'Renuncia voluntaria' WHERE id = 3;

-- ---------------------------------- Usuarios ---------------------------------
-- Solo las credenciales y el rol de acceso. Los datos de la persona están arriba.
-- password_hash de ejemplo (bcrypt de "admin123"); reemplazar en producción.
INSERT INTO usuarios (empleado_id, rol_id, usuario, password_hash) VALUES
    (1, 1, 'admin',   '$2y$10$abcdefghijklmnopqrstuv0123456789ABCDEFGHIJKLMNOPQRSTU'),
    (2, 2, 'cajero1', '$2y$10$abcdefghijklmnopqrstuv0123456789ABCDEFGHIJKLMNOPQRSTU'),
    (3, 3, 'almacen', '$2y$10$abcdefghijklmnopqrstuv0123456789ABCDEFGHIJKLMNOPQRSTU');
-- El empleado 4 (Jorge, ayudante) no tiene usuario: trabaja sin usar el sistema.

-- ------------------------------- Catálogos base ------------------------------
INSERT INTO unidades_medida (id, codigo, nombre, permite_decimal) VALUES
    (1, 'UND',  'Unidad',     0),
    (2, 'KG',   'Kilogramo',  1),
    (3, 'LT',   'Litro',      1),
    (4, 'CAJA', 'Caja',       0),
    (5, 'PQT',  'Paquete',    0);

INSERT INTO categorias (id, nombre, descripcion) VALUES
    (1, 'Abarrotes',    'Productos secos de consumo diario'),
    (2, 'Bebidas',      'Gaseosas, aguas y jugos'),
    (3, 'Limpieza',     'Artículos de limpieza del hogar'),
    (4, 'Higiene',      'Cuidado personal'),
    (5, 'Golosinas',    'Dulces y snacks');

-- Factura -> persona jurídica (exige cliente con NIT). Recibo -> persona natural.
INSERT INTO tipos_comprobante (id, codigo, nombre, aplica_persona, exige_cliente, exige_documento) VALUES
    (1, 'FAC', 'Factura',       'JURIDICA', 1, 1),
    (2, 'REC', 'Recibo',        'NATURAL',  0, 0),
    (3, 'NV',  'Nota de venta', 'AMBAS',    0, 0);

INSERT INTO series_comprobante (tipo_comprobante_id, serie, correlativo_actual, longitud) VALUES
    (1, 'F001', 0, 6),
    (2, 'R001', 0, 6),
    (3, 'NV01', 0, 6);

INSERT INTO metodos_pago (id, codigo, nombre, afecta_caja) VALUES
    (1, 'EFECTIVO', 'Efectivo',            1),
    (2, 'TARJETA',  'Tarjeta débito/crédito', 0),
    (3, 'BILLETERA','Billetera digital',   0),
    (4, 'TRANSFER', 'Transferencia bancaria', 0),
    -- El QR no entra al cajón: el dinero cae en la cuenta del banco.
    (5, 'QR',       'Pago por QR',          0);

INSERT INTO cajas (id, nombre, ubicacion) VALUES
    (1, 'Caja 1', 'Mostrador principal');

INSERT INTO proveedores (id, razon_social, documento, telefono) VALUES
    (1, 'Distribuidora del Norte S.A.C.', '20100000001', '987654321'),
    (2, 'Comercial Andina E.I.R.L.',      '20100000002', '987654322');

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
-- Importante: precio_compra y precio_venta se registran SIN impuesto.
-- El impuesto (el IVA boliviano, 13%) se agrega sobre la base al calcular el
-- total de la venta. Los precios base se eligieron de modo que el precio final
-- por unidad quede redondo:
--     precio_venta = ROUND(precio_estante / 1.13, 2)
--     ROUND(precio_venta * 1.13, 2) = precio_estante
-- Hasta septiembre de 2026 la tasa sembrada era el 18% del IGV peruano, y las
-- bases estaban calculadas para ese 18%. Los precios de estante son los mismos.
--
-- `contenido_empaque` / `nombre_empaque`: cómo llega del proveedor. Solo lo
-- llevan los que de verdad vienen en caja; el arroz por kilo o el aceite
-- suelto van en NULL, que es como se ve un producto sin empaque. Ojo: el
-- stock y los precios siguen siendo POR UNIDAD DE VENTA en todos los casos.
INSERT INTO productos
    (categoria_id, unidad_medida_id, contenido_empaque, nombre_empaque, proveedor_id,
     codigo, codigo_barras, nombre,
     precio_compra, precio_venta, stock_actual, stock_minimo) VALUES
    --                                                                    costo  base   stock min   -- estante c/imp.
    (1, 2, NULL, NULL,      1, 'P-0001', '7790001000019', 'Arroz extra 1 kg',      3.20,  3.98,  120, 20),  --  4.50
    (1, 3, NULL, NULL,      1, 'P-0002', '7790001000026', 'Aceite vegetal 1 L',    6.10,  7.26,   60, 12),  --  8.20
    (1, 2, NULL, NULL,      1, 'P-0003', '7790001000033', 'Azúcar rubia 1 kg',     3.00,  3.72,   45, 15),  --  4.20
    (1, 1,   24, 'Caja',    2, 'P-0004', '7790001000040', 'Leche evaporada 400 g', 2.80,  3.54,   90, 24),  --  4.00
    (2, 3,   12, 'Caja',    2, 'P-0005', '7790001000057', 'Gaseosa 1.5 L',         4.00,  5.75,   75, 18),  --  6.50
    (2, 3,   15, 'Paquete', 2, 'P-0006', '7790001000064', 'Agua mineral 625 ml',   0.90,  1.33,  150, 30),  --  1.50
    (3, 2, NULL, NULL,      1, 'P-0007', '7790001000071', 'Detergente 1 kg',       7.50,  9.65,   40, 10),  -- 10.90
    (3, 3, NULL, NULL,      1, 'P-0008', '7790001000088', 'Lejía 1 L',             2.20,  3.10,   35, 10),  --  3.50
    (4, 1,   72, 'Caja',    2, 'P-0009', '7790001000095', 'Jabón de tocador',      1.60,  2.48,   80, 20),  --  2.80
    (4, 1, NULL, NULL,      2, 'P-0010', '7790001000101', 'Papel higiénico x4',    4.20,  5.75,   55, 12),  --  6.50
    (5, 1,   50, 'Caja',    2, 'P-0011', '7790001000118', 'Galletas surtidas',     0.70,  1.06,  200, 40),  --  1.20
    (5, 1,   24, 'Caja',    2, 'P-0012', '7790001000125', 'Chocolate barra 40 g',  1.10,  2.21,  110, 25);  --  2.50

-- Verificación: esta consulta debe devolver el precio de estante redondo de cada producto.
-- SELECT codigo, nombre, precio_venta,
--        ROUND(precio_venta * (1 + (SELECT CAST(valor AS DECIMAL(6,4))
--                                     FROM configuracion WHERE clave = 'tasa_impuesto')), 2) AS precio_estante
--   FROM productos ORDER BY codigo;

-- Movimiento de inventario inicial (carga de stock físico contado)
INSERT INTO movimientos_inventario
    (producto_id, usuario_id, tipo, origen, cantidad, stock_anterior, stock_resultante, motivo)
SELECT id, 1, 'ENTRADA', 'INICIAL', stock_actual, 0, stock_actual, 'Carga inicial de inventario'
  FROM productos;

-- ------------------------------- Configuración -------------------------------
INSERT INTO configuracion (clave, valor, descripcion) VALUES
    ('negocio_nombre',      'Minimarket El Ahorro',  'Nombre comercial del negocio'),
    ('negocio_documento',   '1023456789',            'NIT del negocio'),
    ('negocio_direccion',   'Av. Grigotá 1420, Santa Cruz', 'Dirección del local'),
    ('negocio_telefono',    '33561200',              'Teléfono de contacto'),
    ('moneda_simbolo',      'Bs',                    'Símbolo de la moneda'),
    ('moneda_codigo',       'BOB',                   'Código ISO de la moneda'),
    ('tasa_impuesto',       '0.1300',                'Tasa del IVA (en Bolivia, 13 %)'),
    ('precios_incluyen_impuesto', '0',               'Los precios de venta ya incluyen el impuesto (1 = sí; 0 = se suma encima)'),
    ('descuento_max_cajero','10',                    'Descuento máximo (%) sin autorización'),
    ('egreso_max_cajero',   '200.00',                'Egreso máximo (Bs) que el cajero registra sin autorización'),
    ('cliente_generico_nombre','Cliente varios',     'Texto impreso en el comprobante cuando la venta no tiene cliente registrado'),
    ('dias_max_devolucion', '7',                     'Días máximos tras la venta para aceptar una devolución'),
    ('exigir_referencia_pago', '1',                  'Pedir el número de operación en pagos con tarjeta, billetera o transferencia (1 = sí)'),
    ('dias_max_sustitucion','1',                     'Días máximos tras la venta para sustituir su comprobante (recibo -> factura)'),
    ('serie_factura',       '1',                     'ID de serie F001 usada para facturas (persona jurídica)'),
    ('serie_recibo',        '2',                     'ID de serie R001 usada para recibos (persona natural)');

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
-- -- 2) detalle: el trigger copia el régimen de impuesto del producto y descuenta stock
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
-- SELECT * FROM v_ventas_comprobante WHERE venta_id = @venta;
-- SELECT * FROM v_kardex WHERE venta_id = @venta;

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
-- No se anula la venta ni se toca el stock; solo se reemplaza el documento.
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
-- SELECT * FROM v_comprobantes_sustituidos WHERE venta_id = @venta3;

-- =============================================================================
--  EJEMPLO E: INGRESO DE MERCADERÍA Y AJUSTE DE INVENTARIO (HU-19, HU-20)
-- =============================================================================
-- Cada movimiento del kardex referencia su documento con una FK, según el origen.
--
-- -- COMPRA: entrada de 50 unidades con la guía del proveedor 1
-- -- (movimientos_inventario no tiene trigger, así que aquí SELECT sí es válido)
-- INSERT INTO movimientos_inventario
--     (producto_id, usuario_id, tipo, origen, proveedor_id, documento_externo,
--      cantidad, stock_anterior, stock_resultante, costo_unitario, motivo)
-- SELECT id, 3, 'ENTRADA', 'COMPRA', 1, 'G001-004512',
--        50, stock_actual, stock_actual + 50, precio_compra, 'Reposición de stock'
--   FROM productos WHERE codigo = 'P-0001';
-- UPDATE productos SET stock_actual = stock_actual + 50 WHERE codigo = 'P-0001';
--
-- -- AJUSTE: merma detectada en el conteo físico. El motivo es obligatorio.
-- INSERT INTO movimientos_inventario
--     (producto_id, usuario_id, tipo, origen, cantidad,
--      stock_anterior, stock_resultante, motivo)
-- SELECT id, 3, 'AJUSTE', 'AJUSTE', 2, stock_actual, stock_actual - 2,
--        'Merma por producto vencido - acta 2026-08'
--   FROM productos WHERE codigo = 'P-0004';
-- UPDATE productos SET stock_actual = stock_actual - 2 WHERE codigo = 'P-0004';

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
-- -- Movimiento de kardex con origen VENTA pero sin venta -> ERROR 3819 (ck_movinv_origen)
-- -- INSERT INTO movimientos_inventario
-- --     (producto_id, tipo, origen, cantidad, stock_anterior, stock_resultante)
-- -- VALUES (1, 'SALIDA', 'VENTA', 1, 10, 9);
--
-- -- Movimiento con origen COMPRA pero apuntando a una venta -> ERROR 3819
-- -- (cada origen admite exactamente su referencia y ninguna otra)
--
-- -- Ajuste de inventario sin motivo -> ERROR 3819 (ck_movinv_motivo)
