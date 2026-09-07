-- `permisos`.`slug`: el identificador con el que el codigo va a pedir un
-- permiso cuando se implemente el control de acceso.
--
-- POR QUE NO ALCANZA CON `nombre` NI CON `id`. `nombre` es la ruta del permiso
-- en el menu ("Usuarios > Consultar") y esta pensada para leerse, no para
-- escribirse en una condicion: tiene mayusculas, espacios, acentos y un
-- separador de tres caracteres. `id` si es estable, pero `puede(1005)` no dice
-- nada en el punto de uso. El slug da lo mejor de los dos: `puede('usuarios.consultar')`.
--
-- FORMATO: minusculas, el ` > ` del nombre pasa a `.` y los espacios a `-`. Los
-- acentos se bajan a ASCII, porque un identificador que hay que copiar y pegar
-- con acentos no es un identificador. En los datos actuales aparecen solo tres
-- (a, o, i acentuadas), asi que la cadena de REPLACE los cubre todos --
-- verificado contando los caracteres no-ASCII de las 113 filas.
--
-- REPLACE NO ES SENSIBLE A LA COLLATION. `permisos` es utf8mb4_unicode_ci, que
-- es insensible a mayusculas Y a acentos, asi que a primera vista
-- `REPLACE(nombre,'a','a')` daria miedo: podria comerse tambien las vocales sin
-- acento. No pasa -- REPLACE() compara byte a byte, no por collation.
-- Verificado sobre los datos reales antes de escribir esto: "Parametros" queda
-- "parametros" y "Usuarios" queda "usuarios", sin tocar las vocales limpias.
--
-- LAS 20 FILAS CON SLUG DUPLICADO LLEVAN EL ID PEGADO, y son deuda heredada.
-- Diez nombres estan repetidos dos veces cada uno (`Usuarios`, `Usuarios >
-- Consultar`, `Inscripciones`, `Documentos`...): son los permisos que hasta el
-- 06/09/2026 se distinguian por la columna `sistema` (uno de Reactor Admin y
-- otro de Reactor Control). Al eliminarse esa columna quedaron indistinguibles
-- por nombre. El slug no puede repetirse -- es su unico proposito --, asi que a
-- LOS DOS miembros de cada par se les agrega `-<id>`: `usuarios-1004` y
-- `usuarios-1022`. No se elige un ganador que se quede con el slug limpio
-- porque no hay criterio para elegirlo; eso es una decision de negocio.
-- **Esos 20 permisos conviene revisarlos a mano**: probablemente sobre uno de
-- cada par, y al borrarlo el otro puede recuperar el slug corto.
--
-- El UNIQUE va DESPUES de la desambiguacion, no antes: al reves la migracion
-- fallaria al sembrar.
--
-- Idempotente: las tres ALTER van guardadas por information_schema con el mismo
-- patron `SET @s / PREPARE` de `20260906_1300` (`DROP/ADD COLUMN IF EXISTS` no
-- existe en MySQL 8, y dev corre MySQL mientras prod corre MariaDB), y los dos
-- UPDATE solo tocan filas sin slug.
--
-- No usar `USE <db>` aca: la conexion PDO ya selecciona la DB del entorno via
-- DB_NAME (`reactor` en prod, `reactor_dev` en dev).

-- 1. La columna, nullable por ahora: el UNIQUE y el NOT NULL van al final,
--    cuando ya no quede ninguna vacia.
SET @s = (SELECT IF(NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'permisos' AND COLUMN_NAME = 'slug'),
    'ALTER TABLE `permisos` ADD COLUMN `slug` VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `id`',
    'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. Slug base. El orden de los REPLACE importa: ` > ` se resuelve ANTES que
--    el espacio suelto, o quedaria `-`-`-` en el medio.
UPDATE `permisos`
   SET `slug` = LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`nombre`, 'á', 'a'), 'ó', 'o'), 'í', 'i'), ' > ', '.'), ' ', '-'))
 WHERE `slug` IS NULL OR `slug` = '';

-- 3. Desambiguacion de los repetidos. La subconsulta va envuelta en un segundo
--    SELECT para forzar su materializacion: sin eso MySQL la fusiona con el
--    UPDATE y corta con el error 1093 ("You can't specify target table
--    'permisos' for update in FROM clause").
UPDATE `permisos` p
  JOIN (SELECT * FROM (SELECT `slug` AS s FROM `permisos` GROUP BY `slug` HAVING COUNT(*) > 1) x) d
    ON d.s = p.`slug`
   SET p.`slug` = CONCAT(p.`slug`, '-', p.`id`);

-- 4. Recien ahora: obligatorio y unico. Las dos cosas en una sola ALTER para no
--    dejar un estado intermedio con NOT NULL pero sin UNIQUE.
SET @s = (SELECT IF(NOT EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'permisos' AND INDEX_NAME = 'uq_permisos_slug'),
    'ALTER TABLE `permisos` MODIFY `slug` VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, ADD UNIQUE KEY `uq_permisos_slug` (`slug`)',
    'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
