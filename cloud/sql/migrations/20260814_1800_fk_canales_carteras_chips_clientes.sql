-- Claves foraneas de `canales`, `carteras`, `chips` y `clientes`.
--
--   canales.dispositivo -> dispositivos.id  ON DELETE RESTRICT
--   canales.modulo      -> modulos.id       ON DELETE RESTRICT
--   carteras.ejecutivo  -> usuarios.id      ON DELETE RESTRICT
--   chips.dominio       -> dominios.id      ON DELETE RESTRICT
--   chips.articulo      -> articulos.id     ON DELETE RESTRICT
--   clientes.talonario  -> talonarios.id    ON DELETE RESTRICT
--
-- Continua 20260814_1700_fk_botones.sql. Idempotente y portable dev/prod.
--
-- La tabla es `articulos`, sin tilde.
--
--
-- POR QUE LAS SEIS CON RESTRICT
--
--   Todas son relaciones estructurales o de catalogo: de que equipo es el
--   canal y que modulo lo implementa, que chip pertenece a que dominio y a que
--   articulo corresponde, con que talonario factura el cliente. Ninguna es un
--   puntero al "ultimo usado".
--
--   `carteras`.`ejecutivo` merece una aclaracion: es una ASIGNACION vigente
--   (quien atiende esa cartera), no una atribucion historica como
--   `usuarios`.`registrante` o `adopciones`.`adoptador`, que llevaron SET NULL.
--   Que una cartera quede sin ejecutivo en silencio al borrar al usuario es
--   justamente lo que no conviene: mejor bloquear y forzar la reasignacion.
--   Mismo criterio que `dominios`.`agente`.
--
--   NO se forma ningun ciclo: se verifico que `talonarios` no apunta a
--   `clientes`, `modulos` no apunta a `canales`, `articulos` no apunta a
--   `chips` y `dispositivos` no apunta a `canales`.
--
--
-- LIMPIEZA PREVIA -- censo (prod / dev):
--
--   canales.dispositivo : 523 / 519 filas, 0 ceros, 0 huerfanos  -> LIMPIA
--   canales.modulo      : 523 / 519 filas, 0 ceros, 0 huerfanos  -> LIMPIA
--   chips.dominio       :  20 /  20 filas, 0 ceros, 0 huerfanos  -> LIMPIA
--   chips.articulo      :  20 /  20 filas, 0 ceros, 0 huerfanos  -> LIMPIA
--   clientes.talonario  :  67 /  64 filas, 0 ceros, 0 huerfanos  -> LIMPIA
--   carteras.ejecutivo  :   8 /   8 filas, 0 ceros, 2 HUERFANOS
--
--   Cinco de las seis columnas estan perfectas: esas FK se declaran sin
--   modificar un solo dato. Los UPDATE quedan escritos igual como defensa ante
--   drift, pero se espera que afecten 0 filas.
--
--   Los 2 huerfanos son un cuarto de `carteras`, que solo tiene 8 filas:
--     cartera 12 -> usuario 1013 (no existe)
--     cartera 17 -> usuario 1011 (no existe)
--   Los dos ids caen dentro del rango vigente de `usuarios` (1-2723), o sea
--   que esos ejecutivos existieron y fueron eliminados sin reasignar su
--   cartera. Se les NULea el puntero, pero conviene revisarlas aparte: son
--   carteras activas que quedaron sin nadie a cargo.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Neutralizar referencias invalidas.
-- ---------------------------------------------------------------------------

UPDATE `canales` c SET c.`dispositivo` = NULL
 WHERE c.`dispositivo` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dispositivos` x WHERE x.`id` = c.`dispositivo`);

UPDATE `canales` c SET c.`modulo` = NULL
 WHERE c.`modulo` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `modulos` x WHERE x.`id` = c.`modulo`);

UPDATE `carteras` c SET c.`ejecutivo` = NULL
 WHERE c.`ejecutivo` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `usuarios` x WHERE x.`id` = c.`ejecutivo`);

UPDATE `chips` c SET c.`dominio` = NULL
 WHERE c.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = c.`dominio`);

UPDATE `chips` c SET c.`articulo` = NULL
 WHERE c.`articulo` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `articulos` x WHERE x.`id` = c.`articulo`);

UPDATE `clientes` c SET c.`talonario` = NULL
 WHERE c.`talonario` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `talonarios` x WHERE x.`id` = c.`talonario`);


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

CALL _reactor_fk('canales',  'fk_canales_dispositivo', 'dispositivo', 'dispositivos', 'RESTRICT');
CALL _reactor_fk('canales',  'fk_canales_modulo',      'modulo',      'modulos',      'RESTRICT');
CALL _reactor_fk('carteras', 'fk_carteras_ejecutivo',  'ejecutivo',   'usuarios',     'RESTRICT');
CALL _reactor_fk('chips',    'fk_chips_dominio',       'dominio',     'dominios',     'RESTRICT');
CALL _reactor_fk('chips',    'fk_chips_articulo',      'articulo',    'articulos',    'RESTRICT');
CALL _reactor_fk('clientes', 'fk_clientes_talonario',  'talonario',   'talonarios',   'RESTRICT');

DROP PROCEDURE _reactor_fk;
