-- Da de alta en el catalogo del Programador de tareas (`tareas`) el job que
-- emite el comprobante del abono de todos los contratos facturables, el DIA 1 DE
-- CADA MES A LAS 09:00, apuntando a `cloud/jobs/contratos_facturar.php`.
--
-- No crea ni modifica ninguna columna: el job escribe `comprobantes`,
-- `comprobantesrenglones`, `talonarios`.`serie` y las tres columnas del ciclo de
-- `contratos` (`facturado` / `facturar` / `remitir`), todas existentes desde el
-- sistema historico.
--
-- ------------------------------------------------------------------------
-- ES LA PRIMERA TAREA DEL REPO QUE EMITE COMPROBANTES
-- ------------------------------------------------------------------------
--
-- Las otras cuatro recalculan columnas derivadas y lo peor que pueden hacer es
-- escribir un numero equivocado, que la corrida siguiente corrige. Esta CREA
-- documentos con numeracion fiscal, los numera contra `talonarios`.`serie` y
-- deja al cliente con una deuda: nada de eso se deshace con volver a correrla.
-- De ahi salen las tres guardas que la acompañan y que estan explicadas en el
-- encabezado del job: el tope de comprobantes por corrida, el segundo chequeo de
-- "sigue siendo facturable" con la fila bloqueada, y el `.log` de 30 dias.
--
-- ------------------------------------------------------------------------
-- POR QUE EL 1 A LAS 09:00
-- ------------------------------------------------------------------------
--
-- EL DIA 1 porque es el dia en que cae `contratos`.`facturar`: el ABM del legacy
-- la pasa por `cTiempo::inicio($x, 'm')` y `facturarEmitir()` le suma un mes
-- exacto, asi que el ciclo entero de los 26 contratos habilitados esta parado en
-- el dia 1. Correrla otro dia no cambiaria el resultado -- el criterio es
-- `facturar <= CURDATE()`, no "facturar = hoy" -- pero retrasaria todos los
-- comprobantes del mes.
--
-- A LAS 09:00 porque es la COLA de la cadena diaria, y el orden no es cosmetico:
--
--   04:00  contratos_situacion_recalcular   la mora (fuera de la cadena)
--   06:00  dolar_actualizar                 escribe la cotizacion
--   07:00  articulos_recalcular             reprecia `articulos`.`venta`
--   08:00  contratos_plan_recalcular        reasigna `contratos`.`plan`
--   09:00  contratos_facturar               <-- esta
--
-- Facturar LEE las dos puntas de esa cadena (`contratos`.`plan` para saber que
-- plan cobrar y `articulos`.`venta` para saber cuanto) y no escribe ninguna.
-- Ponerla antes facturaria el mes nuevo con la lista de precios del mes anterior
-- y con el plan del tamaño que el dominio tenia ayer. Es plata.
--
-- `cron_expr` SE EVALUA EN HORA DE ARGENTINA: lo hace cierto el
-- `date_default_timezone_set()` de `_scheduler.php` (ver DESIGN.md §33). Sobre un
-- deploy que no tenga ese fix, `0 9 1 * *` dispararia a las 06:00 -- o sea en el
-- medio de la cadena, antes de que los precios y los planes se refresquen.
--
-- ------------------------------------------------------------------------
-- QUE PASA EL DIA QUE SE APLICA
-- ------------------------------------------------------------------------
--
-- NADA HASTA EL 1 DEL MES SIGUIENTE: la migracion da de alta la fila en el
-- catalogo y el scheduler no la dispara hasta que el cron coincida. Para correrla
-- antes esta `Ejecutar ahora` del Programador, que es el camino en el que alguien
-- la esta mirando.
--
-- CUANDO CORRA, en desarrollo al 01/10/2026, emitiria 24 comprobantes: los 24
-- contratos que hoy cumplen el filtro `Facturables` del listado, ninguno con
-- bloqueos (verificado uno por uno: los 24 tienen dominio, cliente, talonario,
-- plan y articulo, y ningun dato del cliente se pasa del largo de su columna).
-- Los 24 comparten UN talonario -- `Alfatec - Prefactura - X - 001`, serie 3352 --
-- asi que se llevarian las series 3353 a 3376.
--
-- Y VEINTITRES DE ESOS 24 SEGUIRIAN FACTURABLES despues de la corrida, porque
-- vienen atrasados varios periodos (18 con `facturar` en 2026-06-01, 4 en
-- 2026-07-01, 1 en 2026-09-01). El job emite UN periodo por contrato por corrida
-- y los lista uno por uno al cierre: ver `PERIODOS_POR_CORRIDA` en el job y
-- DESIGN.md §33-octies. En produccion el ciclo esta al dia -- el listado muestra
-- `facturar` = 1/10/2026 en casi todos -- asi que ahi la corrida los deja a todos
-- fuera del filtro, que es el comportamiento que se pidio.
--
-- ------------------------------------------------------------------------
-- IDEMPOTENCIA
-- ------------------------------------------------------------------------
--
-- Mismo criterio que las cuatro migraciones de tareas anteriores: `INSERT ...
-- SELECT ... WHERE NOT EXISTS` y no `INSERT IGNORE` (que se tragaria en silencio
-- cualquier otro error del INSERT), y no `REPLACE` ni `ON DUPLICATE KEY UPDATE`
-- porque el catalogo es un ABM: si alguien ya le cambio la hora, el timeout o el
-- estado desde el Programador, esa decision es posterior a esta migracion y no se
-- pisa.

