-- =============================================================================
--  SISTEMA DE VENTA DE PRODUCTOS
--  Parche - Una sola sesión de caja abierta por usuario (2026-09-05)
--
--  Problema que corrige
--  --------------------
--  `sesiones_caja` ya impedía dos turnos abiertos en la MISMA caja física
--  (columna generada `caja_abierta_uk` + índice único), pero nada impedía que
--  el MISMO cajero abriera un turno en una caja, y sin cerrarlo, abriera otro
--  en una caja distinta. `Cajas::abrir()` solo se protegía con un
--  SELECT-antes-de-INSERT en PHP — una condición de carrera real bajo dos
--  peticiones simultáneas, y ni siquiera eso: nada evitaba abrir dos turnos
--  en cajas distintas uno después del otro, con calma, porque el chequeo era
--  "¿ya tenés una sesión abierta?", no "¿ya tenés una sesión abierta en OTRA
--  caja?" — ambas cosas daban `false` la primera vez, y la segunda apertura
--  simplemente no se intentaba desde la UI, pero nada en la base la hubiera
--  rechazado si se intentaba.
--
--  Con dos sesiones abiertas para el mismo cajero, `Cajas::sesionDe()` (que
--  usa `->first()`, sin criterio de desempate) atribuye todas sus ventas
--  siempre a una sola de las dos, de forma no determinista, mientras el
--  efectivo real queda repartido entre dos cajones distintos. Al cerrar
--  cualquiera de las dos, el arqueo no cuadra por un motivo que no es
--  responsabilidad del cajero.
--
--  Qué hace
--  --------
--  Agrega una columna generada `usuario_abierta_uk` (el `usuario_apertura_id`
--  solo mientras `estado = 'ABIERTA'`, `NULL` en cualquier otro estado) con un
--  índice único encima — mismo patrón que ya protege la caja física. Ahora la
--  propia base rechaza el segundo INSERT si el usuario ya tiene una sesión
--  abierta, sin ventana de carrera posible.
--
--  Es idempotente: si la columna ya existe, no hace nada.
--
--      docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 \
--          -uroot -pventas123 ventas_db \
--          < docs/sql/parches/2026_09_05_sesion_unica_por_cajero.sql
--
--  Los instaladores nuevos NO necesitan este parche: 01_schema_mysql.sql ya
--  trae la columna y el índice desde el alta.
-- =============================================================================

SET NAMES utf8mb4;

SET @existe_columna = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'sesiones_caja'
       AND COLUMN_NAME  = 'usuario_abierta_uk'
);

SET @sql_columna = IF(@existe_columna = 0,
    'ALTER TABLE sesiones_caja
         ADD COLUMN usuario_abierta_uk INT UNSIGNED
             GENERATED ALWAYS AS (IF(estado = ''ABIERTA'', usuario_apertura_id, NULL)) VIRTUAL,
         ADD UNIQUE KEY uq_sesion_usuario_abierta (usuario_abierta_uk)',
    'SELECT 1'
);

PREPARE stmt FROM @sql_columna;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
