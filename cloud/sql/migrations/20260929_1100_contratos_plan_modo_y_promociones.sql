-- Dos cosas para `contratos`, y la segunda desactiva una bomba que el esquema
-- venia arrastrando:
--
--   `plan_modo`    ENUM('dinamico','fijo'), detras de `plan`. Que se hace con el
--                  plan cuando el dominio crece: `fijo` deja el que se pacto,
--                  `dinamico` lo deja seguir al consumo.
--   `promociones`  tabla nueva con los descuentos, y `contratos`.`promo` pasa a
--                  apuntar ahi en vez de a `articulos`.
--
-- ------------------------------------------------------------------------
-- POR QUE `promo` ESTABA ROTA, Y POR QUE EL `id` DE `promociones` ES EL %
-- ------------------------------------------------------------------------
--
-- `db/schema.sql` declara `FOREIGN KEY (promo) REFERENCES articulos (id)`, pero
-- el sistema historico la usa como un PORCENTAJE: `cContrato::facturar()`
-- calcula `($articulo->venta * $promo) / 100` y el combo `$xContrato->promo`
-- ofrece 0, 10, 20 ... 100. Las dos lecturas no pueden convivir —`articulos`
-- tiene ids 1..279 y ninguno de esos once valores existe ahi—, asi que hasta hoy
-- la columna no se podia escribir: cualquier valor o violaba la FK o repartia un
-- descuento equivocado. Por eso el ABM la mostraba de solo lectura. Hoy no
-- explota porque las 50 filas la tienen en NULL.
--
-- LA TABLA NUEVA RESUELVE LAS DOS LECTURAS A LA VEZ PORQUE **EL `id` ES EL
-- PORCENTAJE**: la fila del 15 % tiene `id = 15`. Entonces `contratos`.`promo`
-- sigue conteniendo exactamente el numero que el legacy espera —su cuenta de
-- facturacion no cambia ni una coma— y ademas es una FK valida contra una tabla
-- que si tiene esa fila. Un `id` AUTO_INCREMENT (1, 2, 3...) daria una FK igual
-- de valida y le haria facturar al legacy 1 %, 2 % y 3 % de descuento sin que
-- nadie lo note: es plata, y por eso la PK va sembrada a mano y sin
-- AUTO_INCREMENT. Agregar manana un 12 % es insertar `id = 12`.
--
-- "SIN PROMOCION" ES `NULL`, NO UNA FILA `id = 0`. Es lo que ya tienen las 50
-- filas y lo que la FK exige: el `0` del sistema historico es un centinela, no
-- una referencia (misma regla que el resto del esquema). Por eso el `0` =
-- "Ninguna" del combo legacy no se migra como fila.
--
-- Se siembra de 5 en 5 —20 filas, del 5 % al 100 %—: el combo viejo iba de 10 en
-- 10 y los once valores que ofrecia quedan incluidos, asi que ninguna promo que
-- el legacy supiera ofrecer desaparece.
--
-- ------------------------------------------------------------------------
-- `plan_modo` NACE EN 'fijo', Y NO ES UN DEFAULT CUALQUIERA
-- ------------------------------------------------------------------------
--
-- Es lo que hacen hoy las 50 filas: el plan de cada contrato se eligio a mano y
-- nada lo mueve. Sembrar `dinamico` le cambiaria el comportamiento a todos los
-- contratos vivos con un ALTER, que es justo lo que una migracion no tiene que
-- hacer. El modo dinamico se elige contrato por contrato, desde el ABM.
--
-- El orden del ENUM es el de declaracion y un valor nuevo va AL FINAL:
-- `ORDER BY plan_modo` ordena por el indice interno, no por el texto.
--
-- IDEMPOTENTE con el patron `SET @s / PREPARE` del resto de las migraciones de
-- este repo: `ADD COLUMN IF NOT EXISTS` no existe en MySQL 8 (dev) aunque si en
-- MariaDB 10.11 (prod), asi que se pregunta a information_schema.
--
-- Sin `USE <base>`: la conexion PDO ya selecciona la del entorno (CLAUDE.md).


-- 1. El catalogo de descuentos. `id` = el porcentaje (ver el encabezado).
--    `habilitado` es la bandera de dos valores de siempre: tinyint(1) NOT NULL
--    DEFAULT 0, y la siembra la pone en 1.
CREATE TABLE IF NOT EXISTS `promociones` (
  `id` int NOT NULL COMMENT 'ES el porcentaje de descuento: la fila del 15 % tiene id 15',
  `nombre` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `habilitado` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- 2. Del 5 % al 100 %, de 5 en 5. `INSERT IGNORE` y no `ON DUPLICATE KEY`: si
--    alguien deshabilito una promo o le cambio el texto, reejecutar la
--    migracion no se lo pisa.
INSERT IGNORE INTO `promociones` (`id`, `nombre`, `habilitado`) VALUES
  (5,   'Descuento 5 %',   1),
  (10,  'Descuento 10 %',  1),
  (15,  'Descuento 15 %',  1),
  (20,  'Descuento 20 %',  1),
  (25,  'Descuento 25 %',  1),
  (30,  'Descuento 30 %',  1),
  (35,  'Descuento 35 %',  1),
  (40,  'Descuento 40 %',  1),
  (45,  'Descuento 45 %',  1),
  (50,  'Descuento 50 %',  1),
  (55,  'Descuento 55 %',  1),
  (60,  'Descuento 60 %',  1),
  (65,  'Descuento 65 %',  1),
  (70,  'Descuento 70 %',  1),
  (75,  'Descuento 75 %',  1),
  (80,  'Descuento 80 %',  1),
  (85,  'Descuento 85 %',  1),
  (90,  'Descuento 90 %',  1),
  (95,  'Descuento 95 %',  1),
  (100, 'Descuento 100 %', 1);

-- 3. `plan_modo`, pegada a `plan`: es como se lee ese plan, no un campo suelto.
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contratos' AND COLUMN_NAME = 'plan_modo'),
    'DO 0',
    'ALTER TABLE `contratos` ADD COLUMN `plan_modo` ENUM(''dinamico'',''fijo'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''fijo'' COMMENT ''que se hace con el plan cuando el dominio crece'' AFTER `plan`'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 4. La FK vieja de `promo` se va. Apuntaba a `articulos`, que es la lectura que
--    nunca se pudo usar (ver el encabezado). Se pregunta por el nombre del
--    constraint, no por la columna: es lo unico estable entre reejecuciones.
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'contratos' AND CONSTRAINT_NAME = 'fk_contratos_promo' AND CONSTRAINT_TYPE = 'FOREIGN KEY'),
    'ALTER TABLE `contratos` DROP FOREIGN KEY `fk_contratos_promo`',
    'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 5. Y la nueva apunta a `promociones`. RESTRICT en los dos lados como el resto
--    del esquema: una promo que algun contrato esta usando no se borra ni se
--    renumera, se deshabilita con `habilitado = 0`.
--    No hace falta limpiar nada antes: las 50 filas tienen `promo` en NULL.
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'contratos' AND CONSTRAINT_NAME = 'fk_contratos_promo' AND CONSTRAINT_TYPE = 'FOREIGN KEY'),
    'DO 0',
    'ALTER TABLE `contratos` ADD CONSTRAINT `fk_contratos_promo` FOREIGN KEY (`promo`) REFERENCES `promociones` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
