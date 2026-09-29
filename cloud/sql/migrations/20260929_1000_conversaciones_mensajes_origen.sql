-- `conversaciones_mensajes`.`origen`: la URL COMPLETA de la pagina en la que
-- estaba parada la persona cuando escribio ESE mensaje.
--
-- POR QUE NO ALCANZA CON `conversaciones`.`pagina`. Esa columna guarda una sola
-- cosa: donde se abrio la burbuja. Pero el chat vive en el pie de TODAS las
-- paginas del sitio y la conversacion sobrevive a la navegacion —el `uuid` va en
-- `sessionStorage`—, asi que alguien puede preguntar por los planes en
-- `/precios/planes`, seguir leyendo, y tres mensajes despues preguntar otra cosa
-- parado en `/ayuda`. Con una sola columna toda esa charla figura como si
-- hubiera pasado en la primera pagina.
--
-- Y ES LA URL COMPLETA, no el path: `https://www.reactor.com.ar/precios/planes`
-- y no `/precios/planes`. La querystring y el host son parte del contexto — una
-- consulta que llego desde una campana con `?utm_source=` dice de donde salio
-- esa persona, y el path solo lo pierde.
--
-- OJO CON EL NOMBRE: `conversaciones`.`origen` —la tabla padre— guarda la IP, no
-- una URL. Son dos columnas con el mismo nombre y distinto significado en dos
-- tablas que se leen siempre juntas. Esta anotado aca, en `db/schema.sql` y en
-- el CLAUDE.md de `www/` porque es exactamente el tipo de cosa que se lee mal
-- seis meses despues.
--
-- SOLO SE LLENA EN LAS FILAS DE LA PERSONA (`rol = 'user'`). La respuesta del
-- modelo no se escribe desde ninguna pagina; anotarle la misma URL del turno
-- anterior seria inventar un dato que nadie produjo. Las filas de `assistant`
-- quedan en NULL y eso significa "no aplica", no "falta".
--
-- LAS FILAS QUE YA EXISTEN QUEDAN EN NULL a proposito: nadie anoto esa URL
-- cuando se escribieron y no se puede reconstruir sin inventarla.
-- `conversaciones`.`pagina` de esa conversacion es lo mas parecido que hay, pero
-- es la pagina de APERTURA y copiarla aca la haria pasar por el dato real.
--
-- Idempotente: se chequea `information_schema` antes del ALTER, asi la
-- migracion se puede correr dos veces sin error.
--
-- No usar `USE <db>` aca: la conexion PDO ya selecciona la DB del entorno via
-- DB_NAME (`reactor` en prod, `reactor_dev` en dev).

SET @existe := (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'conversaciones_mensajes'
       AND COLUMN_NAME  = 'origen'
);

SET @sql := IF(
    @existe = 0,
    'ALTER TABLE `conversaciones_mensajes`
        ADD COLUMN `origen` VARCHAR(500)
            CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
            NULL DEFAULT NULL
            AFTER `texto`',
    'SELECT "conversaciones_mensajes.origen ya existe" AS aviso'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
