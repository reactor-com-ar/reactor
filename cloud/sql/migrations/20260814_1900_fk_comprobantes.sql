-- Claves foraneas de `comprobantes`.
--
--   comprobantes.talonario -> talonarios.id  ON DELETE RESTRICT
--   comprobantes.contrato  -> contratos.id   ON DELETE RESTRICT
--   comprobantes.cliente   -> clientes.id    ON DELETE RESTRICT
--
-- Continua 20260814_1800_fk_canales_carteras_chips_clientes.sql.
-- Idempotente y portable dev/prod.
--
--
-- POR QUE LAS TRES CON RESTRICT
--
--   `comprobantes` son documentos fiscales. Que se borre un cliente, un
--   contrato o un talonario que tiene comprobantes emitidos y que la
--   referencia se vacie en silencio seria lo peor posible: quedarian facturas
--   sin poder rastrear a quien se le emitieron ni bajo que talonario. RESTRICT
--   obliga a resolverlo a mano antes de borrar nada.
--
--   NO se forma ningun ciclo: ni `talonarios`, ni `contratos`, ni `clientes`
--   tienen columna que apunte de vuelta a `comprobantes`.
--
--   Notar que `clientes`.`talonario` ya se declaro en la migracion anterior.
--   Con esta, `talonarios` pasa a ser padre de dos tablas distintas, y
--   `clientes` es a la vez hijo de `talonarios` y padre de `comprobantes`.
--   Es una cadena, no un ciclo: comprobantes -> clientes -> talonarios.
--
--
-- LIMPIEZA PREVIA -- censo (prod 2459 comprobantes / dev 2326):
--
--   talonario : 0 ceros, 2 HUERFANOS
--   contrato  : 195 / 190 ceros, 0 huerfanos
--   cliente   : 108 / 103 ceros, 0 huerfanos
--
--   Los 2 huerfanos de `talonario` son comprobantes de 2020 que apuntan al
--   talonario 23, que esta POR DEBAJO del rango vigente de `talonarios`
--   (35-52), o sea que se elimino hace tiempo:
--     comprobante 5069, emitido 2020-10-28 a "Pablo Brunori
--       (MJ Estructuras y Automatizaciones)", total 3554.21
--     comprobante 5089, emitido 2020-12-03 a "Eliseo Perez Olivera",
--       total 4854.21
--   Se les NULea el puntero al talonario, pero OJO: son comprobantes fiscales
--   reales, con importe y razon social. No se pierde el comprobante ni su
--   contenido, solo el vinculo al talonario que ya no existe. Vale revisarlos
--   aparte si hace falta reconstruir esa trazabilidad.
--
--   Aparte: el comprobante 6361 tiene las tres columnas en NULL (fila vacia,
--   probablemente un borrador). No lo toca esta migracion -- NULL es un valor
--   valido para las tres FK.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Neutralizar referencias invalidas.
-- ---------------------------------------------------------------------------

UPDATE `comprobantes` c SET c.`talonario` = NULL
 WHERE c.`talonario` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `talonarios` x WHERE x.`id` = c.`talonario`);

UPDATE `comprobantes` c SET c.`contrato` = NULL
 WHERE c.`contrato` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `contratos` x WHERE x.`id` = c.`contrato`);

UPDATE `comprobantes` c SET c.`cliente` = NULL
 WHERE c.`cliente` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `clientes` x WHERE x.`id` = c.`cliente`);


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

CALL _reactor_fk('comprobantes', 'fk_comprobantes_talonario', 'talonario', 'talonarios', 'RESTRICT');
CALL _reactor_fk('comprobantes', 'fk_comprobantes_contrato',  'contrato',  'contratos',  'RESTRICT');
CALL _reactor_fk('comprobantes', 'fk_comprobantes_cliente',   'cliente',   'clientes',   'RESTRICT');

DROP PROCEDURE _reactor_fk;
