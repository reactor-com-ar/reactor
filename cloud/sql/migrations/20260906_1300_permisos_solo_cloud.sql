-- Los permisos pasan a ser un concepto de cloud y de nadie mas.
--
-- Hasta hoy `roles` y `permisos` eran el modelo de permisos del sistema
-- historico, compartido entre tres productos: la columna `sistema` decia a cual
-- aplicaba cada fila (A = Reactor Admin, C = Reactor Control, P = Reactor App).
-- Ese reparto se elimina: los permisos son de cloud, punto.
--
-- COLUMNAS QUE SE VAN (6):
--
--   permisos.sistema   el reparto por producto que ya no existe.
--   roles.sistema      idem.
--   roles.nivel        'A'/'O' del menu legacy; ya no se editaba desde cloud.
--   roles.menus        lista de ids de `menus` en varchar, del legacy.
--   roles.accesos      lista de ids de una tabla `accesos` QUE NO EXISTE en
--                      este esquema. Referencias a la nada desde el principio.
--   roles.permisos     lista de ids de `permisos` en varchar. La reemplaza
--                      `roles_permisos`, creada en la migracion 20260906_1200.
--
-- ESTO ROMPE AL SISTEMA LEGACY, y es una decision tomada, no un descuido.
-- `roles`.`permisos` / `menus` / `accesos` y las dos `sistema` son el modelo de
-- permisos que lee el legacy desde afuera de este repo. Al eliminarlas, el
-- legacy se queda sin su fuente. Dentro de este repositorio no afecta a nadie:
-- `panel` y `app` tocan `roles` solamente por `r.id` y `r.nombre`
-- (panel/lib/acceso.php, panel/api/usuarios.php, app/lib/contexto.php), y
-- ninguno de los dos lee `permisos`. Verificado con grep sobre app/ panel/
-- cloud/ www/ api/ antes de escribir esta migracion.
--
-- QUE PASA CON LOS IDS COLGADOS. El varchar `roles.permisos` referenciaba 576
-- pares (rol, permiso), de los cuales 40 apuntaban a los permisos 1101-1105,
-- que no existen en `permisos`. Esos 40 desaparecen con la columna: nunca
-- pudieron entrar a `roles_permisos` porque la FK no los admite, y no
-- resolvian a nada. Los 536 reales ya viven en la tabla puente.
--
-- LA SIEMBRA SE REPITE ANTES DE BORRAR. `20260906_1200` ya la hizo, pero entre
-- una migracion y otra alguien pudo tocar el varchar; el INSERT IGNORE es
-- barato y garantiza que no se pierda nada al soltar la columna. Si 1200 no
-- corrio, esto la cubre igual.
--
-- POR QUE EL PATRON `SET @s = ... PREPARE`. `DROP COLUMN IF EXISTS` existe en
-- MariaDB pero NO en MySQL 8.0, y dev corre MySQL mientras prod corre MariaDB.
-- Un procedimiento almacenado necesitaria `DELIMITER`, que el migrador no
-- soporta (aplica el archivo con un solo exec). Construir la sentencia desde
-- information_schema y ejecutarla preparada funciona igual en los dos motores y
-- vuelve la migracion idempotente columna por columna. `DO 0` es el no-op.
--
-- No usar `USE <db>` aca: la conexion PDO ya selecciona la DB del entorno via
-- DB_NAME (`reactor` en prod, `reactor_dev` en dev).

-- 1. Red de seguridad: que todo par (rol, permiso) real este en la tabla puente
--    antes de soltar el varchar que lo contenia.
--
--    VA GUARDADO POR EL MISMO `IF EXISTS` que los DROP, y no suelto: en la
--    segunda pasada la columna `roles`.`permisos` ya no existe y el INSERT
--    moriria con un 1054 ("Unknown column 'r.permisos'"). Sin el guardarraíl la
--    migracion es de un solo uso, que es justo lo que estas migraciones no
--    pueden ser -- el migrador la bloquea por el ledger, pero el ledger no es
--    la unica forma en que un .sql termina corriendo dos veces.
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'permisos'),
    'INSERT IGNORE INTO `roles_permisos` (`rol`, `permiso`, `asignado`) SELECT r.`id`, p.`id`, NOW() FROM `roles` r JOIN `permisos` p ON r.`permisos` LIKE CONCAT(''%('', p.`id`, '')%'') WHERE r.`permisos` IS NOT NULL AND r.`permisos` <> ''''',
    'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. permisos.sistema
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'permisos' AND COLUMN_NAME = 'sistema'), 'ALTER TABLE `permisos` DROP COLUMN `sistema`', 'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. roles.sistema
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'sistema'), 'ALTER TABLE `roles` DROP COLUMN `sistema`', 'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 4. roles.nivel
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'nivel'), 'ALTER TABLE `roles` DROP COLUMN `nivel`', 'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 5. roles.menus
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'menus'), 'ALTER TABLE `roles` DROP COLUMN `menus`', 'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 6. roles.accesos
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'accesos'), 'ALTER TABLE `roles` DROP COLUMN `accesos`', 'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 7. roles.permisos — ultima, para que los pasos anteriores puedan fallar sin
--    haber soltado todavia la fuente que alimenta la tabla puente.
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'permisos'), 'ALTER TABLE `roles` DROP COLUMN `permisos`', 'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
