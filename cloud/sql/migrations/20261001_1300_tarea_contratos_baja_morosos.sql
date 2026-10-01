-- Da de alta en el catalogo del Programador de tareas (`tareas`) el job que da
-- de baja los contratos con deuda vencida hace mas de seis meses, el DIA 15 DE
-- CADA MES A LAS 09:00, apuntando a `cloud/jobs/contratos_baja_morosos.php`.
--
-- No crea ni modifica ninguna columna. El job escribe cuatro columnas que ya
-- existen -- `contratos`.`habilitado` / `.baja` y `dominios`.`habilitado` /
-- `.situacion` -- a traves de `contratoBaja()` de `api/contratos_estado_lib.php`,
-- la misma funcion que usa la accion `Dar de baja` de la ficha del ABM.
--
-- ------------------------------------------------------------------------
-- ES LA PRIMERA TAREA DEL REPO QUE LE CORTA EL SERVICIO A UN CLIENTE
-- ------------------------------------------------------------------------
--
-- `contratos_situacion_recalcular.php` (04:00) tambien escribe
-- `dominios`.`situacion`, pero es un RECALCULO: si la deuda se paga, la corrida
-- siguiente devuelve el dominio a Normal sola. Esta no. Da de baja el contrato,
-- y un contrato dado de baja SALE del recorrido de aquella tarea --solo mira
-- habilitados-- asi que el Suspendido que deja esta corrida no lo pisa nadie:
-- revertirlo es que una persona entre a la ficha y le de `Dar de alta`.
--
-- De ahi salen las guardas que acompañan al job y que estan explicadas en su
-- encabezado: el tope de 10 bajas por corrida --que corta SIN TOCAR NADA, antes
-- de la primera transaccion--, la revalidacion de la deuda con la fila ya
-- bloqueada --para no cortarle el servicio a alguien que acaba de pagar--, el
-- `.log` de 30 dias que nombra uno por uno los dominios que se quedan sin
-- servicio y con que valores venian, y el suceso en `alerta`.
--
-- ------------------------------------------------------------------------
-- QUE CUENTA COMO DEUDA, Y POR QUE NO ES "CUALQUIER FACTURA VIEJA"
-- ------------------------------------------------------------------------
--
-- Prefactura o Factura (`talonarios`.`tipo` IN ('F','T')) en estado PENDIENTE
-- (`comprobantes`.`estado` = '2') con `vencimiento` anterior a
-- `CURDATE() - INTERVAL 6 MONTH`, sin los `NULL` y sin el centinela
-- '1500-01-01'. Es el mismo criterio de "deuda" que ya usa el job de las 04:00.
--
-- EL ESTADO PENDIENTE ES LO QUE HACE QUE LA TAREA TENGA SENTIDO. "Una factura
-- cuyo vencimiento es superior a seis meses" leido sin el estado alcanza
-- tambien a las facturas ya cobradas: en desarrollo eso son 24 de los 26
-- contratos habilitados, o sea casi todo el padron, la mayoria al dia. Con el
-- estado son 3.
--
-- ------------------------------------------------------------------------
-- POR QUE EL 15 A LAS 09:00
-- ------------------------------------------------------------------------
--
-- EL DIA 15 es la mitad del mes, lo mas lejos posible del dia 1, que es cuando
-- `contratos_facturar.php` emite el abono de todos los contratos facturables. Un
-- comprobante recien emitido nace sin vencer (7 dias), asi que no podria
-- disparar una baja ni corriendo el mismo dia: separarlas es para que la factura
-- del mes y el corte de servicio no le caigan juntos al mismo cliente en la
-- misma mañana.
--
-- A LAS 09:00 y no de madrugada a proposito: es horario de oficina, asi que si
-- la corrida corta el servicio de alguien hay gente mirando. No cuelga de la
-- cadena diaria (06:00 cotizacion -> 07:00 precios -> 08:00 planes) ni la
-- necesita: lee `comprobantes`.`estado` y `.vencimiento`, dos columnas que
-- ninguna de las tres escribe.
--
-- `cron_expr` SE EVALUA EN HORA DE ARGENTINA: lo hace cierto el
-- `date_default_timezone_set()` de `_scheduler.php` (ver DESIGN.md §33). Sobre
-- un deploy que no tenga ese fix, `0 9 15 * *` dispararia a las 06:00.
--
-- ------------------------------------------------------------------------
-- QUE PASA EL DIA QUE SE APLICA
-- ------------------------------------------------------------------------
--
-- NADA HASTA EL 15 DEL MES: la migracion da de alta la fila en el catalogo y el
-- scheduler no la dispara hasta que el cron coincida. Para correrla antes esta
-- `Ejecutar ahora` del Programador, que es el camino en el que alguien la esta
-- mirando.
--
-- CUANDO CORRA, en desarrollo al 01/10/2026, daria de baja 3 contratos
-- (verificado corriendo el job y restaurando despues):
--
--   #138  dominio #161 Barrio Lujuva 3        8 pendientes, la mas vieja 2025-08-10 (417 dias)
--   #158  dominio #234 iSay                   2 pendientes, la mas vieja 2026-02-08 (235 dias)
--   #156  dominio #266 Posada de los angeles  1 pendiente,  la mas vieja 2026-03-08 (207 dias)
--
-- Y NINGUNO DE LOS TRES PIERDE EL SERVICIO EN ESA CORRIDA: los tres dominios ya
-- estaban en Suspendido, puestos ahi por el job de las 04:00 hace meses (su
-- umbral son 30 dias de atraso y el de esta tarea son seis meses). Lo que cambia
-- es `habilitado` -- en dos de los tres, porque el dominio 266 ya estaba
-- apagado --. O sea que en la practica esta tarea ESCALA una suspension que ya
-- estaba, no estrena ninguna: el `.log` lo deja escrito renglon por renglon.
--
-- EL CONTRATO 143 NO SE TOCA Y SE REPORTA. Su deuda vencida --el Recibo 5652,
-- pendiente desde el 2023-04-18, 1.262 dias, $ 1.032,24-- es de un talonario de
-- tipo `R`, que no esta en `TIPOS_DEUDA`. Se deja afuera para no tener dos
-- definiciones de "deuda" conviviendo con el job de las 04:00, pero la corrida
-- lo lista en el `.log` y en el suceso para que la decision se pueda revisar con
-- el dato a la vista. Ver el encabezado del job.
--
-- ------------------------------------------------------------------------
-- IDEMPOTENCIA
-- ------------------------------------------------------------------------
--
-- Mismo criterio que las cinco migraciones de tareas anteriores: `INSERT ...
-- SELECT ... WHERE NOT EXISTS` y no `INSERT IGNORE` (que se tragaria en silencio
-- cualquier otro error del INSERT), y no `REPLACE` ni `ON DUPLICATE KEY UPDATE`
-- porque el catalogo es un ABM: si alguien ya le cambio la hora, el timeout o el
-- estado desde el Programador, esa decision es posterior a esta migracion y no
-- se pisa.

