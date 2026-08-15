-- Claves foraneas de `comprobantesrenglones`, `contratos`, `programas` y
-- `prospectos`, mas el ajuste de una FK previa para desarmar el tercer ciclo.
--
--   comprobantesrenglones.comprobante -> comprobantes.id  ON DELETE CASCADE
--   comprobantesrenglones.articulo    -> articulos.id     ON DELETE RESTRICT
--   contratos.cliente                 -> clientes.id      ON DELETE RESTRICT
--   contratos.dominio                 -> dominios.id      ON DELETE RESTRICT
--   contratos.plan                    -> planes.id        ON DELETE RESTRICT
--   programas.dominio                 -> dominios.id      ON DELETE RESTRICT
--   prospectos.cartera                -> carteras.id      ON DELETE SET NULL
--
--   dominios.contrato -> contratos.id   RESTRICT --> SET NULL   (ajuste)
--
-- Continua 20260814_1900_fk_comprobantes.sql. Idempotente y portable dev/prod.
--
--
-- QUEDA AFUERA: `contratos`.`promo` -> promos.id
--
--   NO SE PUEDE DECLARAR. No existe ninguna tabla `promos` -- ni con nombre
--   parecido -- en dev ni en produccion. Ademas la columna es `varchar(5)`, no
--   int, y sus 53 filas contienen todas el valor '0': es centinela puro, sin
--   un solo dato real. Si en algun momento se crea la tabla de promociones y
--   se migra la columna a int, se declara en una migracion aparte.
--
--
-- `programas` SOLO EXISTE EN PRODUCCION
--
--   La tabla esta en `reactor` pero no en `reactor_dev` (drift preexistente
--   entre entornos, detectado al comparar catalogos). Un ALTER incondicional
--   reventaria en desarrollo, asi que el procedimiento saltea las tablas que
--   no existen en la base donde corre. En prod aplica la FK; en dev no hace
--   nada. Cuando dev se ponga al dia, basta reaplicar esta migracion.
--
--
-- EL TERCER CICLO DEL ESQUEMA
--
--   `contratos`.`dominio` cierra un ciclo con `dominios`.`contrato`, declarada
--   en la migracion 1300. En prod hay 35 pares que se apuntan mutuamente.
--
--   Se resuelve con el mismo criterio que los dos ciclos anteriores
--   (usuarios <-> perfiles y dispositivos <-> adopciones): cede la punta que
--   es PUNTERO al registro vigente, se mantiene RESTRICT en la ESTRUCTURAL.
--
--     `dominios`.`contrato` = "el contrato vigente de este dominio" -> puntero,
--         y de hecho 113 de 151 dominios no tienen ninguno. -> SET NULL.
--     `contratos`.`dominio` = "de que dominio es este contrato" -> estructural.
--         Un contrato sin dominio no significa nada. -> RESTRICT.
--
--
-- DOS REGLAS DISTINTAS DEL RESTO, A PROPOSITO
--
--   `comprobantesrenglones`.`comprobante` -> CASCADE, la primera del esquema.
--     Un renglon es parte del comprobante, no una entidad independiente: no
--     tiene sentido que sobreviva a su cabecera ni que la bloquee. Es la
--     relacion cabecera-detalle clasica, el unico caso donde el borrado en
--     cascada es correcto y no una comodidad peligrosa. Los 6 huerfanos que
--     hay hoy son exactamente lo que esto previene a futuro.
--
--   `prospectos`.`cartera` -> SET NULL. Es una asignacion comercial opcional
--     (63 de 161 prospectos no tienen cartera). Que un prospecto quede sin
--     cartera al eliminarla es aceptable; bloquear la baja de una cartera por
--     prospectos viejos, no. Distinto de `carteras`.`ejecutivo`, que quedo
--     RESTRICT porque dejar una cartera sin responsable si es grave.
--
--
-- LIMPIEZA PREVIA -- censo (prod / dev):
--
--   comprobantesrenglones.comprobante : 7062 / 6694 filas, 0 ceros, 6 HUERFANOS
--   comprobantesrenglones.articulo    : 3753 / 3564 ceros, 1 HUERFANO
--   contratos.cliente                 : 53 / 50 filas, 0 ceros, 0 huerfanos -> LIMPIA
--   contratos.dominio                 : 53 / 50 filas, 0 ceros, 0 huerfanos -> LIMPIA
--   contratos.plan                    : 53 / 50 filas, 0 ceros, 0 huerfanos -> LIMPIA
--   programas.dominio                 : 122 filas (solo prod), 0 ceros, 0 huerfanos -> LIMPIA
--   prospectos.cartera                : 63 / 63 ceros, 0 huerfanos
--
--   Los 6 renglones huerfanos pertenecen a comprobantes que ya se borraron:
--   son lineas de factura sin factura. Se les NULea el puntero, pero conviene
--   revisarlos -- lo mas probable es que correspondan eliminarlos, ya que un
--   renglon sin cabecera no tiene ningun uso. Es justamente el desastre que
--   el CASCADE evita de aca en mas.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Neutralizar referencias invalidas.
-- ---------------------------------------------------------------------------

