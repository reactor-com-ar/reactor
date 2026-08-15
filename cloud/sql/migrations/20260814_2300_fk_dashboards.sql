-- Clave foranea de `dashboards`.
--
--   dashboards.dominio -> dominios.id  ON DELETE RESTRICT
--
-- Continua 20260814_2200_fk_casos_medios_controles_promo.sql.
-- Idempotente y portable dev/prod.
--
-- RESTRICT porque es estructural: a que dominio pertenece el dashboard. No es
-- un puntero al "ultimo usado" como `usuarios`.`panel`.
--
-- LIMPIEZA PREVIA -- censo: 1 fila en cada entorno, 0 nulos, 0 ceros, 0
--   huerfanos. La tabla esta limpia, asi que la FK se declara sin modificar
--   ningun dato. El UPDATE queda escrito solo como defensa ante drift.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Neutralizar referencias invalidas (se espera 0 filas).
-- ---------------------------------------------------------------------------

UPDATE `dashboards` d SET d.`dominio` = NULL
 WHERE d.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = d.`dominio`);


-- ---------------------------------------------------------------------------
-- 2. Alta de la constraint.
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

CALL _reactor_fk('dashboards', 'fk_dashboards_dominio', 'dominio', 'dominios', 'RESTRICT');

DROP PROCEDURE _reactor_fk;
