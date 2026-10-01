-- Da de alta en el catalogo del Programador de tareas (`tareas`) el job que
-- reasigna el plan de los contratos en modo dinamico, todos los dias a las
-- 08:00, apuntando a `cloud/jobs/contratos_plan_recalcular.php`.
--
-- ------------------------------------------------------------------------
-- POR QUE A LAS 08:00 Y NO A OTRA HORA
-- ------------------------------------------------------------------------
--
-- Tercera de la cadena diaria, UNA HORA DESPUES de `Recálculo de precios en
-- dólares` (`0 7 * * *`, migracion `20260930_1200`), que a su vez corre una hora
-- despues de `Cotización del dólar` (`0 6 * * *`, migracion `20260930_1100`):
--
--   06:00  dolar_actualizar          escribe parametros.articulos.dolar.*
--   07:00  articulos_recalcular      reprecia articulos.venta con esa cotizacion
--   08:00  contratos_plan_recalcular reasigna contratos.plan (abono = esa venta)
--
-- El orden no es cosmetico: el abono de un plan ES `articulos`.`venta` del
-- articulo al que apunta (`planes`.`articulo`), o sea el numero que la tarea de
-- las 07:00 acaba de mover. Corriendo antes, el `.log` de esta tarea informaria
-- el abono viejo del plan nuevo —el numero por el que alguien va a venir a
-- preguntar— y seria el del dia anterior.
--
-- Son tres tareas y no una por la misma razon que las dos primeras son dos: cada
-- una falla por su cuenta. Si Databox se cae, la cotizacion no se mueve, los
-- precios no cambian y esta tarea corre igual y reasigna planes con los precios
-- que haya — que es lo correcto, porque el plan que le corresponde a un dominio
-- depende de cuanta gente tiene, no de cuanto vale el dolar.
--
-- `cron_expr` SE EVALUA EN HORA DE ARGENTINA: lo hace cierto el
-- `date_default_timezone_set()` de `_scheduler.php` (ver DESIGN.md §33). Sobre
-- un deploy que no tenga ese fix, `0 8 * * *` dispararia a las 05:00.
--
-- ------------------------------------------------------------------------
-- QUE HABILITA ESTA TAREA, Y QUE NO
-- ------------------------------------------------------------------------
--
-- `contratos`.`plan_modo` existe desde la migracion `20260929_1100` y hasta esta
-- tarea NO HACIA NADA: se elegia en el ABM, se guardaba, se mostraba en el
-- listado y en la ficha, y ningun camino del sistema lo leia. Esta es la pieza
-- que le da efecto a `dinamico`.
--
-- NO CAMBIA NADA EL DIA QUE SE APLICA. Las 50 filas de `contratos` tienen
-- `plan_modo` = 'fijo' —el default del ENUM, elegido justamente para que un
-- ALTER no le cambiara el comportamiento a ningun contrato vivo—, asi que la
-- primera corrida sale `ok` con 0 contratos evaluados. El modo se elige contrato
-- por contrato desde el ABM, y es ahi donde alguien decide.
--
-- Esta verificado ademas que el primer dia tampoco moveria nada si se pasaran
-- los 26 contratos habilitados a `dinamico`: los 26 ya estan parados exactamente
-- en el plan que les corresponde por cantidad de usuarios.
--
-- ------------------------------------------------------------------------
-- REEMPLAZA UN ROBOT DEL LEGACY, PERO NO LO COPIA
-- ------------------------------------------------------------------------
--
-- `reactor-api/robot/contratosActualizar.php` (crontab del legacy, 05:45) hace
-- un `for` sobre `cContrato::actualizar()`, que llama a `cPlan::detectar()`. El
-- encabezado del job lista las cinco diferencias; las dos que mas pesan:
--
--   1. `detectar()` cierra con `if ($plan == 0) $plan = 100; // plan free`. El
--      dominio que se pasa del cupo mas grande del catalogo pasa a no pagar
--      nada, de madrugada. Aca se saltea y se reporta — la misma decision que
--      `api/contratos_accion.php` tomo al portar `cContrato::facturar()`, que
--      traia el mismo `if`.
--   2. `detectar()` no mira `planes`.`tipo`, y los OCHO planes Developer
--      habilitados tienen `usuarios` = 10 igual que el Standard Free: para un
--      dominio chico el `order by usuarios limit 1` empata nueve filas y gana la
--      que quiera el motor. El job acota los candidatos a la familia del plan
--      que el contrato ya tiene y saltea cualquier empate.
--
-- ------------------------------------------------------------------------
-- IDEMPOTENCIA
-- ------------------------------------------------------------------------
--
-- Mismo criterio que las dos migraciones de tareas anteriores: `INSERT ...
-- SELECT ... WHERE NOT EXISTS` y no `INSERT IGNORE` (que se tragaria en silencio
-- cualquier otro error del INSERT), y no `REPLACE` ni `ON DUPLICATE KEY UPDATE`
-- porque el catalogo es un ABM: si alguien ya le cambio la hora, el timeout o el
-- estado desde el Programador, esa decision es posterior a esta migracion y no
-- se pisa.

INSERT INTO `tareas` (`nombre`, `descripcion`, `script`, `cron_expr`, `activo`, `overlap`, `timeout_seg`, `retencion_dias`)
SELECT
    'Recálculo de planes dinámicos',
    'Reasigna contratos.plan en los contratos habilitados con plan_modo = dinamico: el plan habilitado más chico de la misma familia que cubra los usuarios del dominio',
    'cloud/jobs/contratos_plan_recalcular.php',
    -- Una hora despues del recálculo de precios (`0 7 * * *`), que es el que
    -- deja el abono de cada plan en su valor del dia.
    '0 8 * * *',
    1,
    -- `skip` y no `allow`: la corrida va entera en una transaccion con los
    -- contratos bloqueados (`FOR UPDATE`). Dos corridas encimadas se esperarian
    -- una a la otra para escribir exactamente lo mismo.
    'skip',
    -- Sin llamadas de red: es un SELECT de 50 filas, dos consultas por contrato
    -- y los UPDATE que cambian, todo en una transaccion. 120 s es la misma
    -- holgura que las otras dos tareas —un numero menos que razonar— y sigue
    -- siendo un techo, no una ventana para que el job se cuelgue: el watchdog
    -- del scheduler mata a los 240 s.
    120,
    -- 30 y no los 7 del default, por el mismo motivo que la tarea de precios:
    -- el `.log` de cada ejecucion es el UNICO lugar donde queda escrito que un
    -- contrato paso del plan X al plan Y, con que cantidad de usuarios y con que
    -- cambio de abono. `contratos` no guarda historial de planes.
    30
-- `FROM DUAL` explicito: dev corre MySQL 8.0 y prod MariaDB 10.11, y el SELECT
-- sin tabla con WHERE no esta igual de aceptado en los dos. `DUAL` si.
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT `id` FROM `tareas` WHERE `script` = 'cloud/jobs/contratos_plan_recalcular.php') AS `ya`
);
