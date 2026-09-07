-- `perfiles` gana TRES banderas de permiso: `operacion`, `invitacion` y
-- `facturacion`. Van entre `tipo` y `panel`, que es donde el perfil describe
-- QUE puede hacer ese acceso, antes de la memoria del ultimo panel abierto.
--
--   operacion    ver y usar los paneles de operacion en app.reactor.com.ar
--   invitacion   invitar usuarios nuevos desde app.reactor.com.ar
--   facturacion  ver y abonar las facturas del servicio en panel.reactor.com.ar
--
-- SON COLUMNAS `habilitado`, aunque no se llamen asi: `tinyint(1) NOT NULL
-- DEFAULT 0`, dos valores y nada mas (1 habilitado / 0 deshabilitado), y se
-- leen y escriben con `esHabilitado()` / `valorHabilitado()` de
-- `lib/habilitado.php`. Es la misma convencion que fijo
-- 20260905_2200_habilitado_tinyint_0_1.sql para la bandera del mismo nombre;
-- lo unico que cambia es que aca hay tres banderas por fila en vez de una.
--
-- POR QUE TRES COLUMNAS Y NO UNA TABLA PUENTE. Es la pregunta obvia teniendo
-- al lado `perfiles_paneles`, y la respuesta es que no son lo mismo: los
-- paneles son un catalogo abierto (162 filas hoy, una por panel de cada
-- dominio) y estos son tres permisos fijos del producto, cableados uno por uno
-- en el codigo de las tres apps. Una tabla puente para un catalogo de tres
-- filas que nadie da de alta agrega un JOIN a cada lectura del perfil —
-- incluida la del gate, que corre en CADA request — sin dar a cambio nada que
-- la columna no de.
--
-- NO SON `perfiles`.`permisos`. Esa columna era el varchar(100) del sistema
-- historico con el formato `(1003)(1007)` y la elimino
-- 20260906_1800_perfiles_sin_permisos_ni_paneles.sql. Estas tres nacen en este
-- repo, con su tipo y su significado escritos.
--
-- ------------------------------------------------------------------------
-- EL BACKFILL ES EL PUNTO DE LA MIGRACION, no un detalle
-- ------------------------------------------------------------------------
--
-- Con el `DEFAULT 0` a secas, las 2.227 filas existentes nacerian sin ningun
-- permiso: nadie podria operar la app, nadie podria invitar y —lo peor— nadie
-- podria OTORGAR facturacion, porque el permiso se otorga solo desde un perfil
-- que ya lo tiene (panel/api/usuarios.php). O sea que quedaria cerrado sin
-- forma de abrirlo. Por eso las filas que ya estan se siembran:
--
--   operacion   = 1 en TODAS. Hoy cualquier perfil habilitado opera la app;
--                 esta migracion no le saca eso a nadie.
--   invitacion  = 1 en TODAS.
--   facturacion = 1 si `tipo` = 'A', 0 si `tipo` = 'O'. Es el unico de los
--                 tres que arranca repartido, y sigue la linea que ya existe:
--                 al panel entra solo `tipo = 'A'` (panel/lib/acceso.php), asi
--                 que darselo a un Operador seria darle un permiso sobre una
--                 pantalla a la que no llega. `tipo` es ENUM('A','O') NOT NULL
--                 desde 20260905_2300, asi que la particion es total: no hay
--                 fila que quede afuera de las dos ramas.
--
-- EL BACKFILL CORRE UNA SOLA VEZ, y esa es toda la gracia de `@sembrar`: si la
-- migracion se reaplica sobre una base que ya la tiene, los UPDATE no corren y
-- no pisan lo que se haya editado a mano desde entonces. Sin ese candado,
-- reaplicarla le devolveria `operacion = 1` a todo perfil al que se lo hubieran
-- quitado — que es exactamente el tipo de "idempotencia" que borra decisiones.
--
-- LAS FILAS NUEVAS NACEN EN 0 igual, y esta bien: quien crea perfiles decide
-- sus permisos (cloud/api/profiles.php los manda explicitos en el alta). El
-- default cubre al que se olvide, y el criterio del que se olvida es el mismo
-- de `habilitado` — sin permiso.
--
-- IDEMPOTENTE con el patron `SET @s / PREPARE` del resto de las migraciones de
-- este repo: `ADD COLUMN IF NOT EXISTS` no existe en MySQL 8 (dev) aunque si en
-- MariaDB 10.11 (prod), asi que se pregunta a information_schema.
--
-- Sin `USE <base>`: la conexion PDO ya selecciona la del entorno (CLAUDE.md).


-- 0. ¿Hay que sembrar? Se responde ANTES de crear las columnas: despues de los
--    ALTER la pregunta ya no se puede hacer. 1 = la migracion corre por primera
--    vez sobre esta base.
SET @sembrar = (SELECT IF(EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perfiles' AND COLUMN_NAME = 'operacion'
), 0, 1));

-- 1. Las tres columnas, en orden y cada una detras de la anterior, para que
--    queden entre `tipo` y `panel`.
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perfiles' AND COLUMN_NAME = 'operacion'),
    'DO 0',
    'ALTER TABLE `perfiles` ADD COLUMN `operacion` tinyint(1) NOT NULL DEFAULT 0 COMMENT ''1 = puede usar los paneles de operacion en app'' AFTER `tipo`'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perfiles' AND COLUMN_NAME = 'invitacion'),
    'DO 0',
    'ALTER TABLE `perfiles` ADD COLUMN `invitacion` tinyint(1) NOT NULL DEFAULT 0 COMMENT ''1 = puede invitar usuarios desde app'' AFTER `operacion`'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perfiles' AND COLUMN_NAME = 'facturacion'),
    'DO 0',
    'ALTER TABLE `perfiles` ADD COLUMN `facturacion` tinyint(1) NOT NULL DEFAULT 0 COMMENT ''1 = puede ver y abonar las facturas en panel'' AFTER `invitacion`'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. Siembra de las filas que ya existian. Solo en la primera corrida.
--    El perfil centinela `id = 0` que creo 20260814_3100 entra en el UPDATE
--    como cualquier otro: es `tipo = 'O'`, asi que queda con operacion e
--    invitacion en 1 y facturacion en 0. No importa — no es de nadie y ninguna
--    sesion se para sobre el; excluirlo seria una excepcion sin consecuencia.
SET @s = (SELECT IF(@sembrar = 1,
    'UPDATE `perfiles` SET `operacion` = 1, `invitacion` = 1',
    'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(@sembrar = 1,
    'UPDATE `perfiles` SET `facturacion` = IF(`tipo` = ''A'', 1, 0)',
    'DO 0'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
