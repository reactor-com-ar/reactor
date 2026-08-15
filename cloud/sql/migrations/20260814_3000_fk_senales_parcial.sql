-- Claves foraneas de `senales` -- las dos que no comprometen la ingesta.
--
--   senales.transceptor -> transceptores.id  ON DELETE SET NULL
--   senales.dispositivo -> dispositivos.id   ON DELETE SET NULL
--
-- Continua 20260814_2900_fk_sesiones_sucesos_talonarios_usos.sql.
-- Idempotente y portable dev/prod.
--
--
-- SIGUE AFUERA `senales`.`canal` -- ver 20260814_2800 para el detalle
--
--   El productor legacy escribe `canal` = 0 en el 68% de las senales (932 de
--   1367 por hora, medido en produccion). Con la FK declarada esos INSERT
--   fallarian con error 1452 y se perderia el grueso de la ingesta. No se
--   declara hasta que ese productor escriba NULL en lugar de 0.
--
--
-- POR QUE ESTAS DOS SI
--
--   `transceptor` y `dispositivo` tienen 27 ceros cada una en 727.573 filas, y
--   el ultimo es del 2026-07-31 -- 15 dias antes de esta migracion. En las
--   ultimas 5000 senales no aparecio ninguno. O sea que fue una anomalia
--   puntual y no el comportamiento normal del productor, a diferencia de
--   `canal`.
--
--   RIESGO RESIDUAL ACEPTADO: si esa anomalia se repite, con la FK puesta la
--   senal se RECHAZA en vez de guardarse con 0, o sea que se pierde el dato en
--   lugar de guardarlo degradado. Es el precio de la integridad referencial en
--   una tabla de ingesta cuyo productor no controlamos desde este repo.
--
--   SET NULL en las dos: `senales` es una bitacora de telemetria, y el
--   historial no debe impedir dar de baja un transceptor o un equipo. Mismo
--   criterio que `registros` (migracion 2100) y `notificaciones` (2700).
--
--
-- LIMPIEZA PREVIA -- censo (prod / dev, sobre 727.573 / 863.327 filas):
--
--   transceptor : 27 / 217 ceros, 0 huerfanos
--   dispositivo : 27 / 217 ceros, 0 huerfanos
--
--   Cero huerfanos reales: no se descarta ningun valor, solo se cambia la
--   representacion de "sin asignar" de 0 a NULL.
--
--
-- PESO: `senales` tiene 727.573 filas y hoy solo el indice PRIMARY. InnoDB
--   debe construir 2 indices nuevos. Prever un par de minutos en produccion
--   (la migracion equivalente sobre `registros`, 2,6M filas y 4 indices, tardo
--   5m13s). Efecto colateral bueno: quedan indices sobre `transceptor` y
--   `dispositivo`, que hoy no existen.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Neutralizar los ceros centinela.
-- ---------------------------------------------------------------------------

UPDATE `senales` s SET s.`transceptor` = NULL
 WHERE s.`transceptor` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `transceptores` x WHERE x.`id` = s.`transceptor`);

UPDATE `senales` s SET s.`dispositivo` = NULL
 WHERE s.`dispositivo` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dispositivos` x WHERE x.`id` = s.`dispositivo`);


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

CALL _reactor_fk('senales', 'fk_senales_transceptor', 'transceptor', 'transceptores', 'SET NULL');
CALL _reactor_fk('senales', 'fk_senales_dispositivo', 'dispositivo', 'dispositivos',  'SET NULL');

DROP PROCEDURE _reactor_fk;
