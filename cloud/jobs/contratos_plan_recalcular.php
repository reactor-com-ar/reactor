<?php

declare(strict_types=1);

/**
 * Reasigna el plan de los contratos en modo dinamico segun los usuarios del
 * dominio.
 *
 * Recorre `contratos` WHERE `habilitado` = 1 AND `plan_modo` = 'dinamico',
 * cuenta los usuarios del dominio y le escribe el plan mas chico que los cubra.
 * Escribe UNA sola columna, `contratos`.`plan`, y nada mas.
 *
 * Corre todos los dias a las 08:00 por el Programador de tareas, UNA HORA
 * DESPUES de `articulos_recalcular.php`. El orden no es cosmetico: el abono de
 * un plan es `articulos`.`venta` del articulo al que apunta, o sea el numero que
 * el job de las 07:00 acaba de repreciar. Corriendo antes, el `.log` de esta
 * tarea informaria el abono viejo del plan nuevo —el numero por el que alguien
 * va a venir a preguntar— y seria el de ayer.
 *
 * ES `reactor-api/robot/contratosActualizar.php` PORTADO, que a su vez es un
 * `for` sobre `cContrato::actualizar()` -> `cPlan::detectar($dominio->usuarios)`.
 * Ese robot esta en el crontab del legacy a las 05:45, un cuarto de hora despues
 * de `dominiosActualizar` (05:30), que es el que le dejaba fresca la columna que
 * leia. Lo que cambia respecto del original esta todo abajo, y ninguna de las
 * cinco diferencias es de estilo: cada una tapa un camino por el que el robot
 * viejo movia plata sin que nadie lo decidiera.
 *
 * ------------------------------------------------------------------------
 * 1. NO HAY FALLBACK AL PLAN FREE
 * ------------------------------------------------------------------------
 *
 * `cPlan::detectar()` cierra con `if ($plan == 0) $plan = 100; // plan free`.
 * O sea: al dominio que NO entra en ningun plan del catalogo —porque crecio por
 * encima del cupo mas grande— le asigna el plan gratuito. Hoy el techo Standard
 * es 200 usuarios; el dia que un dominio llegue a 201, ese `if` lo pasa de pagar
 * el abono mas caro a no pagar nada, de madrugada y sin dejar rastro.
 *
 * Aca eso se saltea y se reporta. Es la misma decision que ya tomo
 * `api/contratos_accion.php` al portar `cContrato::facturar()`, que tambien
 * traia un `if ($this->plan == 0) $this->plan = 100;` y tampoco se porto.
 *
 * ------------------------------------------------------------------------
 * 2. LOS CANDIDATOS SE ACOTAN AL `tipo` DEL PLAN QUE EL CONTRATO YA TIENE
 * ------------------------------------------------------------------------
 *
 * `detectar()` consulta `where (N<=usuarios) and (habilitado='1') order by
 * usuarios limit 1` SIN mirar `planes`.`tipo`, y con el catalogo de hoy eso
 * reparte planes de otra familia: los OCHO planes Developer habilitados tienen
 * `usuarios` = 10, igual que el Standard Free. Para un dominio de 10 usuarios o
 * menos el `order by usuarios limit 1` empata nueve filas y el ganador lo decide
 * el motor — un dominio Standard puede terminar facturando `Plan Developer de
 * 50001 a 100000 peticiones`.
 *
 * El `tipo` del plan actual es el ancla porque es el unico dato del contrato que
 * dice en que familia comercial esta. Con `tipo = 'S'` los cupos son 10, 20, 30,
 * 40, 50, 100, 150 y 200: todos distintos, asi que el minimo es uno solo.
 *
 * ------------------------------------------------------------------------
 * 3. UN EMPATE EN EL CUPO MINIMO NO SE DESEMPATA: SE SALTEA
 * ------------------------------------------------------------------------
 *
 * El ancla por `tipo` alcanza para Standard, pero no es una propiedad del
 * esquema: nada impide cargar manana dos planes Standard con el mismo cupo. Y
 * para Developer el empate es el estado actual (los ocho en 10). Elegir por `id`
 * o por `orden` seria inventar un criterio comercial dentro de un job; que falte
 * uno es un dato que alguien tiene que cargar.
 *
 * Consecuencia buscada: un contrato Developer en modo dinamico NO se mueve y lo
 * dice. Correcto — Developer se tarifa por `usos`, no por usuarios.
 *
 * Y EL EMPATE NO LO DESEMPATA EL MOTOR DE FORMA ESTABLE: corriendo la query del
 * legacy verbatim sobre dev (MySQL 8.0) el empate de nueve filas en el cupo 10
 * devuelve el 100 —el Standard Free, que es el que los contratos tienen hoy—,
 * pero eso sale del orden de la PK con un `ORDER BY usuarios` sin indice, no de
 * una garantia. Produccion corre MariaDB 10.11: el mismo empate puede resolverse
 * al otro lado con otra fila sin que nada cambie en la base.
 *
 * ------------------------------------------------------------------------
 * 4. LOS USUARIOS SE CUENTAN, NO SE LEEN DE `dominios`.`usuarios`
 * ------------------------------------------------------------------------
 *
 * `detectar()` recibe `dominios`.`usuarios`, una columna CACHE que mantiene a
 * mano el legacy y que este repo ya declaro no confiable: `api/dominios.php`,
 * `panel/api/dominio.php` y `panel/api/dashboard.php` calculan los cinco
 * contadores en vez de leerlos, porque el cache esta desfasado en 23 de los 148
 * dominios (el alta suma y la baja no resta). Un job que reasigna planes sobre
 * esa columna depende de que siga corriendo `dominiosActualizar.php` del legacy,
 * que es exactamente el andamio que este repo esta desarmando — y cuando un
 * robot del legacy se muere lo hace en silencio: `articulosActualizar.php` dejo
 * la cotizacion clavada veinticinco dias y nadie se entero.
 *
 * SE USA LA MISMA EXPRESION QUE LA PANTALLA: `COUNT(DISTINCT p.usuario)` sobre
 * `perfiles`, que es lo que el modulo Dominios de cloud y el panel muestran como
 * "Usuarios". No es lo mismo que el `COUNT(id)` del cache: una persona puede
 * tener mas de un perfil en el mismo dominio (hoy hay 8 pares repetidos; el
 * dominio 2 declara 18 y tiene 13 personas). Si el job tarifara por perfiles
 * mientras la pantalla muestra personas, la misma palabra diria dos numeros y el
 * abono saldria del que nadie ve.
 *
 * El cache no se corrige ni se escribe desde aca —no es de este job— pero se
 * anota en el `.log` cuando difiere, que es donde se ve si el robot viejo
 * todavia respira.
 *
 * NO SE FILTRA POR `perfiles`.`habilitado`, igual que las tres pantallas: el
 * cupo del plan es el tamaño del padron del dominio, no cuantos entraron hoy.
 * Cambiar ese criterio aca sin cambiarlo alla volveria a partir la definicion en
 * dos. Lo que si se anota en el `.log` es el conteo de habilitados cuando
 * difiere, porque es la pregunta que sigue.
 *
 * ------------------------------------------------------------------------
 * 5. EL PLAN DESTINO TIENE QUE PODER FACTURARSE
 * ------------------------------------------------------------------------
 *
 * Un plan sin `articulo` bloquea el facturar del contrato ("El plan no tiene
 * articulo: no hay precio que facturar", `api/contratos_accion.php`). Hoy los 22
 * planes habilitados tienen articulo, asi que esta guarda no desvia ninguno:
 * existe para que un plan a medio cargar no deje un contrato sin poder emitir.
 *
 * ------------------------------------------------------------------------
 * QUE NO HACE
 * ------------------------------------------------------------------------
 *
 * - NO toca los contratos en `plan_modo` = 'fijo', que son las 50 filas de hoy
 *   (el default del ENUM). Mientras nadie elija `dinamico` en el ABM, este job
 *   corre y sale `ok` con 0 contratos evaluados. Esta verificado que la primera
 *   corrida con los 26 contratos habilitados en dinamico tampoco moveria
 *   ninguno: los 26 ya estan parados en el plan que les corresponde.
 * - NO toca `facturado`, `facturar` ni `remitir`. El ciclo de facturacion es de
 *   `api/contratos_accion.php?accion=facturar` y de nadie mas.
 * - NO factura. Si el plan cambia, el comprobante del periodo siguiente sale con
 *   el abono nuevo porque `facturar` lee el plan en el momento de emitir.
 * - NO escribe `dominios`.`usuarios` ni ninguna otra columna de `dominios`.
 *
 * ------------------------------------------------------------------------
 * PENDIENTE, Y NO LO RESUELVE ESTE JOB: EL ROBOT VIEJO SIGUE EN EL CRONTAB
 * ------------------------------------------------------------------------
 *
 * `contratosActualizar.php` esta SIN COMENTAR en `reactor-api/cron/jobs` a las
 * 05:45, un renglon despues de `dominiosActualizar` —que esta demostrablemente
 * vivo: los 53 dominios habilitados tienen los cuatro contadores frescos y 17 de
 * los 95 deshabilitados estan desfasados, que es exactamente su
 * `WHERE habilitado = 1`—. Hay que asumir entonces que el de los contratos
 * tambien corre.
 *
 * MIENTRAS LOS DOS CORRAN, EL VIEJO MANDA SOBRE LOS CONTRATOS `fijo`: reasigna
 * el plan de TODOS los habilitados, porque `plan_modo` es de este repo y el
 * legacy no la conoce. Este job corre despues y arregla los `dinamico`, pero un
 * contrato que alguien puso en `fijo` se seguiria moviendo a las 05:45. `fijo`
 * no significa nada hasta que esa linea se comente.
 */

