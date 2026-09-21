-- `enlaces_acceso` deja de ser "de un solo uso" por definicion: el uso unico
-- pasa a ser el DEFAULT y no la unica opcion. Dos columnas nuevas:
--
--   `usos_max`  cuantas veces se puede canjear el mismo enlace. DEFAULT 1, que
--               es exactamente lo que hacia la tabla hasta hoy.
--   `usos`      cuantas veces se canjeo ya. Es el contador contra el que se
--               compara `usos_max`.
--
-- POR QUE UNA COLUMNA Y NO SEGUIR CON `usada IS NULL`. El candado del canje es
-- un UPDATE condicional que valida y marca en la MISMA sentencia
-- (`app/acceso.php`, `panel/acceso.php`): sin eso, dos requests con el mismo
-- enlace pasarian los dos el chequeo antes de que ninguno escribiera. Ese
-- candado era `usada IS NULL`, que solo sabe decir "cero veces o una". Con el
-- contador pasa a ser `usos < usos_max`, que es la misma sentencia unica —
-- `SET usos = usos + 1 ... WHERE usos < usos_max` se resuelve dentro del lock de
-- fila de InnoDB— y ademas sirve para cualquier cupo.
--
-- `usada` Y `origen_uso` CAMBIAN DE SIGNIFICADO: pasan a ser el ULTIMO uso, no
-- el unico. Con `usos_max = 1` —el default, y lo que tienen todas las filas
-- existentes— significan exactamente lo mismo que antes, asi que ninguna fila
-- vieja se relee distinto. Se elige el ultimo y no el primero porque lo que se
-- necesita al auditar es desde donde se esta usando AHORA; cuando empezo ya lo
-- acota `emitido`, y cuantas veces lo dice `usos`.
--
-- ------------------------------------------------------------------------
-- EL BACKFILL NO ES OPCIONAL: SIN EL, TODOS LOS ENLACES YA USADOS REVIVEN
-- ------------------------------------------------------------------------
--
-- Las columnas nacen en `usos = 0`, y el candado nuevo no mira `usada`. Una fila
-- ya canjeada (`usada` con fecha) quedaria en `0 < 1`, o sea VALIDA otra vez: un
-- token que ya circulo —que pudo quedar en el historial de un navegador, en un
-- chat o en el log de un proxy— volveria a abrir la sesion. Por eso el paso 3
-- lleva a `usos = 1` toda fila con `usada IS NOT NULL`.
--
-- La condicion incluye `usos = 0` para que reejecutar la migracion no sume de a
-- uno. Y no puede alcanzar a ninguna fila escrita por el codigo nuevo: ahi
-- `usada` y `usos` se escriben en la misma sentencia, asi que `usada IS NOT NULL`
-- implica `usos >= 1`.
--
-- IDEMPOTENTE con el patron `SET @s / PREPARE` del resto de las migraciones de
-- este repo: `ADD COLUMN IF NOT EXISTS` no existe en MySQL 8 (dev) aunque si en
-- MariaDB 10.11 (prod), asi que se pregunta a information_schema.
--
-- Sin `USE <base>`: la conexion PDO ya selecciona la del entorno (CLAUDE.md).


-- 1. `usos_max`, detras de `expira`: es el otro limite del enlace y los dos se
--    leen juntos ("hasta cuando" y "cuantas veces").
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enlaces_acceso' AND COLUMN_NAME = 'usos_max'),
    'DO 0',
    'ALTER TABLE `enlaces_acceso` ADD COLUMN `usos_max` int NOT NULL DEFAULT 1 COMMENT ''cuantas veces se puede canjear'' AFTER `expira`'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. `usos`, el contador, pegado a su tope.
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enlaces_acceso' AND COLUMN_NAME = 'usos'),
    'DO 0',
    'ALTER TABLE `enlaces_acceso` ADD COLUMN `usos` int NOT NULL DEFAULT 0 COMMENT ''cuantas veces se canjeo ya'' AFTER `usos_max`'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. Backfill: lo que ya se uso queda agotado. Ver el encabezado — sin este
--    paso, cada enlace canjeado del historial vuelve a ser valido.
UPDATE `enlaces_acceso` SET `usos` = 1 WHERE `usada` IS NOT NULL AND `usos` = 0;
