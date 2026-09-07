-- `perfiles` deja de tener rol.
--
-- COLUMNAS QUE SE VAN (2):
--
--   perfiles.rol    int, FK `fk_perfiles_rol` -> `roles`.`id`.
--   perfiles.roles  varchar(100) del sistema historico. **Vacia en las 2227
--                   filas** (verificado antes de escribir esto), asi que se va
--                   sin discusion: es el mismo concepto en el formato viejo.
--
-- NO SE TOCAN `perfiles`.`permisos` NI `perfiles`.`paneles`, aunque tengan la
-- misma pinta de lista legacy `(1003)`. Tienen datos -- 2054 y 2020 filas -- y
-- no son roles: sacarlas es otra decision, no un efecto lateral de esta.
--
-- ESTA MIGRACION CAMBIA QUIEN PUEDE ENTRAR AL PANEL, y es el punto de todo el
-- cambio, no un descuido. `perfiles`.`rol` era la UNICA puerta del panel:
-- `panel/lib/acceso.php` exigia `p.rol IN (101)` (Administrador). Medido en dev
-- antes de aplicarla:
--
--   perfiles habilitados ............ 2063
--   de esos, con rol Administrador ...  422  (371 cuentas)  <- los que entraban
--   Operadores ...................... 1478                  <- los que no
--
-- Al sacar la columna el panel pasa a gatear solo por "perfil habilitado en el
-- dominio de la sesion", asi que esos 1478 Operadores mas los 141 sin rol y los
-- 22 de roles internos pasan a tener acceso al BackOffice. Fue una decision
-- explicita: el control de acceso se rehace en una etapa siguiente sobre
-- `controladores` / `roles_permisos`, y hasta entonces el panel queda abierto a
-- cualquier perfil habilitado.
--
-- **Si esta etapa se demora, esto es lo primero que hay que volver a mirar.**
--
-- QUE NO SE ROMPE. `roles` sigue existiendo y sigue siendo el catalogo de cloud
-- (`controladores_roles`, `roles_permisos`). Lo que desaparece es el vinculo
-- entre un perfil de cliente y un rol. `app` mostraba el nombre del rol en la
-- ficha del perfil y deja de mostrarlo; ninguna otra lectura de `roles` en
-- `panel` o `app` sobrevive a este cambio porque no habia ninguna mas.
--
-- La FK se suelta ANTES que la columna: con la restriccion viva el DROP COLUMN
-- falla. El indice `fk_perfiles_rol` se va solo junto con la columna.
--
-- Idempotente con el mismo patron `SET @s / PREPARE` de las migraciones de hoy
-- (`DROP ... IF EXISTS` no existe en MySQL 8, y dev corre MySQL mientras prod
-- corre MariaDB).
--
-- No usar `USE <db>` aca: la conexion PDO ya selecciona la DB del entorno via
-- DB_NAME (`reactor` en prod, `reactor_dev` en dev).

-- 1. La FK primero.
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perfiles' AND CONSTRAINT_NAME = 'fk_perfiles_rol' AND CONSTRAINT_TYPE = 'FOREIGN KEY'),
    'ALTER TABLE `perfiles` DROP FOREIGN KEY `fk_perfiles_rol`',
    'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. perfiles.rol
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perfiles' AND COLUMN_NAME = 'rol'),
    'ALTER TABLE `perfiles` DROP COLUMN `rol`',
    'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. perfiles.roles
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perfiles' AND COLUMN_NAME = 'roles'),
    'ALTER TABLE `perfiles` DROP COLUMN `roles`',
    'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
