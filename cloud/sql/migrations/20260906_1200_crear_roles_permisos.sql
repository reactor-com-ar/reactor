-- Tabla puente `roles_permisos`: que permisos tiene cada rol, normalizado.
--
-- NO REEMPLAZA A `roles`.`permisos`, LA ESPEJA. Esa columna es un varchar(1000)
-- con la lista de ids entre parentesis (`(1001)(1004)`) y **la lee el sistema
-- legacy, fuera de este repo** -- en este repositorio no hay un solo lugar que
-- la use para autorizar (grep sobre cloud/panel/app/www/api: los unicos hits
-- son los dos endpoints de cloud que la administran). Si cloud dejara de
-- mantenerla, el legacy se quedaria sin permisos de un dia para el otro.
--
-- De ahora en mas, entonces:
--
--   `roles_permisos`   fuente normalizada, con FK, indices e integridad. Es lo
--                      que lee y escribe cloud.
--   `roles`.`permisos` espejo serializado para el legacy. Lo reescribe
--                      api/roles.php en la MISMA transaccion, a partir de la
--                      tabla puente. Nadie lo edita a mano.
--
-- LOS IDS COLGADOS NO ENTRAN ACA, Y ES INEVITABLE. Los 10 roles referencian
-- 576 pares (rol, permiso); 40 de ellos apuntan a los permisos 1101-1105, que
-- no existen en `permisos` (los 8 roles con datos los tienen los cinco). Con
-- una FK real esos 40 no se pueden insertar, asi que la tabla arranca con 536.
-- Los 40 restantes **sobreviven en el varchar**: api/roles.php los conserva al
-- reserializar y el selector los sigue mostrando en su grupo `Sin catalogo`,
-- para que se puedan sacar a proposito y no por efecto colateral de esta
-- migracion. Borrarlos aca seria decidir por el legacy sin preguntarle.
--
-- LA SIEMBRA USA UN JOIN CON LIKE Y NO UN SPLIT DE LA CADENA. Partir un varchar
-- en filas necesita REGEXP/JSON o una tabla de numeros, y dev corre MySQL 8.0
-- mientras prod corre MariaDB 10.11: la sintaxis no coincide. El cruce
-- `roles` x `permisos` son 10 x 115 = 1150 comparaciones, nada. Y como el JOIN
-- es contra `permisos`, descarta los colgados solo.
--
-- El patron `%(id)%` incluye los parentesis a proposito: sin ellos `%101%`
-- tambien matchearia `(1010)` y el rol se llevaria permisos que no tiene.
--
-- FK AL ROL CON CASCADE y FK AL PERMISO CON CASCADE, las dos. Una fila de esta
-- tabla no significa nada sin sus dos puntas, y ninguna de las dos bajas queda
-- sin proteccion por eso: borrar un rol ya lo bloquean `perfiles` y
-- `controladores_roles`, y borrar un permiso ya se confirma con el desglose de
-- los roles que lo pierden (api/permisos.php). Es distinto del RESTRICT de
-- `controladores_roles`.`rol`, donde lo que estaba en juego era el acceso de una
-- persona.
--
-- Idempotente por partida doble: CREATE TABLE IF NOT EXISTS para la estructura e
-- INSERT IGNORE + UNIQUE para la siembra.
--
-- No usar `USE <db>` aca: la conexion PDO ya selecciona la DB del entorno via
-- DB_NAME (`reactor` en prod, `reactor_dev` en dev).

CREATE TABLE IF NOT EXISTS `roles_permisos` (
    `id`       INT      NOT NULL AUTO_INCREMENT,
    `rol`      INT      NOT NULL,
    `permiso`  INT      NOT NULL,
    `asignado` DATETIME NOT NULL,
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE KEY `uq_roles_permisos` (`rol`, `permiso`),
    KEY `ix_roles_permisos_permiso` (`permiso`),
    CONSTRAINT `fk_roles_permisos_rol`     FOREIGN KEY (`rol`)     REFERENCES `roles`    (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT `fk_roles_permisos_permiso` FOREIGN KEY (`permiso`) REFERENCES `permisos` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

INSERT IGNORE INTO `roles_permisos` (`rol`, `permiso`, `asignado`)
SELECT r.`id`, p.`id`, NOW()
  FROM `roles` r
  JOIN `permisos` p ON r.`permisos` LIKE CONCAT('%(', p.`id`, ')%')
 WHERE r.`permisos` IS NOT NULL AND r.`permisos` <> '';
