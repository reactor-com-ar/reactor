<?php

declare(strict_types=1);

/**
 * Recalcula `contratos`.`situacion` —la mora— de los contratos habilitados.
 *
 * Recorre `contratos` WHERE `habilitado` = 1, cuenta sus facturas/prefacturas en
 * estado Pendiente, se queda con el VENCIMIENTO DE LA MAS ANTIGUA y de ahi sale
 * la situacion:
 *
 *   atraso < 15 dias   -> '1' Normal
 *   15 a 29 dias       -> '2' Limitado
 *   30 dias o mas      -> '3' Suspendido
 *
 * Sin pendientes, o con la mas antigua todavia sin vencer, es '1' Normal.
 *
 * Escribe DOS columnas y las dos en la misma transaccion:
 *
 *   `contratos`.`situacion`  la del contrato, una fila por contrato habilitado
 *   `dominios`.`situacion`   la del dominio, que es LA QUE CORTA EL SERVICIO
 *
 * Corre todos los dias a las 04:00 por el Programador de tareas (migracion
 * `20261001_1100`).
 *
 * ------------------------------------------------------------------------
 * LO QUE ESCRIBE EN `dominios` LE APAGA LA APP A UN CLIENTE
 * ------------------------------------------------------------------------
 *
 * `app/index.php` lee `dominios`.`situacion` y con '3' NO DIBUJA NINGUN CONTROL
 * de operacion; con '2' agrega el aviso "su cuenta pronto sera suspendida" y
 * deja los controles. `contratos`.`situacion` no la lee nadie: es el detalle por
 * contrato y el rastro de por que el dominio quedo como quedo.
 *
 * HASTA EL 01/10/2026 ESTE JOB NO TOCABA `dominios` A PROPOSITO, y la decision
 * se revirtio por pedido explicito. El argumento de entonces —"suspender
 * clientes desde un job es una decision de negocio, no de un recalculo"— sigue
 * siendo la razon de todo lo que hay abajo: el `.log` nombra uno por uno los
 * dominios que se quedan sin servicio y con que valor venian, el suceso sube a
 * `alerta` cuando pasa, y la escritura es condicional para que una segunda
 * corrida no vuelva a anunciar lo mismo. Pero escribir las dos columnas es
 * ahora lo correcto: con una sola, el contrato quedaba en Suspendido y el
 * dominio operando — un estado que decia dos cosas distintas sobre el mismo
 * cliente y que no servia para nada.
 *
 * SI UN DOMINIO TIENE VARIOS CONTRATOS HABILITADOS GANA LA PEOR SITUACION
 * (`peorSituacion()`). Hoy no hay ninguno asi, pero nada del esquema lo impide:
 * con un contrato impago el cliente NO esta al dia por mas que el otro si, y
 * quedarse con la mejor dejaria operando a quien debe plata con solo abrirle un
 * contrato nuevo al lado.
 *
 * ------------------------------------------------------------------------
 * LAS 04:00 NO CUELGAN DE LA CADENA DE LAS 06:00 / 07:00 / 08:00
 * ------------------------------------------------------------------------
 *
 * Las otras tres tareas diarias son una cadena y su orden importa: cotizacion ->
 * precios -> planes, porque cada una lee lo que la anterior escribio. Esta no
 * entra en esa cadena ni por arriba ni por abajo: lee `comprobantes`.`estado` y
 * `.vencimiento`, dos columnas que ninguna de las tres toca, y escribe una que
 * ninguna de las tres lee. Correr antes o despues da el mismo resultado.
 *
 * Lo que SI importa es que es diaria y temprano: la situacion de un contrato
 * cambia por el paso del tiempo —un comprobante cruza el dia 15 o el 30 sin que
 * nadie toque la base— asi que el valor envejece solo. No hay nada que recalcular
 * "al facturar": emitir un comprobante nuevo no vence nada.
 *
 * ------------------------------------------------------------------------
 * QUE CUENTA COMO DEUDA: PENDIENTE (`estado` = '2') Y TALONARIO `F` o `T`
 * ------------------------------------------------------------------------
 *
 * Los dos filtros son del pedido ("facturas/prefacturas en estado pendiente") y
 * los dos tienen una lectura que los sostiene:
 *
 * - `comprobantes`.`estado` = '2' es Pendiente, de los cuatro valores que declara
 *   `api/comprobantes_lib.php` ('0' Anulado, '1' Preparacion, '2' Pendiente,
 *   '3' Cancelado). SE COMPARA COMO STRING porque la columna es varchar(1) y '0'
 *   es un valor real: con un cast a int de por medio, Anulado y "sin estado" se
 *   confunden. Preparacion queda afuera a proposito: ese comprobante todavia no
 *   se autorizo —tiene `serie` = 0, o sea que no tiene numero— asi que no es una
 *   deuda que nadie pueda reclamar. Anulado y Cancelado tampoco: uno no existe y
 *   el otro ya se cobro.
 * - `talonarios`.`tipo` IN ('F','T') son Prefactura y Factura, los dos tipos que
 *   documentan una deuda. Los otros cinco del combo `'$xTalonario->tipo'` —`P`
 *   Presupuesto, `D` Pedido, `R` Recibo, `M` Remito, `N` Nota de Credito— no la
 *   documentan, y el filtro los saca a todos. Sin el filtro la cuenta seria otra
 *   y no por poco: de los 209 comprobantes pendientes de la base, 72 son
 *   Presupuestos y 16 Remitos — documentos que no se cobran.
 *
 * EL CASO INCOMODO ES RECIBO (`R`), Y QUEDA AFUERA A SABIENDAS. DESIGN.md
 * §33-quater.1 dice que facturar un contrato sella cotizacion "para cualquier
 * tipo" porque "hay clientes cuyo talonario de facturacion es de Recibo", y lo
 * respaldan 959 Recibos CON `contrato` ya cancelados. O sea que para esos
 * clientes el documento de la deuda es un Recibo y este job no lo esta mirando.
 * Se implementa igual como lo pide el enunciado, y esta VERIFICADO que hoy no
 * cambia ni un resultado: el unico Recibo pendiente con contrato es el 5652 del
 * contrato 143, que ya cae en '3' por sus prefacturas (162 dias) antes de mirarlo
 * (con el Recibo serian 1262). Si manana se decide contar Recibos, el cambio es
 * agregar 'R' a `TIPOS_DEUDA` y nada mas — por eso es una constante y no una
 * lista escrita adentro del SQL.
 *
 * OJO: ESE MISMO CRITERIO DE "DEUDA" ESTA ESCRITO DOS VECES. `ESTADO_PENDIENTE`
 * y `TIPOS_DEUDA` se redeclaran igual en `contratos_baja_morosos.php`, la tarea
 * del dia 15 que da de baja los contratos con deuda vencida hace mas de seis
 * meses — ninguno de los dos jobs puede incluir `api/comprobantes_lib.php` desde
 * CLI. LOS DOS TIENEN QUE MOVERSE JUNTOS: con uno solo, un dominio quedaria
 * Suspendido por un comprobante que el otro job no considera deuda, o de baja
 * sin haber pasado nunca por Suspendido. Y ahi Recibo SI cambia un resultado (el
 * contrato 143 entra o no entra), asi que es el lugar donde esta decision se ve.
 *
 * ------------------------------------------------------------------------
 * LOS BORDES DE LAS BANDAS: CADA UNA SE QUEDA CON SU LIMITE INFERIOR
 * ------------------------------------------------------------------------
 *
 * El enunciado da las bandas solapadas ("0 a 15", "15 a 30", "30 o mas"), asi que
 * los dias 15 y 30 estan en dos a la vez y hay que elegir. Se resuelve por el
 * unico borde que el enunciado SI define solo: "30 dias o mas" es Suspendido, o
 * sea que el 30 es de la banda de arriba. Por simetria el 15 tambien:
 *
 *   dia 14 -> '1'   dia 15 -> '2'   dia 29 -> '2'   dia 30 -> '3'
 *
 * Son dos comparaciones `>=` contra `UMBRAL_LIMITADO` y `UMBRAL_SUSPENDIDO`, en
 * ese orden, y no un `switch` de rangos: asi no hay forma de escribir un hueco
 * entre dos bandas.
 *
 * ------------------------------------------------------------------------
 * EL ATRASO LO CUENTA LA BASE, NO PHP
 * ------------------------------------------------------------------------
 *
 * `DATEDIFF(CURDATE(), MIN(vencimiento))` sobre la misma conexion que fija
 * `SET time_zone = '-03:00'`, igual que el resto del repo: el contenedor corre en
 * UTC y a las 04:00 de Argentina alla ya son las 07:00 del MISMO dia, pero el
 * criterio es el de siempre —las fechas se comparan contra el reloj de la base y
 * nunca contra el de PHP— porque son dos relojes que pueden separarse y el
 * resultado decide si un cliente queda suspendido.
 *
 * UN VENCIMIENTO FUTURO DA ATRASO NEGATIVO Y ESO ES '1' Normal, no un error: el
 * comprobante esta emitido y pendiente, pero todavia no vencio. Hoy es el caso
 * del contrato 154 (vence el 2026-10-07, seis dias adelante).
 *
 * ------------------------------------------------------------------------
 * LO QUE NO SE PUEDE FECHAR NO ENTRA EN EL `MIN()`, Y SE REPORTA
 * ------------------------------------------------------------------------
 *
 * Dos valores de `vencimiento` no son fechas utiles y los dos se excluyen en el
 * `WHERE`, no despues:
 *
 * - `NULL`: `MIN()` ya los ignora, pero entonces un contrato cuyos pendientes
 *   fueran TODOS sin fecha pareceria no tener deuda. Se cuentan aparte y se
 *   avisan.
 * - EL CENTINELA `'1500-01-01'` (`cTiempo::genesis()` del legacy, el "sin fecha"
 *   que este repo ya normaliza en media docena de endpoints). Es el que de verdad
 *   muerde: sin excluirlo, `MIN()` se queda con el y `DATEDIFF` da ~190.000 dias,
 *   o sea SUSPENDIDO para cualquier contrato que tenga uno. Hoy no hay ninguno
 *   entre los pendientes de tipo F/T (verificado, 0 filas), asi que esta guarda
 *   no desvia nada: existe para el dia que alguien guarde un comprobante sin
 *   fecha de vencimiento.
 *
 * ------------------------------------------------------------------------
 * QUE NO HACE
 * ------------------------------------------------------------------------
 *
 * - NO TOCA EL DOMINIO DE UN CONTRATO DESHABILITADO, ni ningun dominio que no
 *   tenga al menos un contrato habilitado. El job solo escribe sobre lo que
 *   calculo: un dominio sin contrato vivo no tiene mora que mirar, y pisarle la
 *   `situacion` seria opinar sobre un cliente del que este job no sabe nada. Su
 *   valor queda como esta — puesto a mano, por el back office viejo o por las
 *   acciones `baja` / `alta` de `api/contratos_accion.php`, que escriben las
 *   mismas dos columnas de `dominios` que este bloque. Eso es lo que hace que
 *   una baja se sostenga sola: el contrato dado de baja sale de este recorrido
 *   y su dominio se queda sin ninguno habilitado. Un alta, en cambio, vuelve a
 *   meter el contrato aca, asi que el 'Normal' que escribio la accion lo revisa
 *   esta corrida contra la deuda real.
 * - NO LEE `contratos`.`tolerancia`, la fecha de gracia que se carga a mano en el
 *   ABM. Es la unica columna del contrato que podria pisar este calculo y queda
 *   afuera porque el enunciado no la menciona y porque nada en este repo la lee
 *   todavia: engancharla aca seria inventar una regla de perdon que nadie pidio,
 *   y perdonar mora es plata. Los 26 contratos habilitados tienen una cargada
 *   (14 con el centinela '1500-01-01'), asi que si algun dia entra al calculo hay
 *   que decidir primero que significa el centinela.
 * - NO factura, no anula, no cancela: no escribe una sola columna de
 *   `comprobantes`. El ciclo de facturacion es de `api/contratos_facturar_lib.php`,
 *   que comparten la accion de la ficha y la tarea `contratos_facturar.php`.
 * - NO filtra por `dominios`.`habilitado`, igual que el job de los planes: lo que
 *   se factura es el contrato, y hay contratos habilitados con el dominio
 *   apagado (el 156).
 * - NO toca los contratos deshabilitados (24 de las 50 filas). Un contrato dado
 *   de baja no tiene mora que administrar, y su `situacion` se queda con el
 *   ultimo valor que tuvo —o en `NULL` si nunca se calculo—, que es el dato
 *   historico y no una afirmacion sobre hoy.
 */

