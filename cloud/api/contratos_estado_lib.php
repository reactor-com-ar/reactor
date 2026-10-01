<?php

declare(strict_types=1);

/**
 * EL ESTADO COMERCIAL DE UN CONTRATO: dar de baja y dar de alta, en un solo
 * lugar.
 *
 * Lo comparten los DOS caminos que ejecutan esa transicion:
 *
 *   - `api/contratos_accion.php?accion=baja|alta`, las acciones de la ficha y
 *     del menu de la fila del ABM, que es donde alguien lo decide a mano.
 *   - `jobs/contratos_baja_morosos.php`, la tarea del dia 15 que da de baja
 *     automaticamente a los contratos con deuda vencida hace mas de seis meses.
 *
 * ESTA EN UN LIB POR LA MISMA RAZON QUE `contratos_facturar_lib.php`: dos copias
 * son dos formas de que la baja que hace el operador y la que hace el job dejen
 * de ser la misma cosa. Y aca eso no es un detalle de estilo -- la baja APAGA EL
 * DOMINIO de un cliente, o sea que lo deja sin los controles de operacion de la
 * app. Dos versiones de esa transicion es un cliente al que se le corta el
 * servicio de una forma por un camino y de otra forma por el otro.
 *
 * ------------------------------------------------------------------------
 * QUE ESCRIBE: CUATRO COLUMNAS DE DOS TABLAS
 * ------------------------------------------------------------------------
 *
 *                        | contratoBaja()      | contratoAlta()
 *   ---------------------+---------------------+----------------------------
 *   contratos.habilitado | 0                   | 1
 *   contratos.baja       | la fecha de corte   | el centinela apocalipsis
 *   dominios.habilitado  | 0                   | 1
 *   dominios.situacion   | '3' Suspendido      | '1' Normal
 *
 * LAS CUATRO EN LA MISMA TRANSACCION, QUE LA ABRE EL LLAMADOR. Igual que
 * `facturarEmitir()`: ninguna de las dos funciones de abajo hace `beginTransaction`
 * ni `commit` -- el endpoint abre una por request y el job una por contrato, y
 * las dos necesitan poder revertir TODO lo que la accion escribio. Escribir el
 * contrato y no el dominio deja al cliente con el contrato dado de baja y la app
 * operando: dos afirmaciones distintas sobre el mismo cliente.
 *
 * LO QUE CORTA EL SERVICIO ES `situacion`, NO `habilitado`. `app/index.php` lee
 * `dominios`.`situacion` y con '3' no dibuja NINGUN control de operacion; de
 * `dominios`.`habilitado` no mira nada -- esa es la bandera administrativa que
 * gobierna los listados de `cloud` y `panel`.
 *
 * `contratos`.`situacion` NO SE TOCA. La escribe una sola tarea
 * (`contratos_situacion_recalcular.php`, 04:00) y sigue siendo asi: es la mora
 * calculada, no el estado comercial que se decide aca.
 *
 * ------------------------------------------------------------------------
 * NO HABLA HTTP, Y ESO ES LO QUE LO HACE INCLUIBLE DESDE CLI
 * ------------------------------------------------------------------------
 *
 * Ninguna funcion llama a `json_error()` ni a `json_ok()`, y ninguna usa `db()`:
 * el PDO llega por parametro. Es mas estricto que `contratos_facturar_lib.php`
 * --que si resuelve la conexion con `db()` y obliga al job a declararla-- porque
 * este lib no arrastra nada: su unica dependencia es `lib/habilitado.php`. El
 * job lo incluye y listo.
 *
 * Por eso tambien NO redeclara `FECHA_APOCALIPSIS`: `contratos_accion.php` carga
 * este lib Y `contratos_facturar_lib.php` en el mismo request, y esa constante ya
 * es de aquel. El mismo valor vive aca con nombre propio (`BAJA_SIN_FECHA`), que
 * ademas dice para que se usa: es el "todavia no se dio de baja" de la columna.
 */

require_once dirname(__DIR__) . '/lib/habilitado.php';

/**
 * El centinela de `contratos`.`baja` que significa "todavia no se dio de baja".
 *
 * Es el apocalipsis del sistema historico (`cTiempo::apocalipsis()`), el mismo
 * '2500-01-01' que escribe `cContrato::nuevo()`, que reparte `CENTINELA_FECHA`
 * en `api/contratos.php` y que `fechaReal()` traduce a `null` al salir. Se
 * declara con nombre propio y no como `FECHA_APOCALIPSIS` para poder convivir
 * en el mismo request con `contratos_facturar_lib.php` (ver la cabecera).
 */
const BAJA_SIN_FECHA = '2500-01-01';

