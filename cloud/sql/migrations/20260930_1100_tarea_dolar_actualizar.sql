-- Dos cosas sobre el catalogo del Programador de tareas (`tareas`):
--
--   1. Da de alta la tarea que actualiza la cotizacion del dolar, todos los
--      dias a las 06:00, apuntando a `cloud/jobs/dolar_actualizar.php`.
--   2. Borra la tarea de prueba que apuntaba a `cloud/jobs/probar_timeout.php`,
--      script que se elimino del repo en el mismo cambio.
--
-- ------------------------------------------------------------------------
-- POR QUE HACE FALTA LA TAREA DEL DOLAR
-- ------------------------------------------------------------------------
--
-- `parametros`.`articulos.dolar.cotizacion` la movia un robot del sistema
-- historico (`reactor-api/robot/articulosActualizar.php`) QUE DEJO DE CORRER:
-- al 30/09/2026 produccion seguia en 1530.00 con `articulos.dolar.actualizado`
-- en 2026-09-05, veinticinco dias vieja. Con esa cotizacion se valorizan los
-- articulos importados, el cotizador de planes de `www` y la cotizacion que se
-- sella en cada comprobante, asi que el numero no es informativo: es plata.
--
-- El job nuevo escribe SOLO los dos parametros. El robot del legacy ademas
-- recalculaba la tabla `articulos` entera, y eso NO se replica a proposito:
-- en cloud recalcular es una accion con nombre que muestra los cuatro precios
-- y los contratos afectados antes de confirmar (DESIGN.md §15.2). Ver el
-- encabezado de `cloud/jobs/dolar_actualizar.php`.
--
-- `cron_expr` SE EVALUA EN HORA DE ARGENTINA, y eso recien es cierto desde
-- este cambio: `_scheduler.php` comparaba contra el reloj de PHP, que en el
-- contenedor es UTC, asi que `0 6 * * *` habria disparado a las 03:00. El
-- scheduler ahora fija `America/Argentina/Buenos_Aires` igual que la web
-- (`cloud/api/bootstrap.php`). Si esta migracion se aplicara sobre un deploy
-- que todavia no tiene ese fix, la tarea correria tres horas antes.
--
-- ------------------------------------------------------------------------
-- IDEMPOTENCIA
-- ------------------------------------------------------------------------
--
-- El alta va con `INSERT ... SELECT ... WHERE NOT EXISTS` y no con
-- `INSERT IGNORE`: `uq_tareas_nombre` haria que el IGNORE funcione, pero
-- tambien se tragaria en silencio cualquier otro error del INSERT. Y no es un
-- `REPLACE` ni un `ON DUPLICATE KEY UPDATE` porque el catalogo es un ABM: si
-- alguien ya le cambio la hora o el timeout desde el Programador, esa decision
-- es posterior a esta migracion y no se pisa.
--
-- El borrado es incondicional y se filtra por `script`, no por `nombre`: la
-- tarea de prueba se dio de alta a mano desde el ABM y el nombre con el que
-- quedo no esta versionado en ningun lado — el script al que apunta, si.

-- ---- 1) Alta de la tarea del dolar ----

INSERT INTO `tareas` (`nombre`, `descripcion`, `script`, `cron_expr`, `activo`, `overlap`, `timeout_seg`, `retencion_dias`)
SELECT
    'Cotización del dólar',
    'Trae la cotización de venta del microservicio de Databox y la guarda en parametros.articulos.dolar.cotizacion / .actualizado',
    'cloud/jobs/dolar_actualizar.php',
    '0 6 * * *',
    1,
    'skip',
    -- El GET tiene su propio timeout de 15 s y despues son dos UPDATE. 120 s es
    -- holgura para una Databox lenta, no una ventana para que el job se cuelgue.
    120,
    -- 30 y no los 7 del default: es una tarea diaria y el historial de
    -- ejecuciones es el unico lugar donde se ve como se vino moviendo la
    -- cotizacion. Con 7 dias se pierde la referencia del mes.
    30
-- `FROM DUAL` explicito: dev corre MySQL 8.0 y prod MariaDB 10.11, y el SELECT
-- sin tabla con WHERE no esta igual de aceptado en los dos. `DUAL` si.
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT `id` FROM `tareas` WHERE `script` = 'cloud/jobs/dolar_actualizar.php') AS `ya`
);

-- ---- 2) Baja de la tarea de prueba ----

-- Primero el historial: `tareas_ejecuciones`.`tarea_id` NO tiene FK, asi que no
-- hay cascada que lo arrastre y las filas quedarian huerfanas contando errores
-- en las KPIs del listado para siempre.
--
-- OJO: los `.log` de esas ejecuciones quedan en
-- /var/log/reactor/cloud/ejecuciones/<id>.log. SQL no los puede borrar; los
-- barre `_cleanup_logs.php`... salvo que sin la fila ya no sabe de ellos, asi
-- que si molestan se borran a mano. Son archivos de texto de una tarea que
-- dormia 60 segundos: no hay nada sensible adentro.
DELETE `e`
  FROM `tareas_ejecuciones` `e`
  JOIN `tareas` `t` ON `t`.`id` = `e`.`tarea_id`
 WHERE `t`.`script` = 'cloud/jobs/probar_timeout.php';

DELETE FROM `tareas` WHERE `script` = 'cloud/jobs/probar_timeout.php';