require_once __DIR__ . '/_bootstrap.php';

/**
 * EL CALCULO DE LA MORA NO VIVE ACA: vive en `api/contratos_situacion_lib.php`.
 *
 * De ahi salen las bandas (`UMBRAL_LIMITADO` / `UMBRAL_SUSPENDIDO`), que cuenta
 * como deuda (`DEUDA_ESTADO_PENDIENTE` / `DEUDA_TIPOS` / `DEUDA_SIN_FECHA`), la
 * consulta que la resuelve (`deudaDeContrato()`) y las dos reglas que la
 * traducen (`situacionPorAtraso()` y `peorSituacion()`). Los codigos de
 * situacion y sus textos llegan por arrastre, de `contratos_estado_lib.php` --
 * las mismas constantes con las que `Dar de baja` escribe el '3'.
 *
 * ESTAN EN UN LIB PORQUE ESTA CUENTA LA HACEN TRES CAMINOS: este barrido diario,
 * el `Actualizar situacion` de la ficha de Contratos
 * (`api/contratos_accion.php?accion=situacion`) y el de la ficha de Dominios
 * (`api/dominios_accion.php?accion=situacion`). Con una copia por camino, la
 * pantalla diria una situacion y este job escribiria otra a las 04:00 de la
 * mañana siguiente -- y esa situacion decide si un cliente ve o no los controles
 * de la app.
 *
 * El lib no habla HTTP, no usa `db()` y no escribe logs: recibe el PDO por
 * parametro y la transaccion la abre el llamador. Es lo que lo hace incluible
 * desde CLI sin tener que declarar nada, a diferencia de
 * `contratos_facturar_lib.php`.
 */
