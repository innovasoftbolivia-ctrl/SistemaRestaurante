-- =============================================================================
--  SISTEMA DEL RESTAURANTE
--  Parche - El número del pedido vale por jornada, no por día (2026-09-18)
--
--  El restaurante cierra pasada la medianoche, y el número del pedido volvía a
--  1 a las 00:00: en la misma noche habría dos «pedido 1». Ahora el contador
--  va por JORNADA, que empieza a la hora de `configuracion.hora_corte_jornada`
--  (5 por omisión, las cinco de la mañana): un pedido de la 01:30 del 19/09 es
--  de la jornada del 18/09 y sigue su numeración.
--
--  - `configuracion.hora_corte_jornada` = 5. Se cambia en Sistema →
--    Configuración, «Número del pedido».
--  - `pedidos.fecha_dia` (columna generada `DATE(fecha_apertura)`) pasa a ser
--    `pedidos.jornada`, una columna NORMAL que escribe
--    `App\Services\Pedidos::abrir()`. Una columna generada no puede leer un
--    valor de la configuración. Se llama `jornada` y no `fecha_dia` porque ya
--    no es la fecha de la apertura: leerla como tal llevaría a error.
--  - `uq_pedido_numero_dia` sigue igual, ahora sobre (jornada, numero_dia).
--
--  LOS PEDIDOS QUE YA EXISTEN NO SE TOCAN. MySQL convierte una columna
--  generada STORED en una normal conservando el valor que ya tenía cada fila,
--  así que cada pedido viejo se queda con su jornada = el día de su apertura,
--  que es con lo que se numeró. NO se recalcula con la regla nueva, a
--  propósito: un «pedido 1» de las 23:00 y otro «pedido 1» de la 01:00 del día
--  siguiente —legítimos con la regla vieja— caerían en la misma jornada y
--  romperían el índice único. Renumerar el historial tampoco: esos números
--  están impresos en tickets que el cliente ya se llevó. La regla nueva vale
--  para los pedidos que se abran de aquí en adelante.
--
--  Consecuencia, solo la noche en que se aplica: si se aplica a mitad de un
--  servicio que ya pasó la medianoche, el primer pedido nuevo toma el número
--  siguiente al último de la jornada de ayer. No se repite ningún número.
--
--  No borra datos. Idempotente: cada paso comprueba si queda algo por hacer.
--  Se aplica después de 2026_09_17_numero_diario_de_pedido.sql.
-- =============================================================================

USE ventas_db;
SET NAMES utf8mb4;

-- ---------------------------------------------------------- la hora de corte
INSERT IGNORE INTO configuracion (clave, valor, descripcion) VALUES
    ('hora_corte_jornada', '5', 'Hora (0 a 12) en que empieza la jornada: lo pedido antes cuenta para la noche anterior');

-- ------------------------------------------------ fecha_dia pasa a ser jornada
-- CHANGE sobre una columna generada STORED la deja como columna normal con el
-- valor que tenía cada fila (no la recalcula). El índice único la sigue solo.
SET @hay := (SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos'
                AND COLUMN_NAME = 'fecha_dia');
SET @sql := IF(@hay,
    'ALTER TABLE pedidos CHANGE COLUMN fecha_dia jornada DATE NOT NULL AFTER numero_dia',
    'SELECT ''pedidos ya tiene jornada'' AS aviso');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
