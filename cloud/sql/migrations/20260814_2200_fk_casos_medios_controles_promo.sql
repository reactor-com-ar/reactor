-- Claves foraneas de `casos`, `comprobantes`.`medio`, `controles`, y la de
-- `contratos`.`promo` que quedo pendiente en la migracion 2000.
--
--   casos.autor           -> usuarios.id      ON DELETE SET NULL
--   comprobantes.medio    -> medios.id        ON DELETE RESTRICT
--   contratos.promo       -> articulos.id     ON DELETE RESTRICT
--   controles.dominio     -> dominios.id      ON DELETE RESTRICT
--   controles.panel       -> paneles.id       ON DELETE RESTRICT
--   controles.dispositivo -> dispositivos.id  ON DELETE RESTRICT
--
-- Continua 20260814_2100_fk_registros_carritos.sql. Idempotente y portable.
--
--
-- `contratos`.`promo` -- AHORA SI SE PUEDE, PERO APUNTA A `articulos`
--
--   En la migracion 2000 esta FK quedo afuera porque se pidio contra una tabla
--   `promos` que no existe en ningun entorno. Redirigida a `articulos`, si es
--   declarable, pero hay que saber dos cosas:
--
--     1. La columna es `varchar(5)` y `articulos`.`id` es int, asi que hay que
--        convertirla igual que se hizo con `articulos`.`categoria` en la
--        migracion 1600. Se NULea el centinela ANTES de tocar el tipo.
--
--     2. Las 53 filas de `contratos` (50 en dev) tienen promo = '0'. TODAS.
--        No hay un solo valor real. Al pasar a NULL la columna queda 100%
--        vacia y la FK no valida nada hoy.
--
--   Se declara igual porque su valor es hacia adelante: a partir de ahora
--   nadie puede escribir en `promo` un articulo que no exista. Sin la
--   constraint, el proximo que empiece a usar la columna puede meter cualquier
--   numero. Pero conviene tenerlo claro -- esta FK no "arregla" datos
--   existentes, no hay ninguno.
--
--
-- POLITICAS
--
--   `casos`.`autor` -> SET NULL. Atribucion historica (quien escribio el
--     caso), mismo criterio que `usuarios`.`registrante`, `adopciones`.
--     `adoptador` y `registros`.`usuario`. Que se pierda el autor al borrar el
--     usuario es aceptable; que no se pueda borrar un usuario porque escribio
--     un caso hace anios, no.
--
--   Las otras cinco -> RESTRICT. `medio` y `promo` son referencias de catalogo
--     (medio de pago, articulo en promocion) y `controles`.`dominio`/`panel`/
--     `dispositivo` son estructurales: un control que perdiera en silencio su
--     dispositivo quedaria inutilizable, igual que los botones de la
--     migracion 1700.
--
--   NO se forma ningun ciclo. `controles` ya era padre de `botones`.`control`
--   (migracion 1700); ahora pasa a ser tambien hijo de dominios / paneles /
--   dispositivos. Es una cadena -- botones -> controles -> dispositivos --,
--   no un ciclo: ninguna de esas tablas apunta de vuelta a `controles`.
--
--
-- LIMPIEZA PREVIA -- censo (prod / dev):
--
--   casos.autor           : 40 / 40 filas, 0 ceros, 0 huerfanos -> LIMPIA
--   comprobantes.medio    : 12 / 8 ceros, 0 huerfanos
--   controles.dominio     : 85 / 81 filas, 0 ceros, 0 huerfanos -> LIMPIA
--   controles.dispositivo : 85 / 81 filas, 0 ceros, 0 huerfanos -> LIMPIA
--   controles.panel       : 0 ceros, 1 HUERFANO
--   contratos.promo       : 53 / 50 filas, TODAS con '0'
--
--   El unico huerfano real es el control 353, que apunta al panel 211 -- dentro
--   del rango vigente de `paneles` (3-225), o sea que existio y se elimino. El
--   control sigue vinculado a su dominio (272) y su dispositivo (424); lo unico
--   que pierde es el panel donde se mostraba.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Neutralizar referencias invalidas.
-- ---------------------------------------------------------------------------

UPDATE `casos` c SET c.`autor` = NULL
 WHERE c.`autor` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `usuarios` x WHERE x.`id` = c.`autor`);

UPDATE `comprobantes` c SET c.`medio` = NULL
 WHERE c.`medio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `medios` x WHERE x.`id` = c.`medio`);

UPDATE `controles` c SET c.`dominio` = NULL
 WHERE c.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = c.`dominio`);

UPDATE `controles` c SET c.`panel` = NULL
 WHERE c.`panel` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `paneles` x WHERE x.`id` = c.`panel`);

UPDATE `controles` c SET c.`dispositivo` = NULL
 WHERE c.`dispositivo` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dispositivos` x WHERE x.`id` = c.`dispositivo`);


-- ---------------------------------------------------------------------------
-- 2. contratos.promo -- centinelas a NULL ANTES de cambiar el tipo.
-- ---------------------------------------------------------------------------

UPDATE `contratos` SET `promo` = NULL
 WHERE `promo` IS NOT NULL
   AND (TRIM(`promo`) = '' OR TRIM(`promo`) = '0');


-- ---------------------------------------------------------------------------
-- 3. contratos.promo -- varchar(5) -> int, solo si hace falta.
-- ---------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS _reactor_promo_a_int;
CREATE PROCEDURE _reactor_promo_a_int()
BEGIN
    IF (SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'contratos'
           AND COLUMN_NAME  = 'promo') <> 'int' THEN
        ALTER TABLE `contratos` MODIFY COLUMN `promo` int NULL DEFAULT NULL;
    END IF;
END;

CALL _reactor_promo_a_int();
DROP PROCEDURE _reactor_promo_a_int;

-- Ya como int, por si quedara algun valor que no exista en `articulos`.
UPDATE `contratos` c SET c.`promo` = NULL
 WHERE c.`promo` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `articulos` x WHERE x.`id` = c.`promo`);


-- ---------------------------------------------------------------------------
-- 4. Alta de las constraints.
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

    IF (SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabla) = 0 THEN
        SET @noop = 1;
    ELSE
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
    END IF;
END;

CALL _reactor_fk('casos',        'fk_casos_autor',           'autor',       'usuarios',     'SET NULL');
CALL _reactor_fk('comprobantes', 'fk_comprobantes_medio',    'medio',       'medios',       'RESTRICT');
CALL _reactor_fk('contratos',    'fk_contratos_promo',       'promo',       'articulos',    'RESTRICT');
CALL _reactor_fk('controles',    'fk_controles_dominio',     'dominio',     'dominios',     'RESTRICT');
CALL _reactor_fk('controles',    'fk_controles_panel',       'panel',       'paneles',      'RESTRICT');
CALL _reactor_fk('controles',    'fk_controles_dispositivo', 'dispositivo', 'dispositivos', 'RESTRICT');

DROP PROCEDURE _reactor_fk;
