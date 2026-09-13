-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - El impuesto se llama IVA, no IGV (2026-09-13)
--
--  Qué cambia
--  ----------
--  Solo la DESCRIPCIÓN del parámetro `tasa_impuesto`, que decía «IGV»: el
--  impuesto de Perú. En Bolivia es el IVA, del 13 %.
--
--  Qué NO cambia, a propósito
--  --------------------------
--  El VALOR de la tasa. Una instalación en uso ya tiene la suya elegida —la
--  demo corre al 0 %, un negocio que desglosa IVA tendrá 13 %— y cambiarla
--  por parche movería los precios de estante de todo el catálogo sin que
--  nadie lo decida. La tasa se elige ahora desde Sistema > Configuración.
--
--  Las instalaciones NUEVAS sí arrancan en 13 %: eso lo pone
--  02_datos_iniciales.sql, con los precios base recalculados para que los
--  precios de estante sigan siendo los mismos que con el 18 % de antes.
-- =============================================================================

USE ventas_db;

UPDATE configuracion
   SET descripcion = 'Tasa del IVA (en Bolivia, 13 %)'
 WHERE clave = 'tasa_impuesto';

SELECT clave, valor, descripcion FROM configuracion WHERE clave = 'tasa_impuesto';
