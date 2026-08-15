-- Claves foraneas de `llaves`, `mensajes` y `menus`.
--
--   llaves.dominio    -> dominios.id  ON DELETE RESTRICT
--   llaves.generador  -> usuarios.id  ON DELETE SET NULL
--   mensajes.usuario  -> usuarios.id  ON DELETE SET NULL
--   menus.padre       -> menus.id     ON DELETE RESTRICT   (AUTO-REFERENCIAL)
--
-- Continua 20260814_2500_fk_etiquetas_gadgets_invitaciones.sql.
-- Idempotente y portable dev/prod.
--
--
-- `menus`.`padre` -- LA JERARQUIA DEL MENU EN CASCADA
--
--   Segunda FK auto-referencial del esquema, despues de `usuarios`.
--   `registrante`. Una tabla que se apunta a si misma no forma un ciclo entre
--   tablas: es el patron estandar para representar un arbol.
--
--   Estado de los datos: 123 filas, 2 niveles, 0 huerfanos, ninguna fila que
--   sea su propio padre. Una sola raiz, hoy marcada con `padre` = 0. Ese 0 se
--   convierte a NULL, que es la unica forma valida de decir "no tengo padre"
--   con una FK -- el 0 fallaria porque no existe `menus`.`id` = 0.
--
--   ON DELETE RESTRICT, no CASCADE. Se verifico que el CASCADE recursivo SI
--   funciona en InnoDB (borrar la raiz de un arbol de 4 niveles elimino las 5
--   filas), justamente por eso se descarta: en un menu de 123 entradas, borrar
--   una opcion de primer nivel se llevaria en silencio todo su submenu.
--   RESTRICT obliga a vaciar la rama a mano y hace explicita la consecuencia.
--   Pasar despues a CASCADE es trivial; al reves implica haber borrado de mas.
--
--   SET NULL seria la peor opcion de las tres: los submenus huerfanos se
--   convertirian en items de primer nivel y apareceria basura en la raiz del
--   menu sin que nadie lo pida.
--
--
--   LO QUE ESTA FK NO GARANTIZA -- IMPORTANTE
--
--   Una FK auto-referencial solo garantiza que el padre EXISTE. No garantiza
--   que la jerarquia sea un arbol. Se comprobo empiricamente que InnoDB acepta
--   sin chistar:
--
--     * Ciclos:  A -> C -> B -> A. Un bucle cerrado, sin raiz. Cada fila tiene
--                un padre valido, asi que la constraint esta conforme.
--     * Auto-referencia: una fila con `padre` = su propio `id`.
--
--   Cualquiera de las dos cosas cuelga un recorrido recursivo del menu. Si eso
--   preocupa, la defensa NO es la FK sino:
--     - un CHECK `padre <> id`, que cubre solo el caso trivial, o
--     - validacion en la aplicacion al reasignar el padre, recorriendo hacia
--       arriba para verificar que el nuevo padre no sea descendiente propio, o
--     - un CTE recursivo (WITH RECURSIVE) que detecte ciclos, corrido como
--       chequeo periodico.
--   Hoy no hay ningun ciclo en los datos; esto es para que no se introduzca
--   uno creyendo que la FK lo impide.
--
--
-- LIMPIEZA PREVIA -- censo (identico en prod y dev):
--
--   llaves.dominio   : 385 filas, 0 ceros, 0 huerfanos  -> LIMPIA
--   llaves.generador : 385 filas, 0 ceros, 2 HUERFANOS
--   mensajes.usuario : 177 filas, 132 ceros, 0 huerfanos
--   menus.padre      : 123 filas, 1 cero (la raiz), 0 huerfanos
--
--   `generador` y `usuario` van con SET NULL por ser atribucion historica
--   (quien genero la llave, de quien es el mensaje), igual que
--   `usuarios`.`registrante`, `casos`.`autor` e `invitaciones`.`emisor`. Los 2
--   huerfanos de `generador` apuntan a usuarios ya eliminados.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Neutralizar referencias invalidas.
-- ---------------------------------------------------------------------------

UPDATE `llaves` l SET l.`dominio` = NULL
 WHERE l.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = l.`dominio`);

UPDATE `llaves` l SET l.`generador` = NULL
 WHERE l.`generador` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `usuarios` x WHERE x.`id` = l.`generador`);

UPDATE `mensajes` m SET m.`usuario` = NULL
 WHERE m.`usuario` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `usuarios` x WHERE x.`id` = m.`usuario`);

-- `menus`.`padre` apunta a la propia tabla. MySQL/MariaDB rechazan un UPDATE
-- con subconsulta sobre la tabla que se modifica (error 1093), asi que va por
-- LEFT JOIN, igual que se hizo con `usuarios`.`registrante`.
UPDATE `menus` m
  LEFT JOIN `menus` x ON x.`id` = m.`padre`
   SET m.`padre` = NULL
 WHERE m.`padre` IS NOT NULL
   AND x.`id` IS NULL;


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

CALL _reactor_fk('llaves',   'fk_llaves_dominio',   'dominio',   'dominios', 'RESTRICT');
CALL _reactor_fk('llaves',   'fk_llaves_generador', 'generador', 'usuarios', 'SET NULL');
CALL _reactor_fk('mensajes', 'fk_mensajes_usuario', 'usuario',   'usuarios', 'SET NULL');
CALL _reactor_fk('menus',    'fk_menus_padre',      'padre',     'menus',    'RESTRICT');

DROP PROCEDURE _reactor_fk;
