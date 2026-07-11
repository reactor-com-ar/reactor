-- Tabla `sucesos_log`: log de actividad de los modulos del panel cloud,
-- leido por la herramienta Visor de sucesos (§Herramientas). Los modulos
-- escriben con el helper `registrarSuceso()` de api/lib/sucesos.php; el
-- visor solo lee.
--
-- Es independiente de la tabla legacy `sucesos` (compartida con las apps
-- historicas api / panel / www / app) para no interferir con esas apps.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS permite correrlo tanto en
-- entornos nuevos como existentes sin efecto en el segundo caso.
--
-- No usar `USE <db>` aca: la conexion PDO de cloud/api/bootstrap.php ya
-- selecciona la DB del entorno via DB_NAME (`reactor` en prod,
-- `reactor_dev` en dev).

CREATE TABLE IF NOT EXISTS `sucesos_log` (
    `id`      INT(11)      NOT NULL AUTO_INCREMENT,
    `fecha`   DATETIME(0)  NULL DEFAULT NULL,
    `origen`  VARCHAR(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
    `tipo`    VARCHAR(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'info',
    `detalle` TEXT         CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
    PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=Dynamic;
