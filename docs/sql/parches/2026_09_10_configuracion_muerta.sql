-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Quitar dos parámetros que no hacen nada (2026-09-10)
--
--  Qué quita
--  ---------
--      precio_incluye_impuesto   nadie lo lee
--      serie_nota_venta          nadie lo lee
--
--  Por qué molesta que estén
--  -------------------------
--  Un parámetro que aparenta hacer algo y no hace nada es peor que no tenerlo.
--  Quien abra la tabla `configuracion` y ponga `precio_incluye_impuesto = 1`
--  va a esperar que los precios pasen a incluir impuesto, y no va a pasar
--  nada: el sistema SIEMPRE guarda el precio sin impuesto y lo agrega al
--  calcular. No hay error, no hay aviso; simplemente no ocurre. Eso se
--  descubre tarde y mal.
--
--  `serie_nota_venta` apunta a la serie NV01, que existe en el catálogo pero
--  que ninguna venta usa: el sistema elige entre factura y recibo según el
--  tipo de persona, y la nota de venta interna nunca se implementó.
--
--  La SERIE no se toca: sigue en el catálogo por si alguna vez se usa. Lo que
--  se va es el parámetro que decía apuntar a ella sin que nadie lo consultara.
--
--  Los que SÍ se usan y se quedan: serie_factura y serie_recibo, que
--  `Ventas::serieDe()` lee para decidir qué documento emitir.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

DELETE FROM configuracion WHERE clave IN ('precio_incluye_impuesto', 'serie_nota_venta');

SELECT clave, valor, descripcion FROM configuracion ORDER BY clave;
