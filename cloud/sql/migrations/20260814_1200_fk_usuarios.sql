-- Claves foraneas de `usuarios`.
--
--   usuarios.perfil      -> perfiles.id     ON DELETE SET NULL
--   usuarios.dominio     -> dominios.id     ON DELETE RESTRICT
--   usuarios.panel       -> paneles.id      ON DELETE SET NULL
--   usuarios.registrante -> usuarios.id     ON DELETE SET NULL   (auto-referencia)
--
-- Continua 20260814_1100_fk_perfiles.sql. Idempotente y portable dev/prod.
--
--
-- POR QUE NO TODAS RESTRICT (a diferencia de las FK de `perfiles`)
--
--   `usuarios.perfil` cierra un CICLO con `perfiles.usuario`, declarada en la
--   migracion anterior: cada tabla referencia a la otra. En prod hay 1.019
--   pares que se apuntan mutuamente (y 0 inconsistentes -- todo `usuarios.perfil`
--   apunta a un perfil que devuelve la referencia).
--
--   Con RESTRICT en las dos puntas ese ciclo se traba: no se puede borrar el
--   usuario porque el perfil lo referencia, ni el perfil porque el usuario lo
--   referencia. Habria que NULear una punta a mano antes de cada borrado.
--   Ni MySQL ni MariaDB tienen constraints diferidas para resolverlo.
--
--   La salida es reconocer que `perfil` y `panel` NO son relaciones
--   estructurales sino punteros de conveniencia al "ultimo usado" -- el
--   comentario de `perfiles`.`panel` en el esquema lo dice literalmente:
--   "id del ultimo panel". Si el destino se borra, el valor correcto es NULL,
--   no un bloqueo. Con `SET NULL` el ciclo se desarma y el borrado queda en un
--   orden natural: se borra el perfil (lo que autoNULea `usuarios`.`perfil`) y
--   despues el usuario. La proteccion de fondo la sigue dando
--   `perfiles`.`usuario` RESTRICT, que impide perder un usuario que todavia
--   tiene perfiles vivos.
--
--   `usuarios.registrante` es auto-referencia (quien dio de alta a quien): un
--   rastro historico, no una dependencia. 27 usuarios en prod son registrante
--   de algun otro. Con RESTRICT no se los podria borrar nunca; con SET NULL se
--   pierde solo la atribucion. Sin ciclo: una tabla consigo misma.
--
--   `usuarios.dominio` SI es estructural -> RESTRICT, como en `perfiles`.
--
--
-- LIMPIEZA PREVIA -- censo (prod / dev):
--
--   perfil      : 1156 / 1171 ceros centinela + 2 / 2 huerfanos REALES
--   dominio     :  269 /  176 ceros, 0 huerfanos
--   panel       : 2092 / 1997 ceros, 0 huerfanos
--   registrante : 1940 / 1938 ceros, 0 huerfanos, 0 auto-referencias
--
--   Los 2 huerfanos de `perfil` son usuarios ACTIVOS con un puntero colgado:
--   id 824 "Administracion Reactor" -> perfil 1919 (no existe) e id 2398
--   "Fernando Luna" -> perfil 2563 (no existe). El puntero ya estaba roto; se
--   NULea, que es lo unico que se puede hacer sin inventar datos.
--
--   Mismo patron `NOT EXISTS` que la migracion anterior: el 0 no existe en el
--   padre, con lo que ceros y huerfanos caen en la misma condicion.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Neutralizar referencias invalidas.
-- ---------------------------------------------------------------------------

UPDATE `usuarios` u SET u.`perfil` = NULL
 WHERE u.`perfil` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `perfiles` x WHERE x.`id` = u.`perfil`);

UPDATE `usuarios` u SET u.`dominio` = NULL
 WHERE u.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = u.`dominio`);

UPDATE `usuarios` u SET u.`panel` = NULL
 WHERE u.`panel` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `paneles` x WHERE x.`id` = u.`panel`);

-- `registrante` apunta a la propia tabla. MySQL/MariaDB rechazan un UPDATE con
-- subconsulta sobre la tabla que se esta modificando (error 1093), asi que va
-- por LEFT JOIN, que es la forma admitida para el caso auto-referencial.
UPDATE `usuarios` u
  LEFT JOIN `usuarios` x ON x.`id` = u.`registrante`
   SET u.`registrante` = NULL
 WHERE u.`registrante` IS NOT NULL
   AND x.`id` IS NULL;


-- ---------------------------------------------------------------------------
-- 2. Alta de las constraints, solo si no existen ya.
-- ---------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS _reactor_agregar_fk;
CREATE PROCEDURE _reactor_agregar_fk(
    IN p_tabla   VARCHAR(64),
    IN p_nombre  VARCHAR(64),
    IN p_columna VARCHAR(64),
    IN p_padre   VARCHAR(64),
    IN p_on_del  VARCHAR(16)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME        = p_tabla
           AND CONSTRAINT_NAME   = p_nombre
           AND CONSTRAINT_TYPE   = 'FOREIGN KEY'
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', p_tabla, '` ADD CONSTRAINT `', p_nombre,
                        '` FOREIGN KEY (`', p_columna, '`) REFERENCES `', p_padre,
                        '` (`id`) ON DELETE ', p_on_del, ' ON UPDATE RESTRICT');
        PREPARE st FROM @s;
        EXECUTE st;
        DEALLOCATE PREPARE st;
    END IF;
END;

CALL _reactor_agregar_fk('usuarios', 'fk_usuarios_perfil',      'perfil',      'perfiles', 'SET NULL');
CALL _reactor_agregar_fk('usuarios', 'fk_usuarios_dominio',     'dominio',     'dominios', 'RESTRICT');
CALL _reactor_agregar_fk('usuarios', 'fk_usuarios_panel',       'panel',       'paneles',  'SET NULL');
CALL _reactor_agregar_fk('usuarios', 'fk_usuarios_registrante', 'registrante', 'usuarios', 'SET NULL');

DROP PROCEDURE _reactor_agregar_fk;