require_once __DIR__ . '/_bootstrap.php';

// El bootstrap carga este helper solo en el camino de error. Se requiere aca
// porque el job tambien deja rastro cuando sale bien: cambiarle el plan a un
// contrato es cambiarle el abono, y eso tiene que poder ubicarse en el tiempo
// desde el Visor de sucesos sin ir a buscar el .log de la ejecucion.
$_sucesos = __DIR__ . '/../api/lib/sucesos.php';
if (is_file($_sucesos)) require_once $_sucesos;

/**
 * El valor de `contratos`.`plan_modo` que habilita el recalculo.
 *
 * El otro es 'fijo' (el default del ENUM, y lo que tienen las 50 filas). El
 * catalogo canonico vive en `PLAN_MODOS` de `api/contratos.php`; aca va solo el
 * valor que este job necesita, porque ese archivo no se puede incluir desde CLI
 * (`api/bootstrap.php` manda headers y llama a `requireAuth()`).
 */
const PLAN_MODO_DINAMICO = 'dinamico';

try {
    $pdo = _jobsPdo();

    $cambiados = [];   // [id, dominio, plan viejo, plan nuevo, abono viejo, abono nuevo]
    $salteados = [];   // [id, motivo]
    $iguales   = 0;

    // TODO EN UNA SOLA TRANSACCION, con los contratos bloqueados. El lock es lo
    // que serializa esto contra `accion=facturar`, que abre con
    // `SELECT * FROM contratos WHERE id = :id FOR UPDATE` y despues arma los
    // renglones leyendo el plan. Sin el, una facturacion que arranco con el plan
    // viejo podria emitir el comprobante con el abono viejo DESPUES de que esta
    // corrida lo cambio, o al reves — y el renglon ya quedo escrito.
    $pdo->beginTransaction();

    // Sin joins a proposito: en MySQL un `FOR UPDATE` sobre un `LEFT JOIN`
    // bloquea tambien las filas de `planes` y `articulos`, que este job solo
    // lee. El contexto se trae despues, sin lock. `plan_modo` y `habilitado` no
    // tienen indice, asi que esto bloquea el scan de las 50 filas de la tabla; a
    // esta escala y a las 08:00 no es un problema.
    $stmt = $pdo->prepare(
        'SELECT id, plan, dominio
           FROM contratos
          WHERE habilitado = 1 AND plan_modo = :modo
          ORDER BY id
          FOR UPDATE'
    );
    $stmt->execute([':modo' => PLAN_MODO_DINAMICO]);
    $contratos = $stmt->fetchAll();
    $total     = count($contratos);

    anotarLog(sprintf('%d contratos habilitados en modo %s', $total, PLAN_MODO_DINAMICO));

    // El contexto de un contrato: su plan, el abono de ese plan, su dominio y
    // los usuarios del dominio.
    //
    // `COUNT(DISTINCT p.usuario)` es LA MISMA expresion de `api/dominios.php`,
    // `panel/api/dominio.php` y `panel/api/dashboard.php` (ver el punto 4 del
    // encabezado). El segundo conteo no decide nada: se informa cuando difiere.
    $ctx = $pdo->prepare(
        'SELECT pl.id          AS plan_id,
                pl.nombre      AS plan_nombre,
                pl.tipo        AS plan_tipo,
                pl.habilitado  AS plan_habilitado,
                pl.usuarios    AS plan_cupo,
                pa.venta       AS plan_abono,
                d.id           AS dominio_id,
                d.nombre       AS dominio_nombre,
                d.usuarios     AS dominio_cache,
                (SELECT COUNT(DISTINCT p.usuario) FROM perfiles p WHERE p.dominio = d.id) AS usuarios,
                (SELECT COUNT(DISTINCT p.usuario) FROM perfiles p WHERE p.dominio = d.id AND p.habilitado = 1) AS usuarios_habilitados
           FROM contratos c
           LEFT JOIN planes    pl ON pl.id = c.plan
           LEFT JOIN articulos pa ON pa.id = pl.articulo
           LEFT JOIN dominios  d  ON d.id  = c.dominio
          WHERE c.id = :id'
    );

    // Los planes de la familia del contrato que cubren esa cantidad de usuarios,
    // del cupo mas chico al mas grande.
    //
    // `p.usuarios >= :usuarios` DEJA AFUERA EL `-1` (ilimitado) Y ESO ES LO QUE
    // CORRESPONDE, por mas que la semantica de la columna diga lo contrario.
    // `detectar()` lo excluye igual, por el mismo `N <= usuarios`. Admitirlo
    // seria peor: `-1` es el minimo de `ORDER BY usuarios`, asi que un plan
    // ilimitado ganaria SIEMPRE, sobre cualquier cupo acotado y para cualquier
    // dominio. Los seis planes Telemetry habilitados tienen `usuarios` = -1, y
    // es correcto que ninguno salga de aca: Telemetry se tarifa por `usos`.
    // Un `usuarios` en NULL queda afuera por la misma comparacion.
    $cand = $pdo->prepare(
        'SELECT p.id, p.nombre, p.usuarios, p.articulo, a.venta AS abono
           FROM planes p
           LEFT JOIN articulos a ON a.id = p.articulo
          WHERE p.habilitado = 1
            AND p.tipo = :tipo
            AND p.usuarios >= :usuarios
          ORDER BY p.usuarios ASC, p.id ASC'
    );

    $upd = $pdo->prepare('UPDATE contratos SET plan = :plan WHERE id = :id');

    foreach ($contratos as $con) {
        $id = (int) $con['id'];

        $ctx->execute([':id' => $id]);
        $c = $ctx->fetch() ?: [];

        // `plan` e `id` del plan no son lo mismo: la columna puede traer el
        // centinela `0` del sistema historico, que no es ningun plan. Con el
        // LEFT JOIN eso llega como `plan_id` en NULL, igual que un plan borrado.
        if ($c['plan_id'] === null) {
            $salteados[] = [$id, 'no tiene plan: sin plan no hay familia (`planes`.`tipo`) de donde elegir'];
            continue;
        }

        // EL PLAN ACTUAL TIENE QUE ESTAR HABILITADO. Los 11 planes
        // deshabilitados del catalogo son los viejos (Edificio / Ciudad / Casa
        // Inteligente, Anonimo, Reactor Ilimitado) y tienen contratos colgados a
        // proposito: el plan se retiro de la venta y esos contratos se quedaron
        // con su precio. Meterlos en la escalera Standard les cambiaria el abono
        // por una decision que nadie tomo. Ademas los once tienen `tipo` vacio,
        // asi que no habria familia a la que acotar los candidatos.
        if (!esHabilitadoPlan($c['plan_habilitado'])) {
            $salteados[] = [$id, sprintf(
                'su plan #%d "%s" esta deshabilitado: un plan retirado de la venta no se reemplaza solo',
                (int) $c['plan_id'], textoPlan($c['plan_nombre'])
            )];
            continue;
        }

        if ($c['dominio_id'] === null) {
            $salteados[] = [$id, 'no tiene dominio: sin dominio no hay usuarios que contar'];
            continue;
        }

        $tipo      = trim((string) ($c['plan_tipo'] ?? ''));
        $usuarios  = (int) $c['usuarios'];
        $planViejo = (int) $c['plan_id'];
        $dominio   = sprintf('dominio #%d %s', (int) $c['dominio_id'], textoPlan($c['dominio_nombre']));

        // El `tipo` vacio es un valor real de la columna (11 filas), pero los
        // once planes que lo tienen estan deshabilitados, asi que un contrato no
        // deberia llegar hasta aca con el. Si llega, `tipo = ''` no define
        // ninguna familia y elegir por cupo a secas es el bug del punto 2.
        if ($tipo === '') {
            $salteados[] = [$id, sprintf(
                'su plan #%d "%s" no tiene tipo: sin familia los candidatos serian todo el catalogo',
                $planViejo, textoPlan($c['plan_nombre'])
            )];
            continue;
        }

        $cand->execute([':tipo' => $tipo, ':usuarios' => $usuarios]);
        $opciones = $cand->fetchAll();

        // NINGUN PLAN LO CUBRE: el dominio crecio por encima del cupo mas grande
        // de su familia. Es el caso que el legacy mandaba al plan Free (punto 1).
        if ($opciones === []) {
            $salteados[] = [$id, sprintf(
                'ningun plan de tipo "%s" cubre %d usuarios (%s): el dominio se paso del cupo mas grande del catalogo',
                $tipo, $usuarios, $dominio
            )];
            continue;
        }

        $cupo    = (int) $opciones[0]['usuarios'];
        $empatan = array_values(array_filter($opciones, static fn(array $o): bool => (int) $o['usuarios'] === $cupo));

        if (count($empatan) > 1) {
            $salteados[] = [$id, sprintf(
                '%d planes de tipo "%s" empatan en el cupo %d (%s): falta el dato que los desempata',
                count($empatan), $tipo, $cupo,
                implode(', ', array_map(static fn(array $o): string => '#' . $o['id'], $empatan))
            )];
            continue;
        }

        $elegido = $empatan[0];

        if ($elegido['articulo'] === null || (int) $elegido['articulo'] === 0) {
            $salteados[] = [$id, sprintf(
                'el plan que corresponde (#%d "%s") no tiene articulo: el contrato no podria facturarse',
                (int) $elegido['id'], textoPlan($elegido['nombre'])
            )];
            continue;
        }

        $planNuevo  = (int) $elegido['id'];
        $abonoViejo = $c['plan_abono']      === null ? null : (float) $c['plan_abono'];
        $abonoNuevo = $elegido['abono']     === null ? null : (float) $elegido['abono'];

        // Lo que no cambia no se escribe, igual que en `articulos_recalcular`:
        // asi el `.log` habla solo de los planes que de verdad se movieron y una
        // segunda corrida del mismo dia es un no-op verificable.
        if ($planNuevo === $planViejo) {
            $iguales++;
            anotarLog(sprintf(
                '  #%d %s | %d usuarios | plan %d (cupo %s) sin cambios%s',
                $id, $dominio, $usuarios, $planViejo, textoCupo($cupo),
                textoConteos($c)
            ));
            continue;
        }

        $upd->execute([':plan' => $planNuevo, ':id' => $id]);

        $cambiados[] = [$id, $dominio, $planViejo, $planNuevo, $abonoViejo, $abonoNuevo];

        anotarLog(sprintf(
            '  #%d %s | %d usuarios | plan %d (cupo %s) -> %d "%s" (cupo %s) | abono %s -> %s%s',
            $id, $dominio, $usuarios,
            $planViejo, textoCupo(isset($c['plan_cupo']) && $c['plan_cupo'] !== null ? (int) $c['plan_cupo'] : null),
            $planNuevo, textoPlan($elegido['nombre']), textoCupo($cupo),
            textoPesos($abonoViejo), textoPesos($abonoNuevo),
            textoConteos($c)
        ));
    }

    $pdo->commit();

    foreach ($salteados as [$id, $motivo]) {
        anotarLog(sprintf('  SALTEADO #%d: %s', $id, $motivo));
    }

    anotarLog(sprintf(
        'Resumen: %d reasignados | %d sin cambios | %d salteados | de %d en modo %s',
        count($cambiados), $iguales, count($salteados), $total, PLAN_MODO_DINAMICO
    ));

    // LA SUMA DEL ABONO, cuando se movio algo. Es el numero por el que alguien
    // va a venir a preguntar, y el que dice si la corrida subio o bajo la
    // facturacion del mes. Solo se suman los contratos con los dos abonos
    // cargados: mezclar un NULL con un 0 daria un delta inventado.
    $delta = deltaAbono($cambiados);
    if ($delta !== null) {
        anotarLog(sprintf(
            'Abono mensual: %s -> %s (%s)',
            textoPesos($delta['antes']), textoPesos($delta['despues']), textoFirmado($delta['delta'])
        ));
    }

    $resumen = sprintf(
        'contratos: %d planes reasignados (%d sin cambios, %d salteados, de %d en modo %s)%s',
        count($cambiados), $iguales, count($salteados), $total, PLAN_MODO_DINAMICO,
        $delta === null ? '' : sprintf(
            ' | abono mensual %s -> %s (%s)',
            textoPesos($delta['antes']), textoPesos($delta['despues']), textoFirmado($delta['delta'])
        )
    );

    // Los avisos NO cortan: lo que se escribio se escribio bien. Lo que hacen es
    // dejar rastro de los casos en que la corrida sale `ok` y aun asi hay algo
    // que mirar — y el primero de la lista, el dominio que se paso del cupo mas
    // grande, es un contrato que esta facturando menos de lo que le toca.
    $avisos = [];
    foreach ($salteados as [$id, $motivo]) {
        $avisos[] = sprintf('#%d: %s', $id, $motivo);
    }

    if (function_exists('registrarSuceso')) {
        registrarSuceso(
            $pdo,
            'cron/' . jobNombre(),
            $avisos === [] ? 'info' : 'alerta',
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
 * `planes`.`habilitado` con el criterio del repo (CLAUDE.md): dos valores, `1`
 * habilitado y `0` deshabilitado, y en PHP se lee el entero y nunca un string.
 *
 * Se declara aca y no se usa `esHabilitado()` de `lib/habilitado.php` porque esa
 * libreria la carga `api/bootstrap.php`, que no se puede incluir desde CLI. La
 * regla es la misma: nada de `=== '1'` ni de `in_array($h, ['S','1','Y'])`.
 */
function esHabilitadoPlan(mixed $valor): bool
{
    return (int) $valor === 1;
}

/** Un nombre de la base para el log, o un marcador cuando viene vacio. */
function textoPlan(mixed $nombre): string
{
    $n = trim((string) ($nombre ?? ''));

    return $n !== '' ? mb_substr($n, 0, 60) : '(sin nombre)';
}

/** Un cupo de `planes`.`usuarios` como lo muestra la pantalla: `-1` es Ilimitado. */
function textoCupo(?int $cupo): string
{
    if ($cupo === null) return 's/d';

    return $cupo === -1 ? 'Ilimitado' : (string) $cupo;
}

/** Un importe para el log, o `s/d` cuando el plan no tiene articulo con precio. */
function textoPesos(?float $monto): string
{
    return $monto === null ? 's/d' : '$ ' . number_format($monto, 2, '.', '');
}

/** Un delta con signo explicito, para que una baja de abono se lea como baja. */
function textoFirmado(float $monto): string
{
    return ($monto >= 0 ? '+' : '-') . '$ ' . number_format(abs($monto), 2, '.', '');
}

/**
 * Los dos conteos que NO deciden nada, para el final del renglon del log.
 *
 * `dominios`.`usuarios` es el cache del legacy (punto 4 del encabezado): se
 * imprime cuando difiere del conteo real, que es donde se ve si
 * `dominiosActualizar.php` todavia corre. Y el conteo de habilitados se imprime
 * cuando difiere del total, que es la pregunta que sigue a "cuantos usuarios
 * tiene este dominio".
 */
function textoConteos(array $c): string
{
    $usuarios    = (int) $c['usuarios'];
    $cache       = $c['dominio_cache'] === null ? null : (int) $c['dominio_cache'];
    $habilitados = (int) $c['usuarios_habilitados'];

    $notas = [];
    if ($cache !== $usuarios) {
        $notas[] = 'cache dominios.usuarios=' . ($cache === null ? 'NULL' : (string) $cache);
    }
    if ($habilitados !== $usuarios) {
        $notas[] = 'habilitados=' . $habilitados;
    }

    return $notas === [] ? '' : ' [' . implode(', ', $notas) . ']';
}

/**
 * Cuanto abono mensual movio la corrida.
 *
 * Solo entran los contratos cuyos DOS planes tienen precio: con un `NULL` de un
 * lado el delta no es cero, es desconocido, y sumarlo como cero daria un total
 * que parece exacto y no lo es. Devuelve null cuando no hay ninguno que sumar.
 *
 * @param array<int, array{0:int,1:string,2:int,3:int,4:?float,5:?float}> $cambiados
 * @return array{antes:float,despues:float,delta:float}|null
 */
function deltaAbono(array $cambiados): ?array
{
    $antes = 0.0;
    $despues = 0.0;
    $n = 0;

    foreach ($cambiados as [, , , , $abonoViejo, $abonoNuevo]) {
        if ($abonoViejo === null || $abonoNuevo === null) continue;
        $antes   += $abonoViejo;
        $despues += $abonoNuevo;
        $n++;
    }

    if ($n === 0) return null;

    return ['antes' => $antes, 'despues' => $despues, 'delta' => $despues - $antes];
}
