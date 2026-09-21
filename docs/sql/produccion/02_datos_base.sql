-- =============================================================================
--  SISTEMA DEL RESTAURANTE
--  Script 02 (producción) - Datos base de una instalación nueva
--  Ejecutar después de 01_schema_mysql.sql
--
--  Es lo que carga `docker-compose.prod.yml` al crear la base de un cliente
--  nuevo, en lugar de docs/sql/02_datos_iniciales.sql. Aquel archivo trae el
--  negocio de demostración —productos, clientes y empleados de ejemplo— y
--  sigue siendo el de desarrollo y el de las pruebas.
--
--  Este trae SOLO lo que el sistema necesita para funcionar: roles y permisos,
--  formas de pago, comprobantes, una caja y un administrador. Los
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
    (4, 'Ayudante',       'Apoyo general en el local'),
    (5, 'Cocinero',       'Prepara los platos en la cocina');

-- ------------------------------ Roles y permisos -----------------------------
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
    ('cocina.entregar',      'Cocina',     'Ver la pantalla de la cocina y marcar entregado lo que ya está listo'),
    ('caja.abrir',           'Caja',       'Abrir sesión de caja'),
    ('caja.cerrar',          'Caja',       'Cerrar sesión de caja'),
    ('reportes.ver',         'Reportes',   'Consultar reportes y dashboard'),
    ('configuracion.editar', 'Sistema',    'Editar parámetros del sistema'),
    ('bitacora.ver',         'Sistema',    'Consultar la bitácora de operaciones'),
    ('clientes.editar',      'Ventas',     'Editar los datos de un cliente ya registrado'),
    ('registros.eliminar',   'Sistema',    'Eliminar productos, categorías, clientes y personal');

INSERT INTO rol_permiso (rol_id, permiso_id) SELECT 1, id FROM permisos;
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 2, id FROM permisos
 WHERE codigo IN ('ventas.registrar','caja.abrir','pedidos.registrar','cocina.entregar');
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 4, id FROM permisos WHERE codigo IN ('cocina.ver');
-- Sin `reportes.ver`: las ventas y las cajas son de todos los cajeros, y eso
-- solo lo ve el administrador. La cocina tampoco toca la carta.
-- El rol 3 y el cargo 3 (Mozo y Mesero) ya no existen: el local no trabaja
-- con mozos. Sus números quedan sin usar, igual que en 02_datos_iniciales.sql.

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
-- Secciones de partida de la carta; el negocio las cambia desde
-- Carta > Categorías.
-- Las bebidas no pasan por la cocina: se cobran, pero no van a su pantalla
-- ni a la comanda. Se cambia en Menú → Categorías.
INSERT INTO categorias (id, nombre, descripcion, pasa_por_cocina) VALUES
    (1, 'Entradas',         'Para picar mientras llega el plato', 1),
    (2, 'Platos de fondo',  'El plato principal',                 1),
    (3, 'Bebidas',          'Refrescos, jugos y gaseosas',        0),
    (4, 'Postres',          'Para terminar',                      1);

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
    ('moneda_codigo',       'BOB',                   'Código ISO de la moneda'),
    -- Un negocio nuevo arranca sin cobrar impuesto y con los precios como el
    -- cliente los ve. Si factura con IVA, se activa en Configuración: con el
    -- precio incluido, lo que paga el cliente no cambia.
    ('tasa_impuesto',       '0.0000',                'Tasa del IVA (en Bolivia, 13 %); 0 = el negocio no cobra impuesto'),
    ('precios_incluyen_impuesto', '1',               'Los precios de venta ya incluyen el impuesto (1 = sí; 0 = se suma encima)'),
    ('descuento_max_cajero','10',                    'Descuento máximo (%) sin autorización'),
    ('egreso_max_cajero',   '200.00',                'Egreso máximo (Bs) que el cajero registra sin autorización'),
    ('cliente_generico_nombre','Cliente varios',     'Texto impreso en el comprobante cuando la venta no tiene cliente registrado'),
    ('exigir_referencia_pago', '1',                  'Pedir el número de operación en pagos con tarjeta, billetera o transferencia (1 = sí)'),
    ('dias_max_sustitucion','1',                     'Días máximos tras la venta para sustituir su comprobante (recibo -> factura)'),
    ('hora_corte_jornada',  '5',                     'Hora (0 a 12) en que empieza la jornada: lo pedido antes cuenta para la noche anterior'),
    ('cajero_cierra_su_caja', '0',                'El cajero cierra su propia caja, sin ver el esperado (1 = sí; 0 = lo cierra un administrador)');
