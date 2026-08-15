-- Conversion integral del motor y el juego de caracteres de toda la base:
--   MyISAM / latin1 / utf8mb3  ->  InnoDB / utf8mb4 / utf8mb4_unicode_ci
--
-- Corre igual en desarrollo y en produccion: no enumera tablas ni vistas, las
-- descubre de information_schema sobre DATABASE(). Es necesario porque los dos
-- entornos difieren -- prod tiene la tabla `programas` y 7 vistas, dev tiene
-- 126 tablas y 4 vistas -- y ademas corren motores distintos (dev MySQL 8.0.46,
-- prod MariaDB 10.11.16).
--
-- IDEMPOTENTE: solo toca las tablas que todavia no estan en
-- InnoDB + utf8mb4_unicode_ci. Reaplicarla no reconstruye nada y no falla, asi
-- que si una corrida se interrumpe alcanza con volver a lanzarla.
--
-- ORDEN: las tablas se convierten de menor a mayor tamano, para que una
-- interrupcion deje la mayor cantidad posible de trabajo consolidado.
--
--
-- POR QUE `CONVERT TO` ES SEGURO ACA
--   El riesgo clasico al migrar columnas latin1 es que contengan bytes UTF-8
--   mal guardados (doble codificacion): ahi `CONVERT TO` los vuelve a
--   codificar y produce mojibake irreversible, y hay que rodear por VARBINARY.
--   Se auditaron las 63 columnas de texto latin1: 2.709 filas tienen bytes
--   altos y NINGUNA forma secuencias UTF-8 validas -- es latin1 genuino (p.ej.
--   `videos`.`nombre` guardaba "Guia" con 0xED, no 0xC3AD). Por lo tanto
--   `CONVERT TO CHARACTER SET` reescribe los bytes correctamente y la
--   conversion es sin perdida. Verificado post-migracion en desarrollo: las
--   mismas 2.709 filas pasaron de 0 a 2.709 con UTF-8 valido, y los conteos
--   exactos de todas las tablas quedaron intactos.
--
-- APLICACION: no requiere cambios. Todos los componentes del repo ya abren la
--   conexion en utf8mb4 (DSN de PDO en cloud/panel, `charset="utf8mb4"` en
--   motor/main.py), asi que MySQL ya venia transcodificando al vuelo.
--
-- VERIFICACIONES PREVIAS (sin hallazgos en ninguna de las dos bases)
--   * Sin indices FULLTEXT ni SPATIAL.
--   * Ningun indice supera el limite de 3072 bytes al pasar a utf8mb4.
--   * Ninguna tabla sin PRIMARY KEY.
--   * Ningun ancho de fila cerca del limite de 65535 bytes de InnoDB.
--
-- ESPACIO: cada ALTER reconstruye la tabla; InnoDB ocupa mas que MyISAM.
--   Prever libre >= 2x la tabla mas grande (`registros`, ~134 MB en prod).
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Default de la base, para que todo objeto nuevo nazca ya en utf8mb4.
--    Sin nombre de base: aplica a la base por defecto de la conexion.
-- ---------------------------------------------------------------------------

ALTER DATABASE CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- 2. Tablas: motor InnoDB + conversion de todas las columnas de texto.
-- ---------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS _reactor_migrar_tablas;
CREATE PROCEDURE _reactor_migrar_tablas()
BEGIN
    DECLARE fin INT DEFAULT 0;
    DECLARE t VARCHAR(64);
    DECLARE cur CURSOR FOR
        SELECT TABLE_NAME
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_TYPE   = 'BASE TABLE'
           AND (ENGINE <> 'InnoDB' OR TABLE_COLLATION <> 'utf8mb4_unicode_ci')
         ORDER BY DATA_LENGTH + INDEX_LENGTH ASC;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET fin = 1;

    OPEN cur;
    bucle: LOOP
        FETCH cur INTO t;
        IF fin = 1 THEN
            LEAVE bucle;
        END IF;
        SET @s = CONCAT('ALTER TABLE `', t, '` ENGINE=InnoDB, ',
                        'CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, ',
                        'ROW_FORMAT=DYNAMIC');
        PREPARE st FROM @s;
        EXECUTE st;
        DEALLOCATE PREPARE st;
    END LOOP;
    CLOSE cur;
