-- Claves foraneas de `paneles` y `planes`.
--
--   paneles.dominio -> dominios.id   ON DELETE RESTRICT
--   planes.articulo -> articulos.id  ON DELETE RESTRICT
--
-- Continua 20260814_2700_fk_notificaciones_pagos.sql.
-- Idempotente y portable dev/prod. La tabla es `articulos`, sin tilde.
--
--
-- QUEDAN AFUERA LAS TRES FK DE `senales` -- VER ABAJO
--
--   Se pidieron tambien `senales`.`transceptor`, `.dispositivo` y `.canal`,
--   pero NO se declaran en esta migracion porque romperian la ingesta MQTT en
--   produccion. El detalle esta al final de este comentario.
--
--
-- CENSO -- ambas columnas practicamente limpias:
--
--   paneles.dominio : 165 / 162 filas, 0 nulos, 0 ceros, 0 huerfanos -> LIMPIA
--   planes.articulo :  33 /  33 filas, 2 ceros, 0 huerfanos
--
--   RESTRICT en las dos: son estructurales (a que dominio pertenece el panel,
--   que articulo factura el plan), no punteros al "ultimo usado".
--
--   Sin ciclo. Ojo que `dominios` TIENE una columna `paneles` de tipo int, lo
--   que a primera vista parece cerrar un ciclo con `paneles`.`dominio`. No lo
--   es: sus valores son 1, y los ids de `paneles` van de 3 a 225, asi que no
--   es una referencia sino un contador o flag. No se le declara FK.
--
--
-- ---------------------------------------------------------------------------
-- POR QUE `senales` QUEDA PENDIENTE
-- ---------------------------------------------------------------------------
--
--   `senales` la escribe el sistema legacy (el ingestor MQTT de este repo,
--   motor/main.py, todavia tiene el INSERT como TODO), y ese productor escribe
--   CEROS como centinela. Medido en produccion:
--
--     * Ingesta actual: 1367 senales por hora, de las cuales 932 traen
--       `canal` = 0 -- el 68%.
--     * `canales`.`id` = 0 NO existe.
--     * La ultima senal con `canal` = 0 entro hace minutos.
--
--   Declarar `senales`.`canal` -> `canales`.`id` haria que esos INSERT fallen
--   con error 1452. No es un riesgo teorico: se perderian ~930 senales por
--   hora, el grueso de la ingesta. Convertir los 492.263 ceros historicos a
--   NULL no arregla nada, porque el productor sigue escribiendo 0.
--
--   `transceptor` y `dispositivo` son otro caso: tambien tienen ceros (27 en
--   727.573 filas) pero el ultimo es de hace 15 dias, o sea una anomalia vieja
--   y no el comportamiento normal. El riesgo es menor pero no nulo: si el caso
--   se repite, con la FK esa senal se PIERDE en vez de guardarse con 0.
--
--   Para poder declarar las tres hay que actuar primero sobre el productor,
--   que esta fuera de este repositorio: que escriba NULL en lugar de 0 cuando
--   no hay canal / transceptor / dispositivo. Recien despues tiene sentido
--   convertir el historico y poner las constraints.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Neutralizar referencias invalidas.
-- ---------------------------------------------------------------------------

UPDATE `paneles` p SET p.`dominio` = NULL
 WHERE p.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = p.`dominio`);

UPDATE `planes` p SET p.`articulo` = NULL
 WHERE p.`articulo` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `articulos` x WHERE x.`id` = p.`articulo`);


-- ---------------------------------------------------------------------------
-- 2. Alta de las constraints.
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

CALL _reactor_fk('paneles', 'fk_paneles_dominio', 'dominio',  'dominios',  'RESTRICT');
CALL _reactor_fk('planes',  'fk_planes_articulo', 'articulo', 'articulos', 'RESTRICT');

DROP PROCEDURE _reactor_fk;