INSERT INTO `tareas` (`nombre`, `descripcion`, `script`, `cron_expr`, `activo`, `overlap`, `timeout_seg`, `retencion_dias`)
SELECT
    'Baja de contratos con deuda vencida',
    -- `tareas`.`descripcion` es varchar(255) y la base corre en modo estricto:
    -- un texto mas largo NO se recorta, revienta la migracion entera con
    -- "Data too long for column". Esta mide 239.
    'Da de baja los contratos con una prefactura o factura pendiente vencida hace mas de 6 meses: deshabilita el contrato con fecha de baja de hoy y deja su dominio deshabilitado y en situacion Suspendido. No da de alta nada: revertir es manual',
    'cloud/jobs/contratos_baja_morosos.php',
    -- El dia 15 de cada mes a las 09:00 (ver arriba).
    '0 9 15 * *',
    1,
    -- `skip` y no `allow`: dos corridas encimadas leerian la misma lista de
    -- candidatos antes de que la primera los deshabilite. El `FOR UPDATE` sobre
    -- el contrato y el chequeo de `contratoBaja()` ya lo evitan por su cuenta
    -- --la segunda se iria con "el contrato ya esta dado de baja"-- pero esto es
    -- el candado de afuera.
    'skip',
    -- 120 s alcanza y sobra: el tope del job son 10 contratos y cada baja son
    -- dos SELECT y dos UPDATE en su propia transaccion, sin llamadas de red. El
    -- watchdog del scheduler mata al doble (240 s), y un `timeout` a mitad de
    -- corrida NO rompe nada: lo ya dado de baja quedo commiteado y la corrida
    -- siguiente retoma por los que faltan.
    120,
    -- 30 y no los 7 del default, y aca es lo mas importante de la fila: el `.log`
    -- de cada ejecucion es EL UNICO LUGAR donde queda escrito que dominio quedo
    -- sin servicio y con que `habilitado` / `situacion` venia. `contratos`.`baja`
    -- guarda la fecha, pero no de que estado se vino -- revertir a mano es leer
    -- ese renglon.
    30
-- `FROM DUAL` explicito: dev corre MySQL 8.0 y prod MariaDB 10.11, y el SELECT
-- sin tabla con WHERE no esta igual de aceptado en los dos. `DUAL` si.
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT `id` FROM `tareas` WHERE `script` = 'cloud/jobs/contratos_baja_morosos.php') AS `ya`
);