END;

CALL _reactor_migrar_tablas();
DROP PROCEDURE _reactor_migrar_tablas;


-- ---------------------------------------------------------------------------
-- 3. Vistas: se recrean con `SQL SECURITY INVOKER`.
--
--    Hoy todas tienen `DEFINER=admin@%`, que en produccion es el usuario
--    maestro de RDS pero en desarrollo no tiene privilegios sobre la base: ahi
--    las vistas estaban ROTAS (error 1356 / 1143) y eso incluso abortaba
--    cualquier `mysqldump` de la base entera. Con INVOKER la vista corre con
--    los permisos de quien consulta y deja de depender de un usuario que puede
--    no existir -- o no tener grants -- en el entorno destino.
--
--    La definicion se reusa tal cual sale de information_schema. Viene
--    calificada con el nombre de la base (`reactor_dev`. / `reactor`.) y asi
--    se deja: MySQL SIEMPRE almacena las vistas calificadas contra la base
--    donde viven y vuelve a agregar el prefijo aunque uno lo quite, de modo
--    que no es un problema de portabilidad sino el comportamiento normal.
--
--    IMPORTANTE -- solo se tocan las vistas que HOY funcionan. Produccion
--    tiene 3 vistas muertas (`registrosvista`, `widgetsvista`, `canalesvista`)
--    que apuntan a objetos ya eliminados: las dos primeras a la tabla
--    `grupos`, que no existe, y la tercera a `canales`.`identificador`, columna
--    que tampoco existe. Un `CREATE OR REPLACE` sobre ellas fallaria y abortaria
--    la migracion; peor aun, MariaDB implementa `CREATE OR REPLACE` como
--    drop-then-create, con lo que un fallo podria dejar la vista destruida.
--    Por eso cada vista se sondea antes con un `SELECT ... LIMIT 0` y se saltea
--    si da error, quedando exactamente como estaba. La migracion NO borra esas
--    vistas: repararlas o eliminarlas es una decision aparte.
--
--    No hay conversion de charset que hacer sobre las vistas: no almacenan
--    datos y heredan la colacion de las tablas base ya convertidas en el
--    paso 2. Por eso este bloque va despues.
-- ---------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS _reactor_normalizar_vistas;
CREATE PROCEDURE _reactor_normalizar_vistas()
BEGIN
    DECLARE fin  INT DEFAULT 0;
    DECLARE rota INT DEFAULT 0;
    DECLARE v VARCHAR(64);
    DECLARE d LONGTEXT;
    DECLARE cur CURSOR FOR
        SELECT TABLE_NAME, VIEW_DEFINITION
          FROM information_schema.VIEWS
         WHERE TABLE_SCHEMA = DATABASE();
    DECLARE CONTINUE HANDLER FOR NOT FOUND     SET fin  = 1;
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION  SET rota = 1;

    OPEN cur;
    bucle: LOOP
        FETCH cur INTO v, d;
        IF fin = 1 THEN
            LEAVE bucle;
        END IF;

        -- Sonda: solo se recrea la vista si HOY funciona. Ver comentario de
        -- arriba -- hay vistas muertas que no deben tocarse.
        SET rota = 0;
        SET @p = CONCAT('SELECT 1 FROM `', v, '` LIMIT 0');
        PREPARE stp FROM @p;
        EXECUTE stp;
        DEALLOCATE PREPARE stp;

        IF rota = 0 THEN
            SET @s = CONCAT('CREATE OR REPLACE SQL SECURITY INVOKER VIEW `', v, '` AS ', d);
            PREPARE sts FROM @s;
            EXECUTE sts;
            DEALLOCATE PREPARE sts;
        END IF;
    END LOOP;
    CLOSE cur;
END;

CALL _reactor_normalizar_vistas();
DROP PROCEDURE _reactor_normalizar_vistas;
