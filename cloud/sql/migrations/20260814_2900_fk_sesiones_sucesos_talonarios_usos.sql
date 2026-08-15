-- Claves foraneas de `sesiones`, `sucesos`, `talonarios` y `usos`.
--
--   sesiones.terminal  -> terminales.id    ON DELETE SET NULL
--   sucesos.dominio    -> dominios.id      ON DELETE SET NULL
--   sucesos.usuario    -> usuarios.id      ON DELETE SET NULL
--   talonarios.empresa -> empresas.id      ON DELETE RESTRICT
--   usos.dominio       -> dominios.id      ON DELETE RESTRICT
--   usos.dispositivo   -> dispositivos.id  ON DELETE RESTRICT
--
-- Continua 20260814_2800_fk_paneles_planes.sql. Idempotente y portable.
--
--
-- QUEDAN AFUERA `sesiones`.`usuario` Y `sesiones`.`perfil` -- ROMPERIAN EL LOGIN
--
--   Se pidieron tambien, pero NO se declaran. Mismo problema que las FK de
--   `senales` (ver 20260814_2800): el productor escribe CEROS como centinela,
--   y aca es todavia mas grave porque `sesiones` se escribe en cada ingreso.
--
--   Medido en produccion sobre las ultimas 2000 sesiones:
--     * 1997 tienen `usuario` = 0   -> 99,85%
--     * 1997 tienen `perfil`  = 0   -> 99,85%
--     * `usuarios`.`id` = 0 y `perfiles`.`id` = 0 NO existen.
--
--   Con la FK declarada, practicamente TODO INSERT de sesion fallaria con
--   error 1452. No es degradacion: es el login caido. Convertir los 479.000
--   ceros historicos a NULL no cambia nada, porque el productor sigue
--   escribiendo 0 en cada ingreso.
--
--   Para declararlas hay que corregir primero quien escribe `sesiones` -- codigo
--   legacy, fuera de este repositorio -- para que ponga NULL en vez de 0.
--
--   `sesiones`.`terminal` SI se declara: de esas mismas 2000 sesiones, NINGUNA
--   trajo `terminal` = 0. Los 13 ceros de la tabla entera son historicos.
--
--
-- LIMPIEZA PREVIA -- censo (prod / dev):
--
--   sesiones.terminal  : 492072 / 487858 filas, 13 ceros, 0 huerfanos
--   sucesos.dominio    :  49399 / 209165 filas, 0 ceros, 0 huerfanos -> LIMPIA
--   sucesos.usuario    :  49399 / 209165 filas, 0 ceros, 0 huerfanos -> LIMPIA
--   talonarios.empresa :     18 /     18 filas, 0 ceros, 0 huerfanos -> LIMPIA
--   usos.dominio       :  44524 /  34508 filas, 0 ceros, 0 huerfanos -> LIMPIA
--   usos.dispositivo   :  44524 /  34508 filas, 0 ceros, 79 HUERFANOS
--
--   Los 79 huerfanos de `usos`.`dispositivo` NO son centinelas ni indican mala
--   escritura: son usos de equipos que despues se eliminaron. La columna no
--   tiene un solo 0, o sea que el productor siempre escribio ids reales. Es
--   justamente el caso que la FK evita de aca en mas.
--
--
-- POLITICAS
--
--   `sucesos` es bitacora (log de actividad del panel), igual que `registros`
--     y `notificaciones` -> SET NULL en sus dos columnas. El historial no debe
--     impedir dar de baja un dominio o un usuario.
--
--   `sesiones`.`terminal` -> SET NULL por el mismo motivo: la sesion es un
--     registro historico de ingreso, no debe bloquear la baja de una terminal.
--
--   `talonarios`.`empresa` y las dos de `usos` -> RESTRICT, por estructurales.
--     Un uso pertenece a un dominio y a un equipo; un talonario, a una empresa.
--
--   Sin ciclos: ni `terminales`, ni `empresas`, ni `dominios`, ni `usuarios`,
--   ni `dispositivos` apuntan de vuelta a estas tablas.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Neutralizar referencias invalidas.
-- ---------------------------------------------------------------------------

UPDATE `sesiones` s SET s.`terminal` = NULL
 WHERE s.`terminal` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `terminales` x WHERE x.`id` = s.`terminal`);

UPDATE `sucesos` c SET c.`dominio` = NULL
 WHERE c.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = c.`dominio`);

UPDATE `sucesos` c SET c.`usuario` = NULL
 WHERE c.`usuario` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `usuarios` x WHERE x.`id` = c.`usuario`);

UPDATE `talonarios` t SET t.`empresa` = NULL
 WHERE t.`empresa` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `empresas` x WHERE x.`id` = t.`empresa`);

UPDATE `usos` u SET u.`dominio` = NULL
 WHERE u.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = u.`dominio`);

UPDATE `usos` u SET u.`dispositivo` = NULL
 WHERE u.`dispositivo` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dispositivos` x WHERE x.`id` = u.`dispositivo`);


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

CALL _reactor_fk('sesiones',   'fk_sesiones_terminal',  'terminal',    'terminales',   'SET NULL');
CALL _reactor_fk('sucesos',    'fk_sucesos_dominio',    'dominio',     'dominios',     'SET NULL');
CALL _reactor_fk('sucesos',    'fk_sucesos_usuario',    'usuario',     'usuarios',     'SET NULL');
CALL _reactor_fk('talonarios', 'fk_talonarios_empresa', 'empresa',     'empresas',     'RESTRICT');
CALL _reactor_fk('usos',       'fk_usos_dominio',       'dominio',     'dominios',     'RESTRICT');
CALL _reactor_fk('usos',       'fk_usos_dispositivo',   'dispositivo', 'dispositivos', 'RESTRICT');

DROP PROCEDURE _reactor_fk;
