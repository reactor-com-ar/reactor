-- Claves foraneas de `notificaciones` y `pagos`.
--
--   notificaciones.dominio -> dominios.id     ON DELETE SET NULL
--   pagos.dominio          -> dominios.id     ON DELETE RESTRICT
--   pagos.contrato         -> contratos.id    ON DELETE RESTRICT
--   pagos.comprobante      -> comprobantes.id ON DELETE RESTRICT
--   pagos.medio            -> medios.id       ON DELETE RESTRICT
--
-- Continua 20260814_2600_fk_llaves_mensajes_menus.sql.
-- Idempotente y portable dev/prod.
--
--
-- CENSO COMPLETAMENTE LIMPIO -- el segundo de la serie
--
--   Las cinco columnas, en los dos entornos, sin un solo NULL, cero ni
--   huerfano:
--
--     notificaciones.dominio : 72995 / 68717 filas
--     pagos.dominio          :   472 /   458 filas
--     pagos.contrato         :   472 /   458 filas
--     pagos.comprobante      :   472 /   458 filas
--     pagos.medio            :   472 /   458 filas
--
--   No se descarta ni se modifica NINGUN dato. Los UPDATE quedan escritos solo
--   como defensa ante drift entre el censo y la aplicacion.
--
--
-- POR QUE `notificaciones` VA CON SET NULL Y `pagos` CON RESTRICT
--
--   `notificaciones` es una bitacora: 73.000 avisos ya enviados. Mismo caso
--   que `registros` (migracion 2100). Con RESTRICT, cualquier dominio que
--   alguna vez haya generado una notificacion quedaria imposible de borrar,
--   y no tiene sentido que el historial de avisos gobierne el ciclo de vida
--   del dominio. SET NULL conserva la notificacion y sacrifica solo el
--   vinculo.
--
--   `pagos` es lo contrario: registros financieros, no bitacora. Que se borre
--   un contrato, un comprobante, un medio de pago o un dominio que tiene pagos
--   asociados, y que la referencia se vacie en silencio, dejaria plata
--   registrada sin poder imputarla a nada. RESTRICT en las cuatro, igual que
--   `comprobantes` en la migracion 1900.
--
--   Notar como quedan encadenados los tres niveles del circuito de facturacion:
--     comprobantesrenglones -> comprobantes   CASCADE  (el detalle sigue a su cabecera)
--     pagos                 -> comprobantes   RESTRICT (no se borra una factura pagada)
--   Es coherente: borrar un comprobante se lleva sus renglones, pero queda
--   bloqueado si alguien ya le imputo un pago.
--
--   NO se forma ningun ciclo: ni `comprobantes`, ni `contratos`, ni `dominios`,
--   ni `medios` tienen columna que apunte de vuelta a `pagos`.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Neutralizar referencias invalidas (se esperan 0 filas en las cinco).
-- ---------------------------------------------------------------------------

UPDATE `notificaciones` t SET t.`dominio` = NULL
 WHERE t.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = t.`dominio`);

UPDATE `pagos` p SET p.`dominio` = NULL
 WHERE p.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = p.`dominio`);

UPDATE `pagos` p SET p.`contrato` = NULL
 WHERE p.`contrato` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `contratos` x WHERE x.`id` = p.`contrato`);

UPDATE `pagos` p SET p.`comprobante` = NULL
 WHERE p.`comprobante` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `comprobantes` x WHERE x.`id` = p.`comprobante`);

UPDATE `pagos` p SET p.`medio` = NULL
 WHERE p.`medio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `medios` x WHERE x.`id` = p.`medio`);


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

CALL _reactor_fk('notificaciones', 'fk_notificaciones_dominio', 'dominio',     'dominios',     'SET NULL');
CALL _reactor_fk('pagos',          'fk_pagos_dominio',          'dominio',     'dominios',     'RESTRICT');
CALL _reactor_fk('pagos',          'fk_pagos_contrato',         'contrato',    'contratos',    'RESTRICT');
CALL _reactor_fk('pagos',          'fk_pagos_comprobante',      'comprobante', 'comprobantes', 'RESTRICT');
CALL _reactor_fk('pagos',          'fk_pagos_medio',            'medio',       'medios',       'RESTRICT');

DROP PROCEDURE _reactor_fk;
