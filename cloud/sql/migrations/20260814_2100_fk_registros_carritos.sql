-- Claves foraneas de `registros`, `carritos` y `carritositems`.
--
--   registros.usuario      -> usuarios.id   ON DELETE SET NULL
--   registros.dominio      -> dominios.id   ON DELETE SET NULL
--   registros.dispositivo  -> dispositivos.id ON DELETE SET NULL
--   registros.canal        -> canales.id    ON DELETE SET NULL
--   carritos.usuario       -> usuarios.id   ON DELETE CASCADE
--   carritositems.usuario  -> usuarios.id   ON DELETE CASCADE
--   carritositems.articulo -> articulos.id  ON DELETE RESTRICT
--
-- Continua 20260814_2000_fk_renglones_contratos_programas_prospectos.sql.
-- Idempotente y portable dev/prod. La tabla es `articulos`, sin tilde.
--
--
-- POR QUE `registros` VA CON SET NULL EN LAS CUATRO (y no RESTRICT como el resto)
--
--   `registros` es el log de eventos del sistema: 2,6M filas en prod, 2,9M en
--   dev, con anios de historia. Es la primera tabla de esta serie que no es
--   una entidad de negocio sino una bitacora, y eso invierte el criterio.
--
--   Con RESTRICT, cualquier dispositivo, canal o dominio que alguna vez haya
--   generado UN registro quedaria imposible de borrar para siempre: habria que
--   purgar el log antes de dar de baja un equipo. Inaceptable en la practica.
--
--   Con CASCADE, dar de baja un equipo purgaria en silencio miles de filas de
--   historia. Peor todavia.
--
--   SET NULL preserva el log completo y sacrifica solo el vinculo del registro
--   hu-rfano. Ademas `usuario` es atribucion historica -- quien hizo la accion
--   --, el mismo caso que `usuarios`.`registrante` y `adopciones`.`adoptador`.
--
--
-- POR QUE `carritos` / `carritositems` VAN CON CASCADE
--
--   Un carrito y sus items son datos efimeros propiedad del usuario, no
--   entidades independientes: sin el usuario no significan nada. Mismo criterio
--   que `comprobantesrenglones`.`comprobante`. Bloquear la baja de un usuario
--   por un carrito abandonado seria absurdo, y dejarlo hu-rfano tambien.
--   `carritositems`.`articulo` en cambio es referencia de catalogo -> RESTRICT.
--
--   Ambas tablas tienen 1 sola fila hoy, en los dos entornos.
--
--
-- LIMPIEZA PREVIA -- censo (prod / dev):
--
--   registros.usuario     : 1.514.163 / 1.903.276 ceros, 0 huerfanos
--   registros.dominio     : 0 ceros, 0 huerfanos   -> LIMPIA, no se toca nada
--   registros.dispositivo : 0 ceros, 0 huerfanos   -> LIMPIA, no se toca nada
--   registros.canal       : 0 ceros, 0 huerfanos   -> LIMPIA, no se toca nada
--   carritos.usuario      : 1 fila, 1 cero
--   carritositems.usuario : 1 fila, 1 cero
--   carritositems.articulo: 1 fila, 0 ceros, 0 huerfanos -> LIMPIA
--
--   CERO huerfanos reales en las siete columnas: no se descarta ningun valor.
--
--
-- PESO DE ESTA MIGRACION (la mas cara de la serie)
--
--   A diferencia de las anteriores, que corrian en menos de 2 segundos, esta
--   toca una tabla grande:
--     * El UPDATE de `registros`.`usuario` modifica ~1,5M filas en prod (58%
--       de la tabla).
--     * InnoDB debe crear 4 indices sobre 2,6M filas -- `registros` hoy solo
--       tiene la PK. Eso suma espacio en disco ademas de tiempo.
--   Prever varios minutos. No hay riesgo de perdida (0 huerfanos), pero
--   conviene correrla en un momento de baja actividad.
--
--   Efecto colateral bueno: quedan indices sobre `usuario`, `dominio`,
--   `dispositivo` y `canal`, que hoy no existen. Cualquier consulta del log
--   filtrada por esas columnas pasa de full scan de 2,6M filas a lookup.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Neutralizar referencias invalidas.
-- ---------------------------------------------------------------------------

UPDATE `registros` r SET r.`usuario` = NULL
 WHERE r.`usuario` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `usuarios` x WHERE x.`id` = r.`usuario`);

UPDATE `registros` r SET r.`dominio` = NULL
 WHERE r.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = r.`dominio`);

UPDATE `registros` r SET r.`dispositivo` = NULL
 WHERE r.`dispositivo` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dispositivos` x WHERE x.`id` = r.`dispositivo`);

UPDATE `registros` r SET r.`canal` = NULL
 WHERE r.`canal` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `canales` x WHERE x.`id` = r.`canal`);

UPDATE `carritos` c SET c.`usuario` = NULL
 WHERE c.`usuario` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `usuarios` x WHERE x.`id` = c.`usuario`);

UPDATE `carritositems` i SET i.`usuario` = NULL
 WHERE i.`usuario` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `usuarios` x WHERE x.`id` = i.`usuario`);

UPDATE `carritositems` i SET i.`articulo` = NULL
 WHERE i.`articulo` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `articulos` x WHERE x.`id` = i.`articulo`);


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

CALL _reactor_fk('registros',     'fk_registros_usuario',      'usuario',     'usuarios',     'SET NULL');
CALL _reactor_fk('registros',     'fk_registros_dominio',      'dominio',     'dominios',     'SET NULL');
CALL _reactor_fk('registros',     'fk_registros_dispositivo',  'dispositivo', 'dispositivos', 'SET NULL');
CALL _reactor_fk('registros',     'fk_registros_canal',        'canal',       'canales',      'SET NULL');
CALL _reactor_fk('carritos',      'fk_carritos_usuario',       'usuario',     'usuarios',     'CASCADE');
CALL _reactor_fk('carritositems', 'fk_carritositems_usuario',  'usuario',     'usuarios',     'CASCADE');
CALL _reactor_fk('carritositems', 'fk_carritositems_articulo', 'articulo',    'articulos',    'RESTRICT');

DROP PROCEDURE _reactor_fk;