/**
 * Los tres valores de `dominios`.`situacion`.
 *
 * Son los del catalogo `combos` con la clave `'$xDominio->situacion'`, los
 * mismos que declara `jobs/contratos_situacion_recalcular.php`. Se escriben y se
 * comparan como STRING porque la columna es `varchar(1)`: mismo criterio que
 * `comprobantes`.`estado`.
 *
 * Limitado ('2') no lo escribe ninguna de las dos transiciones -- es una banda
 * de mora, no un estado comercial que alguien elija -- pero esta declarado
 * porque `situacionTexto()` tiene que saber nombrarlo cuando el dominio VENIA
 * de ahi.
 */
const SITUACION_NORMAL     = '1';
const SITUACION_LIMITADO   = '2';
const SITUACION_SUSPENDIDO = '3';

/** Nombres para el log y el suceso. No se leen de `combos`: no depende de el. */
const SITUACION_TEXTOS = [
    SITUACION_NORMAL     => 'Normal',
    SITUACION_LIMITADO   => 'Limitado',
    SITUACION_SUSPENDIDO => 'Suspendido',
];

/**
 * La fila del contrato BLOQUEADA para la transaccion en curso.
 *
 * El `FOR UPDATE` no es opcional y es lo que hace que la precondicion signifique
 * algo: entre que el endpoint lee el contrato y escribe, o entre que el job arma
 * su lista y le llega el turno a esa fila, el otro camino pudo haberlo dado de
 * baja -- son el mismo codigo y el mismo minuto es posible. El segundo que entra
 * espera, y cuando entra ya ve `habilitado` en 0.
 *
 * Es el mismo `SELECT` que `contratoFila($id, true)` de
 * `contratos_facturar_lib.php`. Se repite porque este lib no puede incluir aquel
 * sin arrastrar la facturacion entera a un job que solo da de baja, y aquel no
 * puede incluir a este sin que el endpoint cargue dos veces lo mismo. Es la
 * misma duplicacion-por-construccion de `lib/habilitado.php` entre las apps.
 */
function contratoEstadoFila(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM contratos WHERE id = :id FOR UPDATE');
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();

    return $fila ?: null;
}

/**
 * Da de baja el contrato y apaga su dominio.
 *
 * `$hoy` es la fecha de corte y LA TRAE EL LLAMADOR, no se calcula aca: el
 * endpoint usa el dia de PHP y el job lo lee de la base con `CURDATE()` una sola
 * vez para toda la corrida, de modo que una corrida larga no cambie de dia a la
 * mitad. `contratos`.`baja` es una columna `date`, asi que va 'AAAA-MM-DD' y no
 * `NOW()` -- que es lo que termina guardando el legacy al mandarle un datetime.
 *
 * EXIGE EL CONTRATO HABILITADO y lanza si no lo esta. No es una validacion de
 * formulario: es la precondicion revalidada con la fila ya bloqueada, o sea lo
 * unico que impide que dos clicks seguidos --o el job y el operador en el mismo
 * minuto-- den dos bajas. El llamador traduce la excepcion a lo suyo: el
 * endpoint a un 409, el job a un salteo que se reporta.
 *
 * @return array{baja:string, dominio:array|null}
 */
function contratoBaja(PDO $pdo, array $con, string $hoy): array
{
    if (!esHabilitado($con['habilitado'] ?? 0)) {
        throw new RuntimeException('El contrato ya esta dado de baja.');
    }

    $pdo->prepare('UPDATE contratos SET baja = :baja, habilitado = :h WHERE id = :id')
        ->execute([':baja' => $hoy, ':h' => DESHABILITADO, ':id' => (int) $con['id']]);

    return [
        'baja'    => $hoy,
        'dominio' => dominioEstadoAplicar($pdo, $con, DESHABILITADO, SITUACION_SUSPENDIDO),
    ];
}

/**
 * Da de alta el contrato y vuelve a prender su dominio. Es `contratoBaja()` al
 * reves, columna por columna.
 *
 * La fecha de baja vuelve al centinela y no se borra a `NULL`: la columna
 * significa "cuando se dio de baja este contrato", asi que un contrato
 * habilitado con una fecha cargada son dos afirmaciones que se contradicen y la
 * ficha las muestra las dos.
 *
 * @return array{baja:null, dominio:array|null}
 */
function contratoAlta(PDO $pdo, array $con): array
{
    if (esHabilitado($con['habilitado'] ?? 0)) {
        throw new RuntimeException('El contrato ya esta habilitado.');
    }

    $pdo->prepare('UPDATE contratos SET baja = :baja, habilitado = :h WHERE id = :id')
        ->execute([':baja' => BAJA_SIN_FECHA, ':h' => HABILITADO, ':id' => (int) $con['id']]);

    return [
        'baja'    => null,
        'dominio' => dominioEstadoAplicar($pdo, $con, HABILITADO, SITUACION_NORMAL),
    ];
}

