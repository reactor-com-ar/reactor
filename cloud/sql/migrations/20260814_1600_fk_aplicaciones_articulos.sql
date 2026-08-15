-- Claves foraneas de `aplicaciones` y `articulos`.
--
--   aplicaciones.dominio -> dominios.id             ON DELETE RESTRICT
--   articulos.categoria  -> articuloscategorias.id  ON DELETE RESTRICT
--
-- Continua 20260814_1500_fk_adopciones.sql. Idempotente y portable dev/prod.
--
--
-- CAMBIO DE TIPO EN `articulos`.`categoria`  (precondicion, no cosmetica)
--
--   La columna es `varchar(10)` y `articuloscategorias`.`id` es `int`. Una FK
--   exige que los tipos coincidan exactamente, asi que la constraint NO se
--   puede declarar sin convertir la columna primero. Por eso esta migracion
--   incluye un `MODIFY COLUMN`, que va mas alla de solo declarar la relacion.
--
--   Es seguro hacerlo:
--     * Los 106 articulos tienen valores numericos o centinela -- ni uno solo
--       con texto. Distribucion identica en prod y dev:
--           ''  -> 22    '0' -> 72    '1' -> 5    '2' -> 1
--           '3' ->  1    '4' ->  3    '5' -> 1    '6' -> 1
--       O sea 94 de 106 son "sin categoria" y solo 12 tienen categoria real.
--     * Las categorias vigentes son los ids 1..7, con lo que los 12 valores
--       reales apuntan todos a filas existentes: 0 huerfanos.
--     * NINGUN archivo del repo referencia `articulos`.`categoria`, asi que no
--       hay comparaciones de tipo (`=== '3'`) que se rompan al pasar a int.
--
--   Orden obligatorio: primero se NULean los centinelas y despues se cambia el
--   tipo. Al reves, convertir '' a int daria 0 (con warning, o error en modo
--   estricto) y se perderia la distincion entre "sin categoria" y la categoria
--   numero cero.
--
--   Aca el centinela es doble: cadena vacia Y '0'. Ninguno de los dos es una
--   referencia valida -- `articuloscategorias` no tiene fila con id=0.
--
--
-- `aplicaciones`.`dominio`: censo limpio en los dos entornos -- 11 filas, 0
--   nulos, 0 ceros, 0 huerfanos. La FK se declara sin modificar ningun dato.
--   El UPDATE queda escrito solo como defensa ante drift.
--
-- Ambas con RESTRICT: son relaciones estructurales (a que dominio pertenece la
-- aplicacion, de que categoria es el articulo), no punteros al "ultimo usado".
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. aplicaciones.dominio -- neutralizar invalidos (se espera 0 filas).
-- ---------------------------------------------------------------------------

UPDATE `aplicaciones` a SET a.`dominio` = NULL
 WHERE a.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = a.`dominio`);


-- ---------------------------------------------------------------------------
-- 2. articulos.categoria -- centinelas a NULL ANTES de tocar el tipo.
-- ---------------------------------------------------------------------------

UPDATE `articulos` SET `categoria` = NULL
 WHERE `categoria` IS NOT NULL
   AND (TRIM(`categoria`) = '' OR TRIM(`categoria`) = '0');


-- ---------------------------------------------------------------------------
-- 3. articulos.categoria -- varchar(10) -> int, solo si hace falta.
-- ---------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS _reactor_categoria_a_int;
CREATE PROCEDURE _reactor_categoria_a_int()
BEGIN
    IF (SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'articulos'
           AND COLUMN_NAME  = 'categoria') <> 'int' THEN
        ALTER TABLE `articulos` MODIFY COLUMN `categoria` int NULL DEFAULT NULL;
    END IF;
END;

CALL _reactor_categoria_a_int();
DROP PROCEDURE _reactor_categoria_a_int;


-- ---------------------------------------------------------------------------
-- 4. Ya como int, neutralizar cualquier categoria inexistente (se espera 0).
-- ---------------------------------------------------------------------------

UPDATE `articulos` a SET a.`categoria` = NULL
 WHERE a.`categoria` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `articuloscategorias` x WHERE x.`id` = a.`categoria`);


-- ---------------------------------------------------------------------------
-- 5. Alta de las constraints.
-- ---------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS _reactor_fk;
CREATE PROCEDURE _reactor_fk(
    IN p_tabla   VARCHAR(64),
    IN p_nombre  VARCHAR(64),
    IN p_columna VARCHAR(64),
    IN p_padre   VARCHAR(64),
    IN p_on_del  VARCHAR(16)
)
BEGIN
    DECLARE v_regla VARCHAR(16);

    SET v_regla = (
        SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME        = p_tabla
           AND CONSTRAINT_NAME   = p_nombre
    );

    IF v_regla IS NOT NULL AND v_regla <> p_on_del THEN
        SET @s = CONCAT('ALTER TABLE `', p_tabla, '` DROP FOREIGN KEY `', p_nombre, '`');
        PREPARE st FROM @s;
        EXECUTE st;
        DEALLOCATE PREPARE st;
        SET v_regla = NULL;
    END IF;

    IF v_regla IS NULL THEN
        SET @s = CONCAT('ALTER TABLE `', p_tabla, '` ADD CONSTRAINT `', p_nombre,
                        '` FOREIGN KEY (`', p_columna, '`) REFERENCES `', p_padre,
                        '` (`id`) ON DELETE ', p_on_del, ' ON UPDATE RESTRICT');
        PREPARE st FROM @s;
        EXECUTE st;
        DEALLOCATE PREPARE st;
    END IF;
END;

CALL _reactor_fk('aplicaciones', 'fk_aplicaciones_dominio', 'dominio',   'dominios',            'RESTRICT');
CALL _reactor_fk('articulos',    'fk_articulos_categoria',  'categoria', 'articuloscategorias', 'RESTRICT');

DROP PROCEDURE _reactor_fk;
