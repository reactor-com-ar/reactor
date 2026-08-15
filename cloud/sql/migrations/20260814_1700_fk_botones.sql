-- Claves foraneas de `botones`.
--
--   botones.dominio     -> dominios.id      ON DELETE RESTRICT
--   botones.panel       -> paneles.id       ON DELETE RESTRICT
--   botones.control     -> controles.id     ON DELETE RESTRICT
--   botones.dispositivo -> dispositivos.id  ON DELETE RESTRICT
--   botones.canal       -> canales.id       ON DELETE RESTRICT
--   botones.icono       -> iconos.id        ON DELETE RESTRICT
--
-- Continua 20260814_1600_fk_aplicaciones_articulos.sql. Idempotente y portable.
--
--
-- POR QUE LAS SEIS CON RESTRICT
--
--   Todas son referencias estructurales o de catalogo: a que dominio y panel
--   pertenece el boton, sobre que control / dispositivo / canal actua, y que
--   icono lo representa. Ninguna es un puntero de conveniencia al "ultimo
--   usado" como `usuarios`.`perfil` o `dispositivos`.`adopcion`, que por eso
--   llevaron SET NULL. Un boton que perdiera en silencio su dispositivo o su
--   canal quedaria inutilizable sin que nadie se entere; es preferible que el
--   borrado del padre se bloquee.
--
--   NO se forma ningun ciclo: se verifico que ni `controles`, ni `canales`,
--   ni `iconos`, ni `paneles` tienen columna que apunte de vuelta a `botones`.
--   Es la primera tanda desde `dispositivos` sin ciclo que desarmar.
--
--
-- LIMPIEZA PREVIA -- censo (prod 124 botones / dev 120):
--
--   dominio     : 0 ceros, 0 huerfanos   -> LIMPIA, no se toca nada
--   panel       : 0 ceros, 0 huerfanos   -> LIMPIA, no se toca nada
--   dispositivo : 0 ceros, 0 huerfanos   -> LIMPIA, no se toca nada
--   canal       : 0 ceros, 0 huerfanos   -> LIMPIA, no se toca nada
--   control     : 0 ceros, 5 HUERFANOS
--   icono       : 9 ceros, 0 huerfanos
--
--   Los 5 huerfanos de `control` apuntan a controles borrados -- ids 318, 327,
--   337 (dos botones distintos) y 370, todos dentro del rango vigente de
--   `controles` (285-380), o sea que existieron y se eliminaron:
--     boton 165 -> control 318      boton 224 -> control 370
--     boton 174 -> control 327      boton 225 -> control 337
--     boton 185 -> control 337
--
--   Son botones que quedaron apuntando al vacio: hoy ya no hacen nada util,
--   porque el control que accionaban no existe. Se les NULea el puntero, que
--   es lo unico posible sin inventar datos, pero conviene revisarlos aparte --
--   probablemente correspondan botones a dar de baja, no solo a desvincular.
--
--   Mismo patron `NOT EXISTS` de las migraciones anteriores: el 0 no existe en
--   el padre, con lo que ceros y huerfanos caen en la misma condicion.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Neutralizar referencias invalidas.
-- ---------------------------------------------------------------------------

UPDATE `botones` b SET b.`dominio` = NULL
 WHERE b.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = b.`dominio`);

UPDATE `botones` b SET b.`panel` = NULL
 WHERE b.`panel` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `paneles` x WHERE x.`id` = b.`panel`);

UPDATE `botones` b SET b.`control` = NULL
 WHERE b.`control` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `controles` x WHERE x.`id` = b.`control`);

UPDATE `botones` b SET b.`dispositivo` = NULL
 WHERE b.`dispositivo` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dispositivos` x WHERE x.`id` = b.`dispositivo`);

UPDATE `botones` b SET b.`canal` = NULL
 WHERE b.`canal` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `canales` x WHERE x.`id` = b.`canal`);

UPDATE `botones` b SET b.`icono` = NULL
 WHERE b.`icono` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `iconos` x WHERE x.`id` = b.`icono`);


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

CALL _reactor_fk('botones', 'fk_botones_dominio',     'dominio',     'dominios',     'RESTRICT');
CALL _reactor_fk('botones', 'fk_botones_panel',       'panel',       'paneles',      'RESTRICT');
CALL _reactor_fk('botones', 'fk_botones_control',     'control',     'controles',    'RESTRICT');
CALL _reactor_fk('botones', 'fk_botones_dispositivo', 'dispositivo', 'dispositivos', 'RESTRICT');
CALL _reactor_fk('botones', 'fk_botones_canal',       'canal',       'canales',      'RESTRICT');
CALL _reactor_fk('botones', 'fk_botones_icono',       'icono',       'iconos',       'RESTRICT');

DROP PROCEDURE _reactor_fk;
