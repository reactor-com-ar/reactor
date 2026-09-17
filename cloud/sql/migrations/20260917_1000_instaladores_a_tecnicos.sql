-- `instaladores` pasa a llamarse `tecnicos`.
--
-- Rename puro: no cambia ni una columna, ni un tipo, ni un valor. La tabla es la
-- vidriera publica de `www.reactor.com.ar` (92 filas, 12 publicadas) y la unica
-- en la que ese docroot ESCRIBE, cuando alguien completa el formulario de
-- registro. Junto con esta migracion se renombro todo lo que la rodea: la
-- carpeta `www/instaladores/` -> `www/tecnicos/`, `www/lib/instaladores.php` ->
-- `www/lib/tecnicos.php`, las siete funciones (`instaladoresListar()` ->
-- `tecnicosListar()`, etc.) y la URL publica `/instaladores` -> `/tecnicos`.
--
-- ------------------------------------------------------------------------
-- OJO: EL BACK OFFICE LEGACY LEE ESTA TABLA Y NO ESTA EN ESTE REPO
-- ------------------------------------------------------------------------
--
-- Las dos columnas que gobiernan la publicacion —`aprobacion` y `visibilidad`—
-- las sube a mano el equipo desde el back office viejo, que vive fuera de este
-- repositorio. El sitio publico solo INSERTA solicitudes (`aprobacion = '1'`,
-- `visibilidad = '0'`); quien las aprueba y las publica es ese sistema.
--
-- O sea que aplicar esta migracion SIN tocar el legacy deja su modulo de
-- instaladores apuntando a una tabla que ya no existe: las altas del sitio se
-- siguen guardando, pero nadie puede aprobarlas ni publicarlas hasta que alla se
-- cambie el nombre tambien. NO ES UN EFECTO SECUNDARIO IMPREVISTO — es la
-- consecuencia directa del rename, y hay que coordinarlo antes de correr esto en
-- produccion.
--
-- La alternativa de dejar una vista `instaladores` como puente NO se puso a
-- proposito: seria una tabla con dos nombres vivos a la vez, que es justamente lo
-- que el rename viene a terminar. Si hace falta como escalon temporal, se agrega
-- en su propia migracion y con fecha de retiro.
--
-- ------------------------------------------------------------------------
-- POR QUE ES BARATO
-- ------------------------------------------------------------------------
--
-- `RENAME TABLE` es un cambio de metadatos: no copia filas y no bloquea mas que
-- el instante del intercambio. Y acá no arrastra nada mas:
--
--   * la tabla NO TIENE claves foraneas propias —su unico indice es la PK—, asi
--     que no hay constraints que reescribir;
--   * NINGUNA FK DEL ESQUEMA LA REFERENCIA (las 99 declaradas apuntan a otras
--     tablas), asi que no hay hijos que queden colgados. Si alguna existiera,
--     InnoDB le reescribe la referencia sola en el rename, pero conviene saber
--     que no hay ninguna.
--
-- IDEMPOTENTE con el patron `SET @s / PREPARE` del resto de las migraciones de
-- este repo. Acá se preguntan LAS DOS TABLAS y no solo una: con mirar unicamente
-- si `tecnicos` existe, una base donde no estuviera ninguna de las dos
-- intentaria renombrar `instaladores` y explotaria. La condicion es "hay origen y
-- todavia no hay destino".
--
-- Sin `USE <base>`: la conexion PDO ya selecciona la del entorno (CLAUDE.md).


SET @s = (SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instaladores')
    AND NOT EXISTS(SELECT 1 FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tecnicos'),
    'RENAME TABLE `instaladores` TO `tecnicos`',
    'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
