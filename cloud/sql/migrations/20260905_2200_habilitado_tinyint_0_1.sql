-- `habilitado` pasa a tener DOS valores posibles y nada mas: 0 (deshabilitado)
-- y 1 (habilitado). Se aplica a TODAS las tablas de la base que tengan una
-- columna con ese nombre, sin enumerarlas: hoy son 22 en desarrollo y el
-- criterio vale para cualquiera que se agregue despues.
--
-- POR QUE. La columna arrastraba TRES codificaciones distintas del sistema
-- historico y el codigo tenia que defenderse de las tres en cada lectura
-- (`in_array($h, ['S','1','Y'])`, `(string) $h === '1'`, `$h <> 1`, y ademas
-- NULL). Medido en desarrollo antes de esta migracion:
--
--   varchar(1) NULL   botones, controles, paneles, perfiles, usuarios,
--                     usuariosgrupos            -> '1' / '0' (y 'S' / 'N' en
--                                                  2 filas de `usuarios`)
--   smallint   NULL   accesos___, canales, canales___, contratos, dashboards,
--                     dispositivos, dispositivos___, dominios, dominiosmedios,
--                     empleados___, enlaces___, planes, roles, temporizadores
--                                               -> 1 / 0 (y 1 fila NULL en
--                                                  `canales`)
--   tinyint(1) NULL   articulos, articulos___   -> 1 / 0
--
-- El tipo suelto no era el problema de fondo: el problema es que la columna
-- admitia valores que ninguna pantalla sabe leer. Con `varchar(1)` NULL,
-- PDO escribe el booleano `false` de PHP como CADENA VACIA -- verificado
-- contra la base de desarrollo: `UPDATE perfiles SET habilitado = :h` con
-- `:h => false` deja `''`, que no es ni '0' ni '1' y hace que la fila se lea
-- como deshabilitada en un lugar y como habilitada en otro. `TINYINT NOT NULL`
-- cierra esa puerta en el motor: cualquier binding se resuelve a 0 o a 1.
--
-- NORMALIZACION DE VALORES. Antes del ALTER se reescribe el contenido:
-- '1' / 'S' / 'SI' / 'Y' / 'T' (en cualquier caja y con espacios) pasan a 1 y
-- TODO lo demas -- incluidos NULL, '' y 'N' -- pasa a 0. El criterio es
-- "deshabilitado por defecto": un valor que ninguna convencion reconoce no
-- puede otorgar acceso. Sin este paso el ALTER convertiria 'S' a 0 igual, pero
-- por truncamiento silencioso del motor y no por una decision escrita.
--
-- IDEMPOTENTE: el cursor solo trae las columnas que todavia no son
-- `tinyint(1) NOT NULL`, asi que reaplicarla no reescribe ninguna tabla ya
-- migrada y no falla. Si una corrida se interrumpe alcanza con relanzarla.
--
-- VISTAS: no se tocan. `perfilesvista` expone `perfiles`.`habilitado` y MySQL
-- resuelve el tipo de la columna contra la tabla base en cada consulta, asi
-- que hereda el `tinyint` sola. Por eso el cursor filtra `BASE TABLE`: un
-- ALTER sobre una vista fallaria y abortaria la migracion.
--
-- FK / INDICES: ninguna columna `habilitado` participa de una clave foranea
-- ni de un indice en ninguno de los dos entornos, asi que el MODIFY no
-- reconstruye relaciones.
--
-- APLICACION: requiere el cambio de codigo que va junto con esta migracion --
-- panel, cloud y app dejan de escribir '1'/'0'/'S' y pasan a escribir 0 y 1.
-- El orden no importa: mientras dure el despliegue, MySQL sigue aceptando
-- `habilitado = '1'` contra un tinyint (lo coacciona a 1) y `= 'S'` lo
-- coacciona a 0, que es el mismo resultado que daba antes de migrar.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).

DROP PROCEDURE IF EXISTS _reactor_habilitado_0_1;
CREATE PROCEDURE _reactor_habilitado_0_1()
BEGIN
    DECLARE fin INT DEFAULT 0;
    DECLARE t VARCHAR(64);
    DECLARE cur CURSOR FOR
        SELECT c.TABLE_NAME
          FROM information_schema.COLUMNS c
          INNER JOIN information_schema.TABLES t
                  ON t.TABLE_SCHEMA = c.TABLE_SCHEMA
                 AND t.TABLE_NAME   = c.TABLE_NAME
         WHERE c.TABLE_SCHEMA = DATABASE()
           AND c.COLUMN_NAME  = 'habilitado'
           AND t.TABLE_TYPE   = 'BASE TABLE'
           AND NOT (c.COLUMN_TYPE = 'tinyint(1)' AND c.IS_NULLABLE = 'NO')
         ORDER BY c.TABLE_NAME;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET fin = 1;

    OPEN cur;
    bucle: LOOP
        FETCH cur INTO t;
        IF fin = 1 THEN
            LEAVE bucle;
        END IF;

        -- 1. Contenido: todo lo que no sea un "si" reconocible queda en 0.
        --    COALESCE con '0' cubre el NULL antes de que el NOT NULL lo
        --    rechace; TRIM/UPPER cubren ' s ' y 'Si' del sistema historico.
        SET @u = CONCAT(
            'UPDATE `', t, '` SET `habilitado` = CASE WHEN ',
            'UPPER(TRIM(COALESCE(`habilitado`, ''0''))) IN (''1'', ''S'', ''SI'', ''Y'', ''T'') ',
            'THEN 1 ELSE 0 END');
        PREPARE st FROM @u;
        EXECUTE st;
        DEALLOCATE PREPARE st;

        -- 2. Tipo: dos valores y ninguna ausencia. El default 0 hace que una
        --    fila insertada sin la columna nazca deshabilitada, no en NULL.
        SET @a = CONCAT(
            'ALTER TABLE `', t, '` MODIFY `habilitado` TINYINT(1) NOT NULL DEFAULT 0');
        PREPARE st FROM @a;
        EXECUTE st;
        DEALLOCATE PREPARE st;
    END LOOP;
    CLOSE cur;
END;

CALL _reactor_habilitado_0_1();
DROP PROCEDURE _reactor_habilitado_0_1;
