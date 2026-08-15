-- Fila centinela `id` = 0 en `usuarios`, `perfiles` y `canales`, y las 3 FK
-- que quedaban bloqueadas por ella.
--
--   usuarios.id      = 0   "(sin asignar)"   <- fila nueva
--   perfiles.id      = 0   "(sin asignar)"   <- fila nueva
--   canales.id       = 0   "(sin asignar)"   <- fila nueva
--
--   sesiones.usuario -> usuarios.id  ON DELETE RESTRICT
--   sesiones.perfil  -> perfiles.id  ON DELETE RESTRICT
--   senales.canal    -> canales.id   ON DELETE RESTRICT
--
-- Continua 20260814_3000_fk_senales_parcial.sql. Idempotente y portable.
--
--
-- EL PROBLEMA QUE RESUELVE
--
--   Estas 3 FK no se pudieron declarar en las migraciones anteriores porque el
--   sistema legacy -- que esta fuera de este repositorio -- escribe 0 como
--   centinela de "sin asignar", y no existia fila con id = 0 en el padre.
--   Medido en produccion:
--
--     sesiones.usuario = 0 : 1997 de las ultimas 2000 sesiones  (99,85%)
--     sesiones.perfil  = 0 : 1997 de las ultimas 2000 sesiones  (99,85%)
--     senales.canal    = 0 : 932 de 1367 senales por hora       (68%)
--
--   Declarar la FK sin mas habria hecho fallar practicamente todo INSERT de
--   sesion (login caido) y dos tercios de la ingesta MQTT.
--
--   En vez de tocar el legacy, se hace valido el 0: se crea la fila centinela.
--   El productor sigue escribiendo 0 y la referencia resuelve correctamente.
--
--   Es la estrategia OPUESTA a la del resto del esquema, donde el 0 se
--   convirtio a NULL (ver 20260814_1100 en adelante). Se toma solo aca, y solo
--   porque el productor de estas tres columnas no se puede modificar desde
--   este repo. Si algun dia ese codigo escribe NULL, conviene migrar estas
--   tres a la convencion general y borrar las filas centinela.
--
--
-- POR QUE EL USUARIO CENTINELA NO PUEDE INICIAR SESION
--
--   `cloud/api/login.php` busca `WHERE usuario = :u` y despues exige que
--   `habilitado` este en ('S','1','Y') y que la contrasena coincida.
--   La fila centinela se crea con:
--     * `usuario`    = NULL  -> `WHERE usuario = :u` NUNCA puede matchear,
--                               porque en SQL NULL no es igual a nada.
--     * `habilitado` = 'N'   -> aunque se llegara a encontrar, corta con 403.
--     * `contrasena` = ''    -> login.php rechaza explicitamente el vacio.
--   Tres barreras independientes.
--
--
-- IMPACTO EN LOS LISTADOS -- REQUIERE ATENCION
--
--   Las tres filas centinela van a aparecer en cualquier listado que haga
--   `SELECT * FROM usuarios` / `perfiles` / `canales` sin filtrar. En concreto,
--   `panel/api/usuarios.php` -- que es codigo vivo -- va a mostrar
--   "(sin asignar)" como si fuera un usuario mas. Conviene agregar
--   `WHERE id <> 0` en los listados del panel. Se deja anotado aca porque es
--   consecuencia directa de esta migracion.
--
--
-- NO_AUTO_VALUE_ON_ZERO
--
--   Sin este modo, MySQL interpreta un INSERT con id = 0 en una columna
--   AUTO_INCREMENT como "genera el proximo valor", y la fila terminaria con un
--   id cualquiera en vez de 0. Se activa solo para esta sesion.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_AUTO_VALUE_ON_ZERO');


-- ---------------------------------------------------------------------------
-- 1. Filas centinela. INSERT IGNORE las hace idempotentes: si ya existen, no
--    se tocan. Todas las columnas son nullable o tienen default, asi que solo
--    se completa lo minimo indispensable.
--
--    Orden: `usuarios` primero, porque `perfiles`.`usuario` la referencia.
--    Ambas se crean apuntandose a NULL, no entre si, para no depender del
--    orden ni ensuciar el ciclo usuarios <-> perfiles.
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO `usuarios` (`id`, `nombre`, `usuario`, `contrasena`, `habilitado`)
VALUES (0, '(sin asignar)', NULL, '', 'N');

INSERT IGNORE INTO `perfiles` (`id`, `nombre`, `usuario`, `dominio`, `habilitado`)
VALUES (0, '(sin asignar)', NULL, NULL, '0');

INSERT IGNORE INTO `canales` (`id`, `nombre`, `dispositivo`)
VALUES (0, '(sin asignar)', NULL);


-- ---------------------------------------------------------------------------
-- 2. Neutralizar referencias invalidas que NO sean el 0.
--
--    Ahora el 0 es valido, asi que la condicion excluye ese valor: se NULea
--    solo lo que apunta a filas realmente inexistentes.
--    En prod habia 1203 huerfanos en `sesiones`.`usuario` y 1258 en
--    `sesiones`.`perfil` -- sesiones de usuarios y perfiles ya eliminados.
-- ---------------------------------------------------------------------------

UPDATE `sesiones` s SET s.`usuario` = NULL
 WHERE s.`usuario` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `usuarios` x WHERE x.`id` = s.`usuario`);

UPDATE `sesiones` s SET s.`perfil` = NULL
 WHERE s.`perfil` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `perfiles` x WHERE x.`id` = s.`perfil`);

UPDATE `senales` s SET s.`canal` = NULL
 WHERE s.`canal` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `canales` x WHERE x.`id` = s.`canal`);


-- ---------------------------------------------------------------------------
-- 3. Alta de las constraints.
--
--    RESTRICT en las tres. No se puede usar SET NULL ni CASCADE sobre la fila
--    centinela sin efectos raros, y ademas RESTRICT es la proteccion correcta:
--    la fila id = 0 NO debe poder borrarse, porque hacerlo dejaria al productor
--    legacy escribiendo referencias invalidas otra vez. La constraint la
--    protege sola, ya que hay millones de filas apuntandole.
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

CALL _reactor_fk('sesiones', 'fk_sesiones_usuario', 'usuario', 'usuarios', 'RESTRICT');
CALL _reactor_fk('sesiones', 'fk_sesiones_perfil',  'perfil',  'perfiles', 'RESTRICT');
CALL _reactor_fk('senales',  'fk_senales_canal',    'canal',   'canales',  'RESTRICT');

DROP PROCEDURE _reactor_fk;
