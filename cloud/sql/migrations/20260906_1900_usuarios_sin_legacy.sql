-- `usuarios` suelta las cinco columnas del modelo de alcance historico.
--
-- COLUMNAS QUE SE VAN (5):
--
--   usuarios.paneles   varchar(255), lista de ids. VACIA en las 2083 filas.
--   usuarios.panel     int, FK `fk_usuarios_panel` -> `paneles`. 2078 con valor.
--   usuarios.perfiles  int, contador. En 0 o NULL en las 2083 filas.
--   usuarios.roles     varchar(255). Solo 3 filas con algo, y ni siquiera con un
--                      formato: dos dicen `(201)` y una dice `admin`.
--   usuarios.dominios  varchar(1). 1704 filas, TODAS con el literal `"0"`.
--
-- LO QUE LAS REEMPLAZA, que es de lo que hablaba el pedido:
--
--   `perfiles`         a que dominios entra la cuenta. Ya no se deduce de
--                      `usuarios.dominios` (que ademas nunca dijo nada: 1704
--                      ceros) ni de `usuarios.perfiles` (siempre 0).
--   `perfiles_paneles` a que paneles entra en cada dominio. Reemplaza a
--                      `usuarios.paneles`, que estaba vacia.
--   `perfiles`.`panel` el ultimo panel abierto, por perfil. Reemplaza a
--                      `usuarios`.`panel`, que era el ultimo panel de la CUENTA
--                      — un solo valor para alguien que opera en varios
--                      dominios, que es justo el dato que no sirve.
--   ninguna            para `usuarios.roles`. Los roles pasaron a ser un
--                      concepto de cloud (`controladores_roles`) el 06/09/2026;
--                      un usuario final no tiene rol.
--
-- `perfil` Y `dominio` EN SINGULAR NO SE TOCAN. Son las dos columnas vivas: el
-- perfil y el dominio ACTIVOS de la sesion, que escriben
-- `panelPerfilActivoAsentar()` y el cambio de dominio, y que leen `panel` y
-- `app` en cada request. Es facil confundirlas con las plurales al grepear —
-- de las siete columnas con nombre parecido, se van cinco y quedan dos.
--
-- QUE SE PIERDE DE VERDAD: `usuarios.panel`, las 2078 filas. Era el ultimo
-- panel que la cuenta habia abierto, sin distinguir dominio. Su reemplazo,
-- `perfiles.panel`, tiene 937 filas: quien no la tenga cargada va a abrir la app
-- en el primer panel de su dominio en vez del ultimo que uso, una sola vez.
-- `appPanelesDelDominio()` ya resuelve ese caso ("el recordado si sigue siendo
-- valido, si no el primero"), asi que no rompe nada: degrada una comodidad.
--
-- La FK se suelta ANTES que la columna: con la restriccion viva el DROP COLUMN
-- falla. El indice `fk_usuarios_panel` se va solo junto con la columna.
--
-- Idempotente con el mismo patron `SET @s / PREPARE` del resto de las
-- migraciones de hoy: `DROP ... IF EXISTS` no existe en MySQL 8, y dev corre
-- MySQL mientras prod corre MariaDB.
--
-- No usar `USE <db>` aca: la conexion PDO ya selecciona la DB del entorno via
-- DB_NAME (`reactor` en prod, `reactor_dev` en dev).

-- 1. La FK de `panel`, primero.
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND CONSTRAINT_NAME = 'fk_usuarios_panel' AND CONSTRAINT_TYPE = 'FOREIGN KEY'),
    'ALTER TABLE `usuarios` DROP FOREIGN KEY `fk_usuarios_panel`',
    'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. usuarios.paneles
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'paneles'),
    'ALTER TABLE `usuarios` DROP COLUMN `paneles`', 'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. usuarios.panel  (la SINGULAR de esta tabla; `perfiles`.`panel` se conserva)
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'panel'),
    'ALTER TABLE `usuarios` DROP COLUMN `panel`', 'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 4. usuarios.perfiles  (el contador; `usuarios`.`perfil` se conserva)
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'perfiles'),
    'ALTER TABLE `usuarios` DROP COLUMN `perfiles`', 'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 5. usuarios.roles
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'roles'),
    'ALTER TABLE `usuarios` DROP COLUMN `roles`', 'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 6. usuarios.dominios  (`usuarios`.`dominio` se conserva)
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'dominios'),
    'ALTER TABLE `usuarios` DROP COLUMN `dominios`', 'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