UPDATE `comprobantesrenglones` r SET r.`comprobante` = NULL
 WHERE r.`comprobante` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `comprobantes` x WHERE x.`id` = r.`comprobante`);

UPDATE `comprobantesrenglones` r SET r.`articulo` = NULL
 WHERE r.`articulo` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `articulos` x WHERE x.`id` = r.`articulo`);

UPDATE `contratos` c SET c.`cliente` = NULL
 WHERE c.`cliente` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `clientes` x WHERE x.`id` = c.`cliente`);

UPDATE `contratos` c SET c.`dominio` = NULL
 WHERE c.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = c.`dominio`);

UPDATE `contratos` c SET c.`plan` = NULL
 WHERE c.`plan` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `planes` x WHERE x.`id` = c.`plan`);

UPDATE `prospectos` p SET p.`cartera` = NULL
 WHERE p.`cartera` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `carteras` x WHERE x.`id` = p.`cartera`);

-- `programas` solo existe en produccion; se limpia con SQL dinamico para que
-- la migracion no falle en desarrollo.
SET @lim = IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programas') > 0,
    'UPDATE `programas` p SET p.`dominio` = NULL
      WHERE p.`dominio` IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = p.`dominio`)',
    'DO 0');
PREPARE stlim FROM @lim;
EXECUTE stlim;
DEALLOCATE PREPARE stlim;


-- ---------------------------------------------------------------------------
-- 2. Alta / ajuste de constraints.
--
--    El procedimiento saltea la tabla si no existe en esta base (caso
--    `programas`), y si la FK ya existe con otra regla la borra y la recrea.
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
        -- La tabla no existe en este entorno: no hay nada que hacer.
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

-- Ajuste de la FK previa: desarma el tercer ciclo.
CALL _reactor_fk('dominios', 'fk_dominios_contrato', 'contrato', 'contratos', 'SET NULL');

-- Las nuevas.
CALL _reactor_fk('comprobantesrenglones', 'fk_renglones_comprobante', 'comprobante', 'comprobantes', 'CASCADE');
CALL _reactor_fk('comprobantesrenglones', 'fk_renglones_articulo',    'articulo',    'articulos',    'RESTRICT');
CALL _reactor_fk('contratos',             'fk_contratos_cliente',     'cliente',     'clientes',     'RESTRICT');
CALL _reactor_fk('contratos',             'fk_contratos_dominio',     'dominio',     'dominios',     'RESTRICT');
CALL _reactor_fk('contratos',             'fk_contratos_plan',        'plan',        'planes',       'RESTRICT');
CALL _reactor_fk('programas',             'fk_programas_dominio',     'dominio',     'dominios',     'RESTRICT');
CALL _reactor_fk('prospectos',            'fk_prospectos_cartera',    'cartera',     'carteras',     'SET NULL');

DROP PROCEDURE _reactor_fk;