INSERT INTO `tareas` (`nombre`, `descripcion`, `script`, `cron_expr`, `activo`, `overlap`, `timeout_seg`, `retencion_dias`)
SELECT
    'Facturación mensual de contratos',
    -- `tareas`.`descripcion` es varchar(255) y la base corre en modo estricto:
    -- un texto mas largo NO se recorta, revienta la migracion entera con
    -- "Data too long for column". La mas larga de las cuatro tareas ya cargadas
    -- mide 228.
    'Emite el comprobante del abono de los contratos facturables (habilitados y con facturar <= hoy, el criterio del atajo Facturables del listado). Adelanta facturado/facturar un mes y deja remitir en 1. Un período por contrato por corrida',
    'cloud/jobs/contratos_facturar.php',
    -- El 1 de cada mes a las 09:00: la cola de la cadena diaria (ver arriba).
    '0 9 1 * *',
    1,
    -- `skip` y no `allow`, y aca pesa mas que en las otras cuatro: dos corridas
    -- encimadas podrian emitirle DOS comprobantes al mismo contrato si la
    -- segunda leyera la lista antes de que la primera adelante `facturar`. El
    -- segundo chequeo con la fila bloqueada (`esFacturable()` dentro del
    -- `FOR UPDATE`) ya lo evita por su cuenta; esto es el candado de afuera.
    'skip',
    -- 300 s y no los 120 de las otras cuatro. No hay llamadas de red, pero cada
    -- comprobante son seis sentencias en su propia transaccion y el talonario se
    -- bloquea en cada una: 24 contratos hoy, y el tope del job son 200. El
    -- watchdog del scheduler mata al doble (600 s), y un `timeout` a mitad de
    -- corrida NO rompe nada -- lo ya emitido quedo commiteado y la corrida
    -- siguiente retoma por los que faltan.
    300,
    -- 30 y no los 7 del default, por el mismo motivo que las otras tareas que
    -- escriben plata, y aca con mas razon: el `.log` de cada ejecucion es el
    -- unico lugar donde queda escrito que un contrato quedo SIN facturar y por
    -- que. Los comprobantes emitidos si tienen rastro propio en `comprobantes`.
    30
-- `FROM DUAL` explicito: dev corre MySQL 8.0 y prod MariaDB 10.11, y el SELECT
-- sin tabla con WHERE no esta igual de aceptado en los dos. `DUAL` si.
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT `id` FROM `tareas` WHERE `script` = 'cloud/jobs/contratos_facturar.php') AS `ya`
);
