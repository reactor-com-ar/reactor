-- Claves foraneas de `etiquetas`, `exhibidores`, `gadgets` e `invitaciones`.
--
--   etiquetas.dispositivo -> dispositivos.id  ON DELETE RESTRICT
--   exhibidores.modelo    -> modelos.id       ON DELETE RESTRICT
--   gadgets.dominio       -> dominios.id      ON DELETE RESTRICT
--   gadgets.dashboard     -> dashboards.id    ON DELETE CASCADE
--   invitaciones.dominio  -> dominios.id      ON DELETE RESTRICT
--   invitaciones.emisor   -> usuarios.id      ON DELETE SET NULL
--
-- Continua 20260814_2400_fk_parametros_guardias_empaques_entradas.sql.
-- Idempotente y portable dev/prod.
--
--
-- CENSO COMPLETAMENTE LIMPIO -- el primero de la serie
--
--   Las seis columnas, en los dos entornos, sin un solo NULL, cero ni
--   huerfano:
--
--     etiquetas.dispositivo : 38 filas
--     exhibidores.modelo    :  2 filas
--     gadgets.dominio       : 10 filas
--     gadgets.dashboard     : 10 filas
--     invitaciones.dominio  : 33 filas
--     invitaciones.emisor   : 33 filas
--
--   No se descarta ni se modifica NINGUN dato. Los UPDATE quedan escritos solo
--   como defensa ante drift entre el censo y la aplicacion.
--
--
-- UN CAMBIO DE TIPO
--
--   `exhibidores`.`modelo` es `smallint` y `modelos`.`id` es `int`; la FK
--   exige tipos identicos. Ampliar smallint -> int no pierde nada. Mismo caso
--   que `empaques` y `envases` en la migracion anterior.
--
--
-- POLITICAS
--
--   `gadgets`.`dashboard` -> CASCADE. Un gadget es un componente del
--     dashboard, no una entidad independiente: si se borra el dashboard, sus
--     gadgets no tienen donde vivir. Mismo criterio que
--     `comprobantesrenglones`.`comprobante` y `dispositivosparametros`.
--
--   `invitaciones`.`emisor` -> SET NULL. Atribucion historica (quien mando la
--     invitacion), igual que `usuarios`.`registrante`, `adopciones`.
--     `adoptador`, `registros`.`usuario` y `casos`.`autor`. No conviene que un
--     usuario quede indeleteable por haber invitado a alguien hace anios.
--
--   Las otras cuatro -> RESTRICT, por estructurales o de catalogo.
--     `etiquetas`.`dispositivo` va RESTRICT y no CASCADE a proposito: a
--     diferencia de `dispositivosparametros`, el nombre no la marca como tabla
--     de detalle de dispositivos, asi que se toma la opcion conservadora.
--     Pasar despues a CASCADE es trivial; al reves implicaria haber borrado
--     filas de mas.
--
--   NO se forma ningun ciclo: ni `dashboards`, ni `modelos`, ni `dominios`,
--   ni `dispositivos`, ni `usuarios` apuntan de vuelta a estas tablas.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Neutralizar referencias invalidas (se esperan 0 filas en las seis).
-- ---------------------------------------------------------------------------

UPDATE `etiquetas` e SET e.`dispositivo` = NULL
 WHERE e.`dispositivo` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dispositivos` x WHERE x.`id` = e.`dispositivo`);

UPDATE `exhibidores` e SET e.`modelo` = NULL
 WHERE e.`modelo` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `modelos` x WHERE x.`id` = e.`modelo`);

UPDATE `gadgets` g SET g.`dominio` = NULL
 WHERE g.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = g.`dominio`);

UPDATE `gadgets` g SET g.`dashboard` = NULL
 WHERE g.`dashboard` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dashboards` x WHERE x.`id` = g.`dashboard`);

UPDATE `invitaciones` i SET i.`dominio` = NULL
 WHERE i.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = i.`dominio`);

UPDATE `invitaciones` i SET i.`emisor` = NULL
 WHERE i.`emisor` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `usuarios` x WHERE x.`id` = i.`emisor`);


-- ---------------------------------------------------------------------------
-- 2. exhibidores.modelo -- smallint -> int, solo si hace falta.
-- ---------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS _reactor_a_int;
CREATE PROCEDURE _reactor_a_int(IN p_tabla VARCHAR(64), IN p_columna VARCHAR(64))
BEGIN
    IF (SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = p_tabla
           AND COLUMN_NAME  = p_columna) <> 'int' THEN
        SET @s = CONCAT('ALTER TABLE `', p_tabla, '` MODIFY COLUMN `', p_columna,
                        '` int NULL DEFAULT NULL');
        PREPARE st FROM @s;
        EXECUTE st;
        DEALLOCATE PREPARE st;
    END IF;
END;

CALL _reactor_a_int('exhibidores', 'modelo');
DROP PROCEDURE _reactor_a_int;


-- ---------------------------------------------------------------------------
-- 3. Alta de las constraints.
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

CALL _reactor_fk('etiquetas',    'fk_etiquetas_dispositivo', 'dispositivo', 'dispositivos', 'RESTRICT');
CALL _reactor_fk('exhibidores',  'fk_exhibidores_modelo',    'modelo',      'modelos',      'RESTRICT');
CALL _reactor_fk('gadgets',      'fk_gadgets_dominio',       'dominio',     'dominios',     'RESTRICT');
CALL _reactor_fk('gadgets',      'fk_gadgets_dashboard',     'dashboard',   'dashboards',   'CASCADE');
CALL _reactor_fk('invitaciones', 'fk_invitaciones_dominio',  'dominio',     'dominios',     'RESTRICT');
CALL _reactor_fk('invitaciones', 'fk_invitaciones_emisor',   'emisor',      'usuarios',     'SET NULL');

DROP PROCEDURE _reactor_fk;
