-- Da de alta en el catalogo del Programador de tareas (`tareas`) el job que
-- reprecia los articulos en dolares, todos los dias a las 07:00, apuntando a
-- `cloud/jobs/articulos_recalcular.php`.
--
-- ------------------------------------------------------------------------
-- POR QUE A LAS 07:00 Y NO A OTRA HORA
-- ------------------------------------------------------------------------
--
-- UNA HORA DESPUES de `Cotización del dólar` (`0 6 * * *`, migracion
-- `20260930_1100`), que es la tarea que escribe el parametro
-- `articulos.dolar.cotizacion` con el que este job reprecia. El orden no es
-- cosmetico: invertido, el recalculo de cada dia aplicaria la cotizacion del
-- dia anterior y el numero nuevo recien llegaria a los precios 24 horas mas
-- tarde. La hora de separacion es holgura para una Databox lenta — el GET de la
-- otra tarea tiene 15 s de timeout y 120 s de ventana.
--
-- Son dos tareas y no una a proposito: si Databox se cae, la cotizacion no se
-- mueve, `dolar_actualizar` queda en `error` y el recalculo de las 07:00 corre
-- igual y sale `ok` con 0 filas tocadas (no hay nada que mover). Fusionadas, un
-- fallo de red del microservicio dejaria sin correr el recalculo, que no
-- depende de la red.
--
-- `cron_expr` SE EVALUA EN HORA DE ARGENTINA: lo hace cierto el
-- `date_default_timezone_set()` de `_scheduler.php` (ver DESIGN.md §33). Sobre
-- un deploy que no tenga ese fix, `0 7 * * *` dispararia a las 04:00.
--
-- ------------------------------------------------------------------------
-- QUE CAMBIA DE LO QUE EL REPO DOCUMENTABA
-- ------------------------------------------------------------------------
--
-- Hasta este cambio repreciar en masa NO existia en cloud a proposito: era solo
-- la accion de fila `api/articulos_accion.php?accion=recalcular`, con
-- confirmacion, porque mueve el abono de contratos vivos. El argumento sigue en
-- pie —de ahi los bloqueos y el rastro fila por fila del job— pero la decision
-- es la contraria: dejar los precios clavados hasta que alguien se acuerde de
-- tocar la fila tambien es una decision de plata, tomada por omision. Al
-- 30/09/2026 convivian CINCO cotizaciones implicitas en la tabla (1370, 1375,
-- 1380, 1415, 1450) contra un parametro en 1540, con 70 de las 79 filas en
-- dolares vendiendo a una cotizacion vieja.
--
-- OJO CON LA PRIMERA CORRIDA: esas 70 filas se mueven de golpe, entre +6 % y
-- +12 %, y 31 planes con 50 contratos vivos facturan alguno de esos articulos.
-- El .log de la ejecucion lista fila por fila el antes y el despues, y el
-- resumen va al Visor de sucesos (`cron/articulos_recalcular`).
--
-- ------------------------------------------------------------------------
-- IDEMPOTENCIA
-- ------------------------------------------------------------------------
--
-- Mismo criterio que la migracion de la tarea del dolar: `INSERT ... SELECT ...
-- WHERE NOT EXISTS` y no `INSERT IGNORE` (que se tragaria en silencio cualquier
-- otro error del INSERT), y no `REPLACE` ni `ON DUPLICATE KEY UPDATE` porque el
-- catalogo es un ABM: si alguien ya le cambio la hora, el timeout o el estado
-- desde el Programador, esa decision es posterior a esta migracion y no se pisa.

INSERT INTO `tareas` (`nombre`, `descripcion`, `script`, `cron_expr`, `activo`, `overlap`, `timeout_seg`, `retencion_dias`)
SELECT
    'Recálculo de precios en dólares',
    'Reprecia los artículos con moneda Dólar con la cotización vigente de parametros.articulos.dolar.cotizacion (compra = importación × cotización, venta = compra + margen)',
    'cloud/jobs/articulos_recalcular.php',
    -- Una hora despues de la tarea del dolar (`0 6 * * *`).
    '0 7 * * *',
    1,
    -- `skip` y no `allow`: la corrida va entera en una transaccion con las filas
    -- en dolares bloqueadas (`FOR UPDATE`). Dos corridas encimadas se esperarian
    -- una a la otra para escribir exactamente lo mismo.
    'skip',
    -- Sin llamadas de red: son un SELECT de ~80 filas y los UPDATE que cambian,
    -- todo en una transaccion. 120 s es la misma holgura que la tarea del dolar
    -- —un numero menos que razonar— y sigue siendo un techo, no una ventana para
    -- que el job se cuelgue: el watchdog del scheduler mata a los 240 s.
    120,
    -- 30 y no los 7 del default, por el mismo motivo que la tarea del dolar y
    -- con mas razon: el .log de cada ejecucion es el UNICO lugar donde queda
    -- escrito que el precio de un articulo paso de X a Y y cuando. `articulos`
    -- no guarda historial de precios.
    30
-- `FROM DUAL` explicito: dev corre MySQL 8.0 y prod MariaDB 10.11, y el SELECT
-- sin tabla con WHERE no esta igual de aceptado en los dos. `DUAL` si.
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT `id` FROM `tareas` WHERE `script` = 'cloud/jobs/articulos_recalcular.php') AS `ya`
);