require_once __DIR__ . '/../api/contratos_situacion_lib.php';

// El bootstrap carga este helper solo en el camino de error. Se requiere aca
// porque el job tambien deja rastro cuando sale bien: mover la situacion de un
// contrato es decir que un cliente esta en mora, y eso tiene que poder ubicarse
// en el tiempo desde el Visor de sucesos sin ir a buscar el .log de la ejecucion.
$_sucesos = __DIR__ . '/../api/lib/sucesos.php';
if (is_file($_sucesos)) require_once $_sucesos;

try {
    $pdo = _jobsPdo();

    $cambiados = [];   // [id, dominio, situacion vieja, situacion nueva, dias, pendientes]
    $avisos    = [];
    $iguales   = 0;
    $conteo    = [SITUACION_NORMAL => 0, SITUACION_LIMITADO => 0, SITUACION_SUSPENDIDO => 0];

    // La situacion que le corresponde a cada dominio, acumulada mientras se
    // recorren los contratos: [dominio => ['situacion' => '1'|'2'|'3',
    // 'nombre' => ..., 'contratos' => [ids]]]. Ver "LA SITUACION DEL DOMINIO".
    $porDominio = [];

    // TODO EN UNA SOLA TRANSACCION, con los contratos bloqueados. Mismo criterio
    // que `contratos_plan_recalcular.php`: el lock serializa esto contra
    // `accion=facturar`, que abre con `SELECT * FROM contratos WHERE id = :id FOR
    // UPDATE`. Sin el, una facturacion en curso podria insertar su comprobante
    // entre el conteo y el UPDATE de esta corrida — y aunque el comprobante nuevo
    // nace sin vencer (no cambiaria la banda), el contrato quedaria escrito por
    // dos caminos a la vez sobre la misma fila.
    $pdo->beginTransaction();

    // Sin joins a proposito: en MySQL un `FOR UPDATE` sobre un `LEFT JOIN`
    // bloquea tambien las filas de las tablas unidas, que este job solo lee.
    // `habilitado` no tiene indice, asi que esto bloquea el scan de las 50 filas;
    // a esta escala y a las 04:00 no es un problema.
    $stmt = $pdo->prepare(
        'SELECT id, dominio, situacion
           FROM contratos
          WHERE habilitado = 1
          ORDER BY id
          FOR UPDATE'
    );
    $stmt->execute();
    $contratos = $stmt->fetchAll();
    $total     = count($contratos);

    anotarLog(sprintf('%d contratos habilitados', $total));

    // El dominio es solo para el log: el calculo no lo usa para nada.
    $ctx = $pdo->prepare('SELECT id, nombre FROM dominios WHERE id = :id');

    $upd = $pdo->prepare('UPDATE contratos SET situacion = :situacion WHERE id = :id');

    foreach ($contratos as $con) {
        $id = (int) $con['id'];

        // La deuda la resuelve el lib en una sola fila: la misma consulta y los
        // mismos filtros que usan los dos `Actualizar situacion` de las fichas.
        // Los tres contadores no deciden nada, se informan — `pendientes_otros`
        // esta para que el .log pueda explicar por que un contrato CON
        // comprobantes pendientes sale Normal.
        $d = deudaDeContrato($pdo, $id);

        $pendientes = $d['pendientes'];
        $sinFecha   = $d['sin_fecha'];
        $otros      = $d['pendientes_otros'];
        $dias       = $d['dias'];
        $vencMin    = $d['venc_min'];
        $nueva      = $d['situacion'];

        $ctx->execute([':id' => (int) $con['dominio']]);
        $dom     = $ctx->fetch() ?: [];
        $dominio = $dom === []
            ? 'sin dominio'
            : sprintf('dominio #%d %s', (int) $dom['id'], textoNombre($dom['nombre']));

        // Una pendiente sin fecha utilizable no se puede fechar, asi que no entra
        // al `MIN()`: el contrato puede salir Normal teniendo deuda real. No
        // corta la corrida —el resto del calculo es valido— pero se avisa.
        if ($sinFecha > 0) {
            $avisos[] = sprintf(
                '#%d: %d pendiente(s) sin vencimiento usable (NULL o el centinela %s) que no entran al calculo%s',
                $id, $sinFecha, FECHA_GENESIS,
                $pendientes === 0 ? ' — el contrato queda Normal por falta de fecha, no por falta de deuda' : ''
            );
        }

        $vieja = $con['situacion'] === null ? null : trim((string) $con['situacion']);
        $conteo[$nueva]++;

        // SE ACUMULA ACA, ANTES DEL `continue` DE "sin cambios". El dominio
        // necesita la situacion de TODOS sus contratos habilitados, cambie o no
        // su propia columna: si esto viviera despues del `continue`, un contrato
        // que ya estaba en '3' no contaria y el dominio de al lado bajaria a
        // Normal — justo al reves de lo que corresponde.
        if ($dom !== []) {
            $domId = (int) $dom['id'];
            $porDominio[$domId] ??= [
                'situacion' => SITUACION_NORMAL,
                'nombre'    => textoNombre($dom['nombre']),
                'contratos' => [],
            ];
            $porDominio[$domId]['contratos'][] = $id;
            $porDominio[$domId]['situacion']   = peorSituacion($porDominio[$domId]['situacion'], $nueva);
        }

        $detalle = sprintf(
            '%d pendiente(s)%s%s',
            $pendientes,
            $vencMin === null ? '' : sprintf(', la mas antigua vence %s (%s)', $vencMin, textoAtraso($dias)),
            $otros > 0 ? sprintf(' [+%d pendiente(s) de otros tipos de talonario, que no son deuda]', $otros) : ''
        );

        // Lo que no cambia no se escribe, igual que los otros dos jobs: asi el
        // .log habla solo de las situaciones que de verdad se movieron y una
        // segunda corrida del mismo dia es un no-op verificable. El `NULL`
        // inicial SI cuenta como cambio: pasar de "no se sabe" a '1' es escribir
        // un dato que antes no estaba.
        if ($vieja === $nueva) {
            $iguales++;
            anotarLog(sprintf(
                '  #%d %s | %s | %s sin cambios',
                $id, $dominio, $detalle, situacionTexto($nueva)
            ));
            continue;
        }

        $upd->execute([':situacion' => $nueva, ':id' => $id]);

        $cambiados[] = [$id, $dominio, $vieja, $nueva, $dias, $pendientes];

        anotarLog(sprintf(
            '  #%d %s | %s | %s -> %s',
            $id, $dominio, $detalle, situacionTexto($vieja), situacionTexto($nueva)
        ));
    }

    // ------------------------------------------------------------------------
    // LA SITUACION DEL DOMINIO: ES LA QUE CORTA EL SERVICIO
    // ------------------------------------------------------------------------
    //
    // `dominios`.`situacion` es la columna que lee `app/index.php`: con '3' el
    // dominio queda SIN NINGUN CONTROL de operacion y con '2' se dibuja el aviso
    // de "pronto sera suspendida" pero los controles siguen. O sea que lo que se
    // escribe aca abajo le apaga la app a un cliente.
    //
    // Va en la MISMA transaccion que los contratos: las dos columnas dicen lo
    // mismo, y una corrida que escribiera una sola dejaria el contrato en '3' y
    // el dominio operando, que es justo el estado que este bloque existe para
    // deshacer.
    //
    // ORDEN DE LOCKS: primero `contratos` (el SELECT ... FOR UPDATE de arriba) y
    // despues `dominios`, siempre por `id` ascendente (`ksort`). Dos corridas
    // encimadas —que el `overlap = skip` de la tarea ya evita— tomarian los
    // locks en el mismo orden y se esperarian en vez de abrazarse.
    $domCambiados = [];
    ksort($porDominio);

    if ($porDominio !== []) {
        $selDom = $pdo->prepare('SELECT id, nombre, situacion FROM dominios WHERE id = :id FOR UPDATE');
        $updDom = $pdo->prepare('UPDATE dominios SET situacion = :situacion WHERE id = :id');

        foreach ($porDominio as $domId => $info) {
            $selDom->execute([':id' => $domId]);
            $fila = $selDom->fetch();

            // La FK de `contratos`.`dominio` es RESTRICT, asi que esto no deberia
            // pasar; si pasa, el contrato apunta a un dominio que no existe y no
            // hay nada que escribir.
            if (!$fila) {
                $avisos[] = sprintf(
                    'el dominio #%d no existe: los contratos %s apuntan a una fila borrada',
                    $domId, '#' . implode(', #', $info['contratos'])
                );
                continue;
            }

            $domVieja = $fila['situacion'] === null ? null : trim((string) $fila['situacion']);
            $domNueva = $info['situacion'];

            if ($domVieja === $domNueva) continue;

            $updDom->execute([':situacion' => $domNueva, ':id' => $domId]);

            $domCambiados[] = [$domId, $info['nombre'], $domVieja, $domNueva, $info['contratos']];

            anotarLog(sprintf(
                '  DOMINIO #%d %s | %s -> %s | por %s %s',
                $domId, $info['nombre'],
                situacionTexto($domVieja), situacionTexto($domNueva),
                count($info['contratos']) === 1 ? 'el contrato' : 'los contratos',
                '#' . implode(', #', $info['contratos'])
            ));
        }
    }

    $pdo->commit();

    anotarLog(sprintf(
        'Resumen: %d situaciones cambiadas | %d sin cambios | de %d habilitados',
        count($cambiados), $iguales, $total
    ));
    anotarLog(sprintf(
        'Reparto: %d Normal | %d Limitado | %d Suspendido',
        $conteo[SITUACION_NORMAL], $conteo[SITUACION_LIMITADO], $conteo[SITUACION_SUSPENDIDO]
    ));
    anotarLog(sprintf(
        'Dominios: %d con situacion cambiada | de %d alcanzados por un contrato habilitado',
        count($domCambiados), count($porDominio)
    ));

    // LOS QUE ENTRAN A SUSPENDIDO, uno por uno y al pie. Es el movimiento por el
    // que alguien va a venir a preguntar: son los contratos que dejaron de estar
    // al dia, no una estadistica.
    $aSuspendido = array_values(array_filter(
        $cambiados,
        static fn(array $c): bool => $c[3] === SITUACION_SUSPENDIDO
    ));
    foreach ($aSuspendido as [$id, $dominio, $vieja, , $dias]) {
        anotarLog(sprintf(
            'A SUSPENDIDO: #%d %s | %s | venia de %s',
            $id, $dominio, textoAtraso($dias), situacionTexto($vieja)
        ));
    }

    // Y LOS DOMINIOS QUE SE QUEDARON SIN APP, que es el efecto real de la
    // corrida: con `situacion` = '3' `app/index.php` no dibuja ningun control.
    // Se listan aparte de los contratos porque es la linea que contesta "a quien
    // se le corto el servicio hoy", y el valor anterior va escrito al lado para
    // que revertir a mano sea leer este renglon.
    $domSuspendidos = array_values(array_filter(
        $domCambiados,
        static fn(array $d): bool => $d[3] === SITUACION_SUSPENDIDO
    ));
    foreach ($domSuspendidos as [$domId, $nombre, $domVieja]) {
        anotarLog(sprintf(
            'SIN SERVICIO: dominio #%d %s | venia de %s | la app deja de mostrarle los controles',
            $domId, $nombre, situacionTexto($domVieja)
        ));
    }

    $resumen = sprintf(
        'contratos: %d situaciones cambiadas (%d sin cambios, de %d habilitados) | %d Normal, %d Limitado, %d Suspendido%s | dominios: %d cambiados de %d%s',
        count($cambiados), $iguales, $total,
        $conteo[SITUACION_NORMAL], $conteo[SITUACION_LIMITADO], $conteo[SITUACION_SUSPENDIDO],
        $aSuspendido === [] ? '' : sprintf(' | %d pasaron a Suspendido', count($aSuspendido)),
        count($domCambiados), count($porDominio),
        $domSuspendidos === [] ? '' : sprintf(' | %d dominios quedaron SIN SERVICIO', count($domSuspendidos))
    );

    // Los avisos NO cortan: lo que se escribio se escribio bien. Suben el suceso
    // a `alerta` porque son los casos en que la corrida sale `ok` y aun asi hay
    // algo que mirar — una pendiente sin fecha es deuda que el calculo no vio.
    //
    // Y TAMBIEN SUBE A `alerta` CUANDO UN DOMINIO QUEDO SIN SERVICIO, que no es
    // un error pero es lo mas grave que hace este job: un cliente que entra a la
    // app y no encuentra ningun control. Tiene que verse en el Visor sin abrir
    // el .log de la ejecucion.
    if (function_exists('registrarSuceso')) {
        registrarSuceso(
            $pdo,
            'cron/' . jobNombre(),
            ($avisos === [] && $domSuspendidos === []) ? 'info' : 'alerta',
            // El detalle contrato por contrato ya esta en el .log de la
            // ejecucion: al Visor va el resumen y los primeros avisos.
            $resumen . ($avisos === [] ? '' : ' | AVISO: ' . implode('; ', array_slice($avisos, 0, 5)))
                     . (count($avisos) > 5 ? sprintf(' (+%d mas en el log)', count($avisos) - 5) : '')
        );
    }
    foreach ($avisos as $aviso) anotarLog('AVISO: ' . $aviso);

    marcarEjecucionOk($resumen);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    anotarLog('ERROR: ' . $e->getMessage());
    marcarEjecucionError($e);
    throw $e;
}

/**
 * Las cuatro funciones que vivian aca --`situacionPorAtraso()`,
 * `peorSituacion()`, `situacionTexto()` y `textoAtraso()`-- se mudaron a
 * `api/contratos_situacion_lib.php` y a `api/contratos_estado_lib.php` cuando el
 * `Actualizar situacion` de las fichas paso a hacer esta misma cuenta. Lo unico
 * que queda abajo es lo que solo sirve para el .log de este barrido.
 */

/** Un nombre de la base para el log, o un marcador cuando viene vacio. */
function textoNombre(mixed $nombre): string
{
    $n = trim((string) ($nombre ?? ''));

    return $n !== '' ? mb_substr($n, 0, 60) : '(sin nombre)';
}
