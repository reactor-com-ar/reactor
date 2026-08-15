-- Claves foraneas de `adopciones`, y ajuste de una FK previa para desarmar el
-- segundo ciclo del esquema.
--
--   adopciones.dispositivo -> dispositivos.id  ON DELETE RESTRICT
--   adopciones.dominio     -> dominios.id      ON DELETE RESTRICT
--   adopciones.adoptador   -> usuarios.id      ON DELETE SET NULL
--   adopciones.liberador   -> usuarios.id      ON DELETE SET NULL
--
--   dispositivos.adopcion  -> adopciones.id    RESTRICT --> SET NULL   (ajuste)
--
-- Continua 20260814_1400_fk_dispositivos.sql. Idempotente y portable dev/prod.
--
--
-- EL SEGUNDO CICLO DEL ESQUEMA
--
--   `adopciones`.`dispositivo` cierra un ciclo con `dispositivos`.`adopcion`,
--   declarada en la migracion anterior: cada tabla referencia a la otra. En
--   prod hay 134 pares que se apuntan mutuamente.
--
--   Con RESTRICT en las dos puntas el ciclo se traba y no se puede borrar ni
--   el equipo ni la adopcion. Se resuelve igual que el ciclo
--   usuarios <-> perfiles: cede la punta que es un PUNTERO al registro
--   vigente, y se mantiene RESTRICT en la que es ESTRUCTURAL.
--
--     `dispositivos`.`adopcion` = "la adopcion actual de este equipo".
--         Puntero. Si la adopcion se borra, el valor correcto es NULL.
--         -> SET NULL. (Se declaro RESTRICT en la migracion 1400 y ya se habia
--            senalado como el caso discutible del grupo; ahora hay un motivo
--            concreto para cambiarlo.)
--
--     `adopciones`.`dispositivo` = "de que equipo es esta adopcion".
--         Estructural: una adopcion sin equipo no significa nada.
--         -> RESTRICT.
--
--   `adoptador` y `liberador` son atribucion historica (quien adopto, quien
--   libero), el mismo caso que `usuarios`.`registrante`: un rastro, no una
--   dependencia. Con RESTRICT no se podria borrar nunca a un usuario que
--   adopto algun equipo -- y los 224 registros tienen adoptador valido. Con
--   SET NULL se pierde solo la atribucion. -> SET NULL en ambas.
--
--   Notar que `adoptador` y `liberador` apuntan las dos a `usuarios`: dos FK
--   distintas de la misma tabla hacia el mismo padre, lo cual es correcto y no
--   tiene nada de especial.
--
--
-- LIMPIEZA PREVIA -- censo (identico en prod y dev, 224 adopciones):
--
--   dispositivo : 0 ceros, 3 HUERFANOS
--   dominio     : 0 ceros, 0 huerfanos   -> LIMPIA, no se toca nada
--   adoptador   : 0 ceros, 0 huerfanos   -> LIMPIA, no se toca nada
--   liberador   : 144 ceros, 0 huerfanos  (adopciones aun no liberadas)
--
--   Los 3 huerfanos apuntan a equipos borrados -- ids 150 (dos veces) y 341,
--   dentro del rango vigente de `dispositivos` (104-455), o sea que existieron
--   y se eliminaron:
--     55640 -> disp 150, adoptada 2020-07-05, liberada 2020-07-11, vigente=0
--     55644 -> disp 150, adoptada 2020-07-11, sin liberar, VIGENTE=1
--     55655 -> disp 341, adoptada 2020-08-05, liberada 2024-03-14, vigente=0
--
--   OJO con 55644: esta marcada vigente=1, o sea una adopcion ACTIVA sobre un
--   equipo que ya no existe. Es un dato inconsistente previo a esta migracion.
--   Aca solo se le NULea el puntero, que es lo unico posible sin inventar
--   datos; queda pendiente decidir si esa fila debe darse por liberada.
--
--   Aparte: `liberado` usa '1500-01-01 00:00:00' como centinela de "no
--   liberada", el mismo patron de centinela que el 0 en las columnas de
--   referencia. No se toca en esta migracion.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Neutralizar referencias invalidas.
-- ---------------------------------------------------------------------------

UPDATE `adopciones` a SET a.`dispositivo` = NULL
 WHERE a.`dispositivo` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dispositivos` x WHERE x.`id` = a.`dispositivo`);

UPDATE `adopciones` a SET a.`dominio` = NULL
 WHERE a.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = a.`dominio`);

UPDATE `adopciones` a SET a.`adoptador` = NULL
 WHERE a.`adoptador` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `usuarios` x WHERE x.`id` = a.`adoptador`);

UPDATE `adopciones` a SET a.`liberador` = NULL
 WHERE a.`liberador` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `usuarios` x WHERE x.`id` = a.`liberador`);


-- ---------------------------------------------------------------------------
-- 2. Alta / ajuste de constraints.
--
--    El procedimiento compara la regla ON DELETE vigente contra la deseada:
--      - si la FK no existe            -> la crea
--      - si existe con otra regla      -> la borra y la recrea
--      - si existe con la regla exacta -> no hace nada
--    MySQL/MariaDB no permiten cambiar la regla de una constraint en el lugar,
--    de ahi el DROP + ADD. El indice que InnoDB creo para la FK sobrevive al
--    DROP y se reutiliza, asi que no se reconstruye nada del dato.
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

-- Ajuste de la FK previa: desarma el ciclo.
CALL _reactor_fk('dispositivos', 'fk_dispositivos_adopcion', 'adopcion', 'adopciones', 'SET NULL');

-- Las cuatro nuevas.
CALL _reactor_fk('adopciones', 'fk_adopciones_dispositivo', 'dispositivo', 'dispositivos', 'RESTRICT');
CALL _reactor_fk('adopciones', 'fk_adopciones_dominio',     'dominio',     'dominios',     'RESTRICT');
CALL _reactor_fk('adopciones', 'fk_adopciones_adoptador',   'adoptador',   'usuarios',     'SET NULL');
CALL _reactor_fk('adopciones', 'fk_adopciones_liberador',   'liberador',   'usuarios',     'SET NULL');

DROP PROCEDURE _reactor_fk;
