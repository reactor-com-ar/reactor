-- Primeras claves foraneas reales del esquema: las 4 relaciones de `perfiles`.
--
--   perfiles.usuario -> usuarios.id
--   perfiles.dominio -> dominios.id
--   perfiles.rol     -> roles.id
--   perfiles.panel   -> paneles.id      (la tabla es `paneles`, en plural)
--
-- Requiere que las tablas esten en InnoDB: MyISAM acepta la sintaxis de FK y la
-- ignora en silencio. Lo garantiza 20260814_1000_innodb_utf8mb4.sql.
--
-- Corre igual en desarrollo y produccion, y es idempotente: cada constraint se
-- agrega solo si no existe, asi que reaplicarla no falla.
--
--
-- PASO 1 -- LIMPIEZA DE DATOS (obligatoria: sin esto el ADD CONSTRAINT falla)
--
--   Censo previo (prod / dev):
--     usuario : 1 / 1     huerfano real  -> perfil 1613 "Operador en Familia
--                                           Alvarez" (uuid TXHTVWYB), apunta al
--                                           usuario 1499, que ya no existe. Ya
--                                           estaba deshabilitado.
--     dominio : 0 / 0     limpia
--     rol     : 242 / 144 ceros centinela
--     panel   : 1273 / 1289 ceros centinela
--
--   Los ceros no son huerfanos: significan "sin asignar". Se verifico que
--   NINGUNA tabla padre tiene una fila con id=0, o sea que el 0 nunca fue una
--   referencia valida. Aun asi `ADD CONSTRAINT` los rechaza, porque 0 no es
--   NULL. Se convierten a NULL, que es la representacion relacional correcta
--   de "sin asignar" y la que la FK admite.
--
--   Un unico patron `NOT EXISTS` resuelve los dos casos a la vez -- el 0 no
--   existe en el padre, con lo que cae en la misma condicion que un huerfano.
--   Ademas deja la migracion a prueba de drift: si para cuando se aplique
--   apareciera un huerfano nuevo, se neutraliza en vez de abortar a mitad.
--
-- PASO 2 -- LAS CONSTRAINTS
--
--   `ON DELETE RESTRICT ON UPDATE RESTRICT` en las cuatro (es ademas el default
--   de SQL, se explicita para que quede legible). No se borra un usuario,
--   dominio, rol ni panel mientras tenga perfiles colgando: hay que limpiar a
--   mano primero. Es deliberadamente lo mas conservador -- nada se borra en
--   cascada por sorpresa, y expone los lugares donde la app hoy borra padres
--   sin mirar si algo los referencia.
--
--   InnoDB crea solo el indice que cada FK necesita sobre la columna hija.
--   `perfiles` no tenia ninguno (solo la PK), asi que ademas se gana busqueda
--   indexada por usuario / dominio / rol / panel.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Neutralizar referencias invalidas (ceros centinela + huerfanos).
-- ---------------------------------------------------------------------------

UPDATE `perfiles` p SET p.`usuario` = NULL
 WHERE p.`usuario` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `usuarios` x WHERE x.`id` = p.`usuario`);

UPDATE `perfiles` p SET p.`dominio` = NULL
 WHERE p.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = p.`dominio`);

UPDATE `perfiles` p SET p.`rol` = NULL
 WHERE p.`rol` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `roles` x WHERE x.`id` = p.`rol`);

UPDATE `perfiles` p SET p.`panel` = NULL
 WHERE p.`panel` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `paneles` x WHERE x.`id` = p.`panel`);


-- ---------------------------------------------------------------------------
-- 2. Alta de las constraints, solo si no existen ya.
-- ---------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS _reactor_agregar_fk;
CREATE PROCEDURE _reactor_agregar_fk(
    IN p_nombre VARCHAR(64),
    IN p_columna VARCHAR(64),
    IN p_padre  VARCHAR(64)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME        = 'perfiles'
           AND CONSTRAINT_NAME   = p_nombre
           AND CONSTRAINT_TYPE   = 'FOREIGN KEY'
    ) THEN
        SET @s = CONCAT('ALTER TABLE `perfiles` ADD CONSTRAINT `', p_nombre,
                        '` FOREIGN KEY (`', p_columna, '`) REFERENCES `', p_padre,
                        '` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT');
        PREPARE st FROM @s;
        EXECUTE st;
        DEALLOCATE PREPARE st;
    END IF;
END;

CALL _reactor_agregar_fk('fk_perfiles_usuario', 'usuario', 'usuarios');
CALL _reactor_agregar_fk('fk_perfiles_dominio', 'dominio', 'dominios');
CALL _reactor_agregar_fk('fk_perfiles_rol',     'rol',     'roles');
CALL _reactor_agregar_fk('fk_perfiles_panel',   'panel',   'paneles');

DROP PROCEDURE _reactor_agregar_fk;
