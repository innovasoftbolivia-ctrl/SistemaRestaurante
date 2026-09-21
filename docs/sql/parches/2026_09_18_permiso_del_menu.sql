-- =============================================================================
--  SISTEMA DEL RESTAURANTE
--  Parche - El permiso del menú se llama como el módulo (2026-09-18)
--
--  El módulo que da de alta los platos se llama «Menú» en todas las
--  pantallas, pero el permiso que lo abre seguía diciendo «Catálogo · Crear y
--  editar productos y precios», que es como lo nombraba el minimarket. Es lo
--  que lee el administrador al armar un rol.
--
--  Solo cambia el texto: el código del permiso (`productos.gestionar`) sigue
--  igual, igual que la ruta y la tabla, para no tocar media aplicación por una
--  etiqueta.
--
--  Idempotente: volver a correrlo no cambia nada.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

UPDATE permisos
   SET modulo      = 'Menú',
       descripcion = 'Crear y editar los platos del menú y sus precios'
 WHERE codigo = 'productos.gestionar';
