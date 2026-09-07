-- `perfiles` suelta las dos ultimas listas del sistema historico.
--
-- COLUMNAS QUE SE VAN (2), las dos varchar(100) con el formato `(1003)(1002)`:
--
--   perfiles.permisos  lista de ids de `permisos`.
--   perfiles.paneles   lista de ids de `paneles`.
--
-- NINGUNA DE LAS DOS LA LEE NADIE EN ESTE REPO. Verificado con grep sobre
-- app/ panel/ cloud/ www/ api/ antes de escribir esto: los unicos hits son
-- comentarios. Ojo con no confundirlas al buscar -- `usuarios` tiene sus
-- propias columnas `perfiles`, `dominios`, `paneles` y `roles` (las escribe
-- `usuarios_alta.php`), y `perfiles`.`panel` EN SINGULAR es un int que SI se
-- usa: es el ultimo panel abierto y lo leen `app/lib/contexto.php` y
-- `app/api/paneles.php`. Esa se queda.
--
-- `permisos` ESTA 100% MUERTA. Sus 2519 pares (perfil, permiso) apuntan a solo
-- dos ids: 1002 y 1003, y NINGUNO DE LOS DOS EXISTE en `permisos`. Eran los
-- "Inicio" de Reactor Control y Reactor App, que quedaron duplicados al
-- eliminarse la columna `sistema` (`20260906_1300`) y se borraron despues desde
-- el ABM. No hay nada que migrar: la columna referencia filas que ya no estan.
--
--   perfiles con valor en `permisos` ... 2054 de 2227
--   pares totales .................... 2519
--   pares que resuelven a un permiso .. 0
--
-- `paneles` YA FUE REEMPLAZADA. La sustituyo `perfiles_paneles`
-- (`20260906_1600` + siembra en `20260906_1700`, 3598 filas), que es lo que lee
-- `app` hoy. El varchar tampoco servia de respaldo: de sus 2375 pares, 2368
-- apuntaban a un panel inexistente y 2 a un panel de otro dominio -- 5 validos
-- en total. Se midio antes de crear la tabla puente y por eso la siembra se
-- hizo desde `paneles`, no desde esta columna.
--
-- NO HAY NADA QUE PRESERVAR, entonces: no se copia a ningun lado antes de
-- soltar. Es la diferencia con `roles`.`permisos`, que si tenia 536 pares
-- validos y por eso `20260906_1300` los sembro en `roles_permisos` antes de
-- eliminar la columna.
--
-- Idempotente con el mismo patron `SET @s / PREPARE` del resto de las
-- migraciones de hoy: `DROP COLUMN IF EXISTS` no existe en MySQL 8, y dev corre
-- MySQL mientras prod corre MariaDB.
--
-- No usar `USE <db>` aca: la conexion PDO ya selecciona la DB del entorno via
-- DB_NAME (`reactor` en prod, `reactor_dev` en dev).

-- 1. perfiles.permisos
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perfiles' AND COLUMN_NAME = 'permisos'),
    'ALTER TABLE `perfiles` DROP COLUMN `permisos`',
    'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. perfiles.paneles  (la PLURAL en varchar; `panel` en singular se conserva)
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perfiles' AND COLUMN_NAME = 'paneles'),
    'ALTER TABLE `perfiles` DROP COLUMN `paneles`',
    'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
