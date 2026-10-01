-- `contratos`.`promo` deja de ser una FK y vuelve a ser lo unico que siempre
-- fue: un PORCENTAJE de descuento, de 0 a 100. Se va la tabla `promociones` que
-- creo la migracion `20260929_1100` un dia antes, se va la FK y se va el indice
-- que la acompanaba.
--
-- ------------------------------------------------------------------------
-- POR QUE SE DESHACE UNA TABLA DE AYER
-- ------------------------------------------------------------------------
--
-- El problema que `promociones` resolvia era real: el esquema declaraba
-- `FOREIGN KEY (promo) REFERENCES articulos (id)` mientras el sistema historico
-- la lee como porcentaje -- `cContrato::facturar()` calcula
-- `($articulo->venta * $promo) / 100` --, y las dos lecturas no podian convivir
-- (`articulos` tiene ids 1..279 y ninguno de los valores del combo legacy existe
-- ahi), asi que la columna no se podia escribir sin violar la FK o repartir un
-- descuento equivocado.
--
-- La tabla lo resolvio sembrando el porcentaje COMO PK -- la fila del 15 % con
-- `id = 15` -- y asi satisfacia las dos lecturas a la vez. Funcionaba, pero
-- deja a la vista que el catalogo no agregaba nada: una tabla cuya unica
-- columna util es su propia PK, con veinte filas que no son mas que los veinte
-- numeros que ya se podian escribir en la columna. El `nombre` ("Descuento
-- 15 %") es el `id` en castellano y el `habilitado` apagaba un numero, no un
-- dato. Para ofrecer un 12 % habia que insertar una fila; y renumerar una
-- cualquiera le cambiaba el descuento a todos los contratos que la usaban, de
-- ahi el `ON UPDATE RESTRICT`.
--
-- SACANDO LA FK, LA COLUMNA GUARDA EL MISMO NUMERO QUE EL LEGACY ESPERA Y YA NO
-- HAY NADA QUE PUEDA DESALINEARSE. El ABM la escribe con un campo numerico de 0
-- a 100 y la valida ahi (`cloud/api/contratos.php`): el rango lo da la cuenta
-- del descuento, no un catalogo. La cuenta de `cContrato::facturar()` no cambia
-- ni una coma, que es la unica invariante que habia que cuidar.
--
-- ------------------------------------------------------------------------
-- NO HAY DATOS QUE MIGRAR
-- ------------------------------------------------------------------------
--
-- Las 50 filas de `contratos` tienen `promo` en NULL -- lo verificado en dev
-- antes de escribir esta migracion, y es lo mismo que ya decia la migracion
-- `20260929_1100`: la columna nunca se pudo escribir. Ningun contrato apunta a
-- `promociones`, asi que el DROP no arrastra nada y el `UPDATE` defensivo del
-- paso 3 no toca ninguna fila en ningun entorno conocido. Va igual porque una
-- base con un valor fuera de rango bloquearia el DROP sin decir por que.
--
-- "SIN PROMOCION" SIGUE SIENDO `NULL`, no `0`: es lo que tienen las 50 filas y
-- la misma regla del resto del esquema -- el `0` del sistema historico es un
-- centinela, no un dato. El endpoint guarda NULL tambien cuando le mandan 0,
-- porque un descuento del 0 % es no tener promocion y dos formas de escribir lo
-- mismo en una columna es justo lo que el centinela ya hizo una vez.
--
-- IDEMPOTENTE con el patron `SET @s / PREPARE` del resto de las migraciones de
-- este repo: `DROP FOREIGN KEY IF EXISTS` no existe en MySQL 8 (dev) aunque si
-- en MariaDB 10.11 (prod), asi que se pregunta a information_schema.
--
-- Sin `USE <base>`: la conexion PDO ya selecciona la del entorno (CLAUDE.md).


-- 1. La FK. Se pregunta por el nombre del constraint, que es lo unico estable
--    entre reejecuciones.
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'contratos' AND CONSTRAINT_NAME = 'fk_contratos_promo' AND CONSTRAINT_TYPE = 'FOREIGN KEY'),
    'ALTER TABLE `contratos` DROP FOREIGN KEY `fk_contratos_promo`',
    'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. Y el indice que MySQL habia creado para sostenerla. Sin FK no lo usa
--    nadie: `promo` no se filtra ni se ordena por ella en ninguna consulta.
--    Va DESPUES del paso 1 -- con la FK viva el motor rechaza el DROP INDEX.
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contratos' AND INDEX_NAME = 'fk_contratos_promo'),
    'ALTER TABLE `contratos` DROP INDEX `fk_contratos_promo`',
    'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. Defensivo: cualquier valor que no sea un porcentaje vuelve a NULL. En dev
--    y en prod no hay ninguno (las 50 filas estan en NULL), pero si lo hubiera
--    quedaria guardado un descuento que el ABM ya no puede ni mostrar ni
--    corregir. El `0` entra en el mismo saco que el resto: es el centinela.
UPDATE `contratos` SET `promo` = NULL
 WHERE `promo` IS NOT NULL
   AND (`promo` <= 0 OR `promo` > 100);

-- 4. El comentario de la columna, que es donde queda escrito el rango ahora que
--    no hay catalogo que lo defina. El tipo no cambia: sigue siendo `int NULL`.
ALTER TABLE `contratos`
  MODIFY COLUMN `promo` int DEFAULT NULL
  COMMENT 'porcentaje de descuento sobre el abono del plan: 1 a 100, NULL = sin promocion';

-- 5. Y el catalogo se va. Despues de los pasos 1 y 2: con la FK viva el DROP
--    falla.
DROP TABLE IF EXISTS `promociones`;
