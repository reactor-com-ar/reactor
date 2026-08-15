-- Claves foraneas de `usuariosgrupos`, `utilizaciones` y `widgets`.
--
--   usuariosgrupos.dominio -> dominios.id  ON DELETE RESTRICT
--   utilizaciones.dominio  -> dominios.id  ON DELETE RESTRICT
--   utilizaciones.plan     -> planes.id    ON DELETE RESTRICT
--   widgets.dominio        -> dominios.id  ON DELETE RESTRICT
--   widgets.panel          -> paneles.id   ON DELETE RESTRICT
--
-- Continua 20260814_3100_centinela_cero_y_fk_finales.sql.
-- Idempotente y portable dev/prod.
--
--
-- SIN RIESGO PARA NINGUN PRODUCTOR
--
--   A diferencia de `senales` y `sesiones`, estas tablas no reciben escritura
--   con centinelas 0 desde el sistema legacy:
--     * `utilizaciones` esta congelada: su ultimo registro es del 2020-10-12,
--       hace 2133 dias. Es tabla de archivo.
--     * `usuariosgrupos` esta VACIA (0 filas en los dos entornos).
--     * `widgets` no tiene un solo 0 en las dos columnas.
--   Por eso aca se aplica la convencion general -- convertir a NULL -- y no
--   hace falta la fila centinela de la migracion 3100.
--
--   RESTRICT en las cinco: todas son estructurales (a que dominio pertenece el
--   grupo / la utilizacion / el widget, bajo que plan y en que panel).
--
--   Sin ciclos: ni `dominios`, ni `planes`, ni `paneles` apuntan de vuelta.
--
--
-- LIMPIEZA PREVIA -- censo (identico en prod y dev):
--
--   usuariosgrupos.dominio : 0 filas          -> tabla vacia, nada que limpiar
--   utilizaciones.dominio  : 1916 filas, 0 ceros, 0 huerfanos -> LIMPIA
--   utilizaciones.plan     : 1916 filas, 1189 ceros, 629 HUERFANOS
--   widgets.dominio        :  312 filas, 0 ceros, 14 HUERFANOS
--   widgets.panel          :  312 filas, 0 ceros, 16 HUERFANOS
--
--   `utilizaciones`.`plan` es el caso mas fuerte de la serie: entre ceros y
--   huerfanos, 1818 de 1916 filas (95%) quedan en NULL y solo 98 conservan un
--   plan valido. Los 629 huerfanos apuntan TODOS al mismo plan, el 127, que
--   estaba dentro del rango vigente de `planes` (99-150) y fue eliminado. O
--   sea: un unico plan borrado dejo colgadas 629 utilizaciones.
--
--   Se pierde el registro de bajo que plan se facturaron esas 629 filas. No
--   hay forma de reconstruirlo -- el plan no existe --, y al ser una tabla sin
--   escritura desde 2020 el impacto operativo es nulo, pero conviene saberlo:
--   si ese dato historico importa, esta en el backup pre-migracion.
--
--   Los huerfanos de `widgets` son pocos y de padres eliminados:
--     dominio -> 111 y 213     panel -> 27, 31 y 140
--   Son widgets que ya no se pueden renderizar porque su dominio o su panel no
--   existe; quedan desvinculados, y conviene revisarlos aparte por si
--   corresponde darlos de baja.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Neutralizar referencias invalidas.
-- ---------------------------------------------------------------------------

UPDATE `usuariosgrupos` g SET g.`dominio` = NULL
 WHERE g.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = g.`dominio`);

UPDATE `utilizaciones` u SET u.`dominio` = NULL
 WHERE u.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = u.`dominio`);

UPDATE `utilizaciones` u SET u.`plan` = NULL
 WHERE u.`plan` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `planes` x WHERE x.`id` = u.`plan`);

UPDATE `widgets` w SET w.`dominio` = NULL
 WHERE w.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = w.`dominio`);

UPDATE `widgets` w SET w.`panel` = NULL
 WHERE w.`panel` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `paneles` x WHERE x.`id` = w.`panel`);


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

CALL _reactor_fk('usuariosgrupos', 'fk_usuariosgrupos_dominio', 'dominio', 'dominios', 'RESTRICT');
CALL _reactor_fk('utilizaciones',  'fk_utilizaciones_dominio',  'dominio', 'dominios', 'RESTRICT');
CALL _reactor_fk('utilizaciones',  'fk_utilizaciones_plan',     'plan',    'planes',   'RESTRICT');
CALL _reactor_fk('widgets',        'fk_widgets_dominio',        'dominio', 'dominios', 'RESTRICT');
CALL _reactor_fk('widgets',        'fk_widgets_panel',          'panel',   'paneles',  'RESTRICT');

DROP PROCEDURE _reactor_fk;