/**
 * Le escribe al dominio del contrato la bandera y la situacion que le
 * corresponden, y devuelve con que valores venia.
 *
 * TOMA EL LOCK DE `dominios` DESPUES DEL DE `contratos`, que es el orden en que
 * los toma `jobs/contratos_situacion_recalcular.php`. Dos escrituras encimadas
 * --esta transicion y la corrida de las 04:00-- se esperan en vez de abrazarse.
 *
 * Devuelve `null` cuando no hay dominio que tocar -- un contrato sin dominio, o
 * con la FK apuntando a una fila que no existe --, y entonces la transicion
 * escribe el contrato y nada mas. No es un error: hoy los 50 contratos tienen
 * dominio y la FK es RESTRICT, pero el `0` del sistema historico es "sin
 * asignar" y no una referencia.
 *
 * SE ESCRIBE SIEMPRE, aunque el dominio ya estuviera asi. Es al reves que el job
 * de las 04:00 --que saltea lo que no cambia para que su `.log` hable solo de lo
 * que se movio-- porque aca la fila es una sola y elegida: no hay un recorrido
 * masivo del que filtrar ruido. Lo que se informa igual es de donde venia
 * (`antes`), que es lo que hace falta para revertirlo a mano.
 */
function dominioEstadoAplicar(PDO $pdo, array $con, int $habilitado, string $situacion): ?array
{
    $domId = (int) ($con['dominio'] ?? 0);
    if ($domId <= 0) return null;

    $sel = $pdo->prepare('SELECT id, nombre, situacion, habilitado FROM dominios WHERE id = :id FOR UPDATE');
    $sel->execute([':id' => $domId]);
    $fila = $sel->fetch();
    if (!$fila) return null;

    $antes = [
        'habilitado' => esHabilitado($fila['habilitado']) ? 1 : 0,
        'situacion'  => $fila['situacion'] === null ? null : trim((string) $fila['situacion']),
    ];

    $pdo->prepare('UPDATE dominios SET habilitado = :h, situacion = :s WHERE id = :id')
        ->execute([':h' => $habilitado, ':s' => $situacion, ':id' => $domId]);

    return [
        'id'              => $domId,
        'nombre'          => trim((string) ($fila['nombre'] ?? '')),
        'habilitado'      => $habilitado,
        'situacion'       => $situacion,
        'situacion_texto' => SITUACION_TEXTOS[$situacion] ?? $situacion,
        'antes'           => $antes,
        // Si de verdad se movio algo. No decide nada de lo que se escribe: lo
        // usan el log y el suceso para no anunciar un corte de servicio que ya
        // estaba hecho.
        'cambio'          => $antes['habilitado'] !== $habilitado || $antes['situacion'] !== $situacion,
    ];
}

/**
 * Si esta transicion DEJO AL DOMINIO SIN SERVICIO -- o sea si lo paso a
 * Suspendido viniendo de otra cosa.
 *
 * Es el unico efecto de la baja por el que alguien va a venir a preguntar: un
 * cliente que entra a la app y no encuentra ningun control. Lo usan el endpoint
 * y el job para subir el suceso a `alerta` y para destacar la linea en el `.log`.
 */
function dominioQuedoSinServicio(?array $dom): bool
{
    return $dom !== null
        && $dom['cambio']
        && $dom['situacion'] === SITUACION_SUSPENDIDO
        && $dom['antes']['situacion'] !== SITUACION_SUSPENDIDO;
}

/** Un codigo de situacion con su texto, o el marcador del `NULL` inicial. */
function situacionTexto(?string $codigo): string
{
    if ($codigo === null || $codigo === '') return 'sin calcular';

    return isset(SITUACION_TEXTOS[$codigo])
        ? sprintf("'%s' %s", $codigo, SITUACION_TEXTOS[$codigo])
        : sprintf("'%s' (desconocido)", $codigo);
}

/**
 * Como quedo el dominio, en una linea, con los valores de los que venia.
 *
 * Es el texto que va al suceso y al `.log`: revertir una baja a mano es leer
 * este renglon. Mismo criterio que el job de las 04:00.
 */
function dominioEstadoTexto(?array $dom): string
{
    if ($dom === null) {
        return 'sin dominio: no se toco ninguna fila de `dominios`';
    }

    return sprintf(
        'dominio #%d %s: habilitado %d -> %d, situacion %s -> %s%s',
        $dom['id'],
        $dom['nombre'] !== '' ? $dom['nombre'] : '(sin nombre)',
        $dom['antes']['habilitado'], $dom['habilitado'],
        situacionTexto($dom['antes']['situacion']), situacionTexto($dom['situacion']),
        dominioQuedoSinServicio($dom) ? ' | SIN SERVICIO: la app deja de mostrarle los controles' : ''
    );
}
