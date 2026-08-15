-- Claves foraneas de `dispositivosparametros`, `dominiosguardias`,
-- `dominiosmedios`, `empaques`, `entradas` y `envases`.
--
--   dispositivosparametros.dispositivo -> dispositivos.id       ON DELETE CASCADE
--   dominiosguardias.dominio           -> dominios.id           ON DELETE CASCADE
--   dominiosmedios.dominio             -> dominios.id           ON DELETE CASCADE
--   empaques.modelo                    -> modelos.id            ON DELETE RESTRICT
--   envases.modelo                     -> modelos.id            ON DELETE RESTRICT
--   entradas.categoria                 -> entradascategorias.id ON DELETE RESTRICT
--
-- Continua 20260814_2300_fk_dashboards.sql. Idempotente y portable dev/prod.
--
--
-- TRES CAMBIOS DE TIPO (precondicion, no cosmetica)
--
--   `empaques`.`modelo` y `envases`.`modelo` son `smallint`, y `modelos`.`id`
--   es `int`. Una FK exige tipos identicos, asi que hay que ampliarlas.
--   Ampliar smallint -> int no pierde nada (todo smallint entra en int).
--
--   `entradas`.`categoria` es `varchar(50)` contra `entradascategorias`.`id`
--   int, mismo caso que `articulos`.`categoria` (migracion 1600) y
--   `contratos`.`promo` (migracion 2200). Sus 106 valores son todos numericos
--   o vacios, ninguno con texto, asi que la conversion es segura. Se NULean
--   los centinelas ANTES de tocar el tipo: al reves, '' caeria en 0.
--
--
-- CASCADE EN LAS TRES TABLAS DE DETALLE
--
--   `dispositivosparametros`, `dominiosguardias` y `dominiosmedios` son filas
--   de detalle propiedad de su padre -- el nombre mismo lo dice. Un parametro
--   sin dispositivo, o una guardia sin dominio, no significan nada: no son
--   entidades independientes. Mismo criterio que `comprobantesrenglones`.
--   `comprobante` (migracion 2000) y `carritos`.`usuario` (2100).
--
--   No abre ningun riesgo nuevo: borrar un dominio ya esta bloqueado por los
--   RESTRICT de `perfiles`, `usuarios`, `dispositivos`, `contratos`, etc., y
--   borrar un dispositivo por los de `canales`, `botones` y `adopciones`. El
--   CASCADE solo evita que queden filas de detalle hu-rfanas el dia que esos
--   padres si se puedan borrar.
--
--
-- LIMPIEZA PREVIA -- censo (prod / dev):
--
--   dispositivosparametros.dispositivo : 10711 / 10501 filas, 0 ceros,
--                                        0 huerfanos  -> LIMPIA
--   dominiosguardias.dominio           : 1 fila, ya en NULL -> LIMPIA
--   dominiosmedios.dominio             : 1 fila, 0 ceros, 0 huerfanos -> LIMPIA
--   empaques.modelo                    : 1 fila, 1 HUERFANO
--   envases.modelo                     : 1 fila, 1 HUERFANO
--   entradas.categoria                 : 106 / 104 filas, 11 centinelas,
--                                        21 HUERFANOS
--
--   OJO CON LOS HUERFANOS DE `entradas`.`categoria` -- son 21 de 106 (20%) y NO
--   son centinelas: son asignaciones reales de una numeracion de categorias
--   anterior, cuyo catalogo ya no existe. Conviven dos generaciones de valores:
--
--     legacy, sin catalogo:  '5' x6, '10' x5, '1' x4, '7' x3, '6' x2, '8' x1
--     vigentes, validos:     '106' x15, '110' x14, '3' x13, '118' x10,
--                            '4' x8, '2' x8, '111' x4, '9' x1, '115' x1
--
--   Al NULear los 21 se pierde la unica pista de en que categoria estaban esas
--   entradas. No hay alternativa -- el catalogo viejo no existe --, pero si esa
--   clasificacion importa, conviene mapearla A MANO a las categorias vigentes
--   ANTES de correr esta migracion. Una vez aplicada, el dato solo queda en el
--   backup.
--
--   `empaques` y `envases` tienen una sola fila cada una, y en ambos casos es
--   huerfana: modelo 3331 y 222 respectivamente, contra un rango vigente de
--   `modelos` de 101 a 135. Al limpiarlas las dos columnas quedan 100% NULL,
--   igual que paso con `contratos`.`promo`. Las FK no validan nada hoy; su
--   valor es impedir escrituras invalidas de aca en mas.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Neutralizar referencias invalidas en las columnas que ya son int.
-- ---------------------------------------------------------------------------

UPDATE `dispositivosparametros` d SET d.`dispositivo` = NULL
 WHERE d.`dispositivo` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dispositivos` x WHERE x.`id` = d.`dispositivo`);

UPDATE `dominiosguardias` g SET g.`dominio` = NULL
 WHERE g.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = g.`dominio`);

UPDATE `dominiosmedios` m SET m.`dominio` = NULL
 WHERE m.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = m.`dominio`);

UPDATE `empaques` e SET e.`modelo` = NULL
 WHERE e.`modelo` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `modelos` x WHERE x.`id` = e.`modelo`);

UPDATE `envases` v SET v.`modelo` = NULL
 WHERE v.`modelo` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `modelos` x WHERE x.`id` = v.`modelo`);


-- ---------------------------------------------------------------------------
-- 2. entradas.categoria -- centinelas a NULL ANTES de cambiar el tipo.
-- ---------------------------------------------------------------------------

UPDATE `entradas` SET `categoria` = NULL
 WHERE `categoria` IS NOT NULL
   AND (TRIM(`categoria`) = '' OR TRIM(`categoria`) = '0');


-- ---------------------------------------------------------------------------
-- 3. Cambios de tipo, solo donde haga falta.
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

CALL _reactor_a_int('empaques', 'modelo');
CALL _reactor_a_int('envases',  'modelo');
CALL _reactor_a_int('entradas', 'categoria');
DROP PROCEDURE _reactor_a_int;


-- ---------------------------------------------------------------------------
-- 4. Ya como int, neutralizar las categorias de la numeracion vieja.
-- ---------------------------------------------------------------------------

UPDATE `entradas` e SET e.`categoria` = NULL
 WHERE e.`categoria` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `entradascategorias` x WHERE x.`id` = e.`categoria`);


-- ---------------------------------------------------------------------------
-- 5. Alta de las constraints.
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

CALL _reactor_fk('dispositivosparametros', 'fk_dispositivosparametros_dispositivo', 'dispositivo', 'dispositivos',       'CASCADE');
CALL _reactor_fk('dominiosguardias',       'fk_dominiosguardias_dominio',           'dominio',     'dominios',           'CASCADE');
CALL _reactor_fk('dominiosmedios',         'fk_dominiosmedios_dominio',             'dominio',     'dominios',           'CASCADE');
CALL _reactor_fk('empaques',               'fk_empaques_modelo',                    'modelo',      'modelos',            'RESTRICT');
CALL _reactor_fk('envases',                'fk_envases_modelo',                     'modelo',      'modelos',            'RESTRICT');
CALL _reactor_fk('entradas',               'fk_entradas_categoria',                 'categoria',   'entradascategorias', 'RESTRICT');

DROP PROCEDURE _reactor_fk;
