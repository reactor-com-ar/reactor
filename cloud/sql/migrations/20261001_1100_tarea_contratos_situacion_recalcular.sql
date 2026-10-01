-- Da de alta en el catalogo del Programador de tareas (`tareas`) el job que
-- recalcula la mora de los contratos, todos los dias a las 04:00, apuntando a
-- `cloud/jobs/contratos_situacion_recalcular.php`.
--
-- Depende de la migracion `20261001_1000`, que crea la columna que este job
-- escribe (`contratos`.`situacion`). Aplicada al reves, la primera corrida
-- fallaria con "Unknown column 'situacion'".
--
-- ------------------------------------------------------------------------
-- POR QUE A LAS 04:00, Y POR QUE NO ENTRA EN LA CADENA DE LAS 06/07/08
-- ------------------------------------------------------------------------
--
-- Las otras tres tareas diarias son una cadena y su orden es parte del diseño:
--
--   06:00  dolar_actualizar          escribe parametros.articulos.dolar.*
--   07:00  articulos_recalcular      reprecia articulos.venta con esa cotizacion
--   08:00  contratos_plan_recalcular reasigna contratos.plan (abono = esa venta)
--
-- Esta NO se engancha ni arriba ni abajo de esa cadena, y es a proposito: lee
-- `comprobantes`.`estado` y `.vencimiento` —dos columnas que ninguna de las tres
-- escribe— y escribe `contratos`.`situacion`, que ninguna de las tres lee.
-- Corriendo a las 04:00 o a las 09:00 el resultado es identico. Las 04:00 son
-- entonces la hora mas barata: la mas vacia del dia y la que deja la situacion
-- del dia ya calculada antes de que arranque cualquier otra cosa.
--
-- LO QUE SI IMPORTA ES QUE SEA DIARIA. La situacion de un contrato cambia por el
-- paso del tiempo —un comprobante cruza el dia 15 o el dia 30 sin que nadie toque
-- la base— asi que el valor envejece solo y no hay evento del sistema al que
-- colgarlo: emitir un comprobante nuevo no vence nada, y cobrarlo lo saca de
-- Pendiente pero la fila ya quedaria escrita con la mora de ayer hasta la corrida
-- siguiente.
--
-- `cron_expr` SE EVALUA EN HORA DE ARGENTINA: lo hace cierto el
-- `date_default_timezone_set()` de `_scheduler.php` (ver DESIGN.md §33). Sobre un
-- deploy que no tenga ese fix, `0 4 * * *` dispararia a las 01:00.
--
-- ------------------------------------------------------------------------
-- QUE CAMBIA EL DIA QUE SE APLICA: SE LLENAN LAS 26 FILAS, Y NO SE CORTA NADA
-- ------------------------------------------------------------------------
--
-- A diferencia de la tarea de los planes —que el primer dia sale `ok` con 0
-- contratos evaluados porque las 50 filas estan en `plan_modo` = 'fijo'— esta
-- ESCRIBE LOS 26 CONTRATOS HABILITADOS en su primera corrida, porque la columna
-- nace en `NULL` ("todavia no se calculo") y pasar de `NULL` a un codigo es un
-- cambio real. Verificado contra la base de desarrollo al 01/10/2026:
--
--   18 Normal  |  0 Limitado  |  8 Suspendido
--
-- Los ocho de Suspendido tienen la prefactura pendiente mas antigua vencida hace
-- 146, 146, 146, 146, 162, 207, 235 y 417 dias. La banda de Limitado sale vacia:
-- hoy no hay ningun contrato con un atraso de entre 15 y 29 dias.
--
-- **Y AUN ASI NO SE LE CORTA EL SERVICIO A NADIE**, porque ningun camino del
-- sistema lee todavia `contratos`.`situacion`. La columna que SI gatea servicio
-- es `dominios`.`situacion` (`app/index.php` apaga los controles con `=== '3'`),
-- es otra, y este job no la toca. Son dos numeros que conviene tener juntos
-- antes de que alguien decida conectarlas: esta regla deja 8 contratos en '3' y
-- hoy hay 4 dominios habilitados en '3'.
--
-- ------------------------------------------------------------------------
-- IDEMPOTENCIA
-- ------------------------------------------------------------------------
--
-- Mismo criterio que las tres migraciones de tareas anteriores: `INSERT ...
-- SELECT ... WHERE NOT EXISTS` y no `INSERT IGNORE` (que se tragaria en silencio
-- cualquier otro error del INSERT), y no `REPLACE` ni `ON DUPLICATE KEY UPDATE`
-- porque el catalogo es un ABM: si alguien ya le cambio la hora, el timeout o el
-- estado desde el Programador, esa decision es posterior a esta migracion y no se
-- pisa.

INSERT INTO `tareas` (`nombre`, `descripcion`, `script`, `cron_expr`, `activo`, `overlap`, `timeout_seg`, `retencion_dias`)
SELECT
    'Recálculo de situación de contratos',
    'Escribe contratos.situacion en los contratos habilitados (1 Normal / 2 Limitado / 3 Suspendido) según los días de atraso de la factura o prefactura pendiente más antigua: menos de 15 Normal, 15 a 29 Limitado, 30 o más Suspendido',
    'cloud/jobs/contratos_situacion_recalcular.php',
    -- Fuera de la cadena de las 06/07/08, que no comparte ni una columna con
    -- esta tarea (ver el encabezado).
    '0 4 * * *',
    1,
    -- `skip` y no `allow`: la corrida va entera en una transaccion con los
    -- contratos bloqueados (`FOR UPDATE`). Dos corridas encimadas se esperarian
    -- una a la otra para escribir exactamente lo mismo.
    'skip',
    -- Sin llamadas de red: un SELECT de 50 filas, dos consultas por contrato
    -- —la de la deuda cae sobre el indice `fk_comprobantes_contrato`— y los
    -- UPDATE que cambian, todo en una transaccion. 120 s es la misma holgura que
    -- las otras tres tareas (un numero menos que razonar) y sigue siendo un
    -- techo, no una ventana para que el job se cuelgue: el watchdog del
    -- scheduler mata a los 240 s.
    120,
    -- 30 y no los 7 del default, por el mismo motivo que las otras dos tareas que
    -- escriben plata: el `.log` de cada ejecucion es el UNICO lugar donde queda
    -- escrito que un contrato paso a Suspendido, con cuantos dias de atraso y
    -- sobre que comprobante. `contratos` no guarda historial de situaciones.
    30
-- `FROM DUAL` explicito: dev corre MySQL 8.0 y prod MariaDB 10.11, y el SELECT
-- sin tabla con WHERE no esta igual de aceptado en los dos. `DUAL` si.
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT `id` FROM `tareas` WHERE `script` = 'cloud/jobs/contratos_situacion_recalcular.php') AS `ya`
);
