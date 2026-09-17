-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Script 02 (producción) - Datos base de una instalación nueva
--  Ejecutar después de 01_schema_mysql.sql
--
--  Es lo que carga `docker-compose.prod.yml` al crear la base de un cliente
--  nuevo, en lugar de docs/sql/02_datos_iniciales.sql. Aquel archivo trae el
--  negocio de demostración —productos con stock, clientes, proveedores y
--  empleados de ejemplo— y sigue siendo el de desarrollo y el de las pruebas.
--
--  Este trae SOLO lo que el sistema necesita para funcionar: roles y permisos,
--  formas de pago, comprobantes, unidades, una caja y un administrador. Los
--  datos del negocio se completan desde Sistema > Configuración.
--
--  La contraseña del administrador NO está aquí: al primer arranque
--  `CredencialesSeeder` genera una aleatoria, la muestra una sola vez en el log
--  del contenedor y obliga a cambiarla al entrar.
--
--  Mantener sincronizado con 02_datos_iniciales.sql: InstalacionLimpiaTest
--  compara permisos, roles, formas de pago, series y claves de configuración
--  de los dos archivos, y falla si uno se queda atrás.
-- =============================================================================

SET NAMES utf8mb4;

USE ventas_db;

-- ---------------------------------- Cargos -----------------------------------
INSERT INTO cargos (id, nombre, descripcion) VALUES
    (1, 'Gerente',        'Dueño o administrador del negocio'),
    (2, 'Cajero',         'Atiende al público y maneja la caja'),
    (3, 'Almacenero',     'Recibe mercadería y controla el inventario'),
    (4, 'Ayudante',       'Apoyo general en tienda y almacén');

-- ------------------------------ Roles y permisos -----------------------------
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

INSERT INTO rol_permiso (rol_id, permiso_id) SELECT 1, id FROM permisos;
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 2, id FROM permisos
 WHERE codigo IN ('ventas.registrar','caja.abrir');
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 3, id FROM permisos
 WHERE codigo IN ('productos.gestionar','inventario.ingresar','inventario.ajustar');
-- Sin `reportes.ver`: los reportes, las ventas, las cajas y las devoluciones son
-- de todos los cajeros, y eso solo lo ve el administrador. Tampoco
-- `registros.eliminar`: el almacenero da de alta y corrige, no elimina.

-- ------------------------------ Administrador --------------------------------
-- Un solo empleado y una sola cuenta, para poder entrar. Sus datos reales se
-- corrigen desde Personal > Empleados; el resto del personal se da de alta ahí.
INSERT INTO empleados (id, cargo_id, tipo_documento, documento, nombres, apellidos,
                       fecha_ingreso, tipo_contrato, estado) VALUES
    (1, 1, 'CI', '0000000', 'Administrador', 'Del sistema', CURDATE(), 'INDEFINIDO', 'ACTIVO');

-- Hash que no corresponde a ninguna contraseña: CredencialesSeeder pone una
-- aleatoria al primer arranque y marca el cambio obligatorio.
INSERT INTO usuarios (empleado_id, rol_id, usuario, password_hash, debe_cambiar_password) VALUES
    (1, 1, 'admin', '$2y$10$abcdefghijklmnopqrstuv0123456789ABCDEFGHIJKLMNOPQRSTU', 1);

-- ------------------------------- Catálogos base ------------------------------
INSERT INTO unidades_medida (id, codigo, nombre, permite_decimal) VALUES
    (1, 'UND',  'Unidad',     0),
    (2, 'KG',   'Kilogramo',  1),
    (3, 'LT',   'Litro',      1),
    (4, 'CAJA', 'Caja',       0),
    (5, 'PQT',  'Paquete',    0);

-- Categorías de partida; el negocio las cambia desde Catálogo > Categorías.
INSERT INTO categorias (id, nombre, descripcion) VALUES
    (1, 'Abarrotes',    'Productos secos de consumo diario'),
    (2, 'Bebidas',      'Gaseosas, aguas y jugos'),
    (3, 'Limpieza',     'Artículos de limpieza del hogar'),
    (4, 'Higiene',      'Cuidado personal'),
    (5, 'Golosinas',    'Dulces y snacks');

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
    (5, 'QR',       'Pago por QR',          0);

INSERT INTO cajas (id, nombre, ubicacion) VALUES
    (1, 'Caja 1', 'Mostrador principal');

-- ------------------------------- Configuración -------------------------------
-- Los datos del negocio quedan de relleno y se completan desde
-- Sistema > Configuración antes de emitir el primer comprobante.
INSERT INTO configuracion (clave, valor, descripcion) VALUES
    ('negocio_nombre',      'Mi negocio',            'Nombre comercial del negocio'),
    ('negocio_documento',   '0000000',               'NIT del negocio'),
    ('negocio_direccion',   '',                      'Dirección del local'),
    ('negocio_telefono',    '',                      'Teléfono de contacto'),
    ('moneda_simbolo',      'Bs',                    'Símbolo de la moneda'),
    ('moneda_codigo',       'BOB',                   'Código ISO de la moneda'),
    -- Un negocio nuevo arranca sin cobrar impuesto y con los precios como el
    -- cliente los ve. Si factura con IVA, se activa en Configuración: con el
    -- precio incluido, lo que paga el cliente no cambia.
    ('tasa_impuesto',       '0.0000',                'Tasa del IVA (en Bolivia, 13 %); 0 = el negocio no cobra impuesto'),
    ('precios_incluyen_impuesto', '1',               'Los precios de venta ya incluyen el impuesto (1 = sí; 0 = se suma encima)'),
    ('descuento_max_cajero','10',                    'Descuento máximo (%) sin autorización'),
    ('egreso_max_cajero',   '200.00',                'Egreso máximo (Bs) que el cajero registra sin autorización'),
    ('cliente_generico_nombre','Cliente varios',     'Texto impreso en el comprobante cuando la venta no tiene cliente registrado'),
    ('dias_max_devolucion', '7',                     'Días máximos tras la venta para aceptar una devolución'),
    ('exigir_referencia_pago', '1',                  'Pedir el número de operación en pagos con tarjeta, billetera o transferencia (1 = sí)'),
    ('dias_max_sustitucion','1',                     'Días máximos tras la venta para sustituir su comprobante (recibo -> factura)'),
    ('serie_factura',       '1',                     'ID de serie F001 usada para facturas (persona jurídica)'),
    ('serie_recibo',        '2',                     'ID de serie R001 usada para recibos (persona natural)');
