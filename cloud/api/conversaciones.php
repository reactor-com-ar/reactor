<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/**
 * Las conversaciones del chat con IA del sitio publico
 * (`www/chat/mensaje.php`). Dos tablas: `conversaciones` y
 * `conversaciones_mensajes`.
 *
 * MODULO DE CONSULTA Y BAJA, SIN ALTA NI EDICION, y no es una simplificacion:
 * las filas las escribe el visitante del sitio conversando. Un alta desde aca
 * fabricaria una charla que nunca ocurrio y una edicion reescribiria lo que
 * alguien dijo -- es lo mismo que ya vale para Notificaciones, Señales y
 * Registros, con la diferencia de que aca SI hay baja (ver mas abajo).
 *
 * POR QUE SI HAY BAJA. Estas dos tablas guardan lo que escribio una persona
 * anonima mas su IP y su navegador, o sea datos personales bajo la 25.326, y
 * son las unicas del sistema que los acumulan sin que nadie se haya registrado.
 * Tiene que existir la forma de borrar una conversacion puntual sin entrar a la
 * base a mano.
 *
 * EL PROMPT DE SISTEMA NO ESTA EN NINGUNA DE LAS DOS TABLAS. Lo que se ve aca
 * es lo que dijeron la persona y el modelo; el contexto con el que contesto
 * -- el documento del experto de Datarocket, los planes y los articulos -- se
 * arma de nuevo en cada turno y no se guarda. Lo unico que queda de el es
 * `contexto`, con los `uuid` de las entradas que se le pasaron.
 */

/**
 * Precios por millon de tokens con los que se estima el costo del mes.
 *
 * VAN ACA ARRIBA y no al pie del archivo: una `const` en el cuerpo de un script
 * se define cuando la ejecucion llega a la linea, no se eleva como las
 * funciones, y `handleList()` -- que las usa -- corre antes y termina en un
 * `exit`. Es el mismo motivo por el que `notificaciones.php` sube la suya.
 *
 * Y estan aca y no en la base a proposito: son de OpenAI, cambian cuando ellos
 * quieren y no hay nada que el operador pueda configurar al respecto. Si algun
 * dia el modelo cambia de gama, estos dos numeros quedan viejos y hay que
 * tocarlos -- por eso la pantalla dice "estimado".
 */
const CONVERSACIONES_USD_ENTRADA = 0.15;
const CONVERSACIONES_USD_SALIDA  = 0.60;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                handleGet((int) $_GET['id']);
            }
            handleList();
            break;
        case 'DELETE':
            handleDelete();
            break;
        default:
            // Sin POST ni PUT a proposito: ver la cabecera del archivo.
            json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar conversaciones: ' . $e->getMessage(), 500);
}

/**
 * Listado de conversaciones.
 *
 * LAS TRES SUBCONSULTAS DEL SELECT SON EL MODULO. Una conversacion sin su
 * primera pregunta es una fila de fechas y una IP: lo que el operador viene a
 * leer es QUE le preguntaron, y tener que abrir la ficha de cada una para
 * averiguarlo convertiria el listado en un indice inutil. Las otras dos son el
 * largo real y lo que costo.
 *
 * `c`.`mensajes` (la columna) cuenta los turnos DE LA PERSONA, porque es el
 * contador con el que el sitio aplica el tope por conversacion. `total` cuenta
 * las filas de las dos partes. Son numeros distintos y los dos se muestran.
 */
function handleList(): void
{
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
    if ($limit <= 0 || $limit > 2000) $limit = 100;

    $where  = [];
    $params = [];

    $desde = trim((string) ($_GET['desde'] ?? ''));
    $hasta = trim((string) ($_GET['hasta'] ?? ''));
    if ($desde !== '') {
        $where[]          = 'c.iniciada >= :desde';
        $params[':desde'] = $desde . ' 00:00:00';
    }
    if ($hasta !== '') {
        $where[]          = 'c.iniciada <= :hasta';
        $params[':hasta'] = $hasta . ' 23:59:59';
    }

    // Busqueda por texto libre: la resuelve la BASE (`ABM.md`, "Como busca el
    // texto libre"). El metodo es el mismo de siempre -- terminos cruzados con
    // Y, cada uno buscado en todos los campos con O -- pero uno de esos campos
    // vive en la tabla HIJA, asi que la mitad de cada condicion va adentro de un
    // EXISTS en vez de en el WHERE plano.
    //
    // POR QUE EXISTS Y NO UN JOIN: con `JOIN conversaciones_mensajes` la
    // consulta devolveria una fila por mensaje y habria que juntarlas con
    // GROUP BY, arruinando el LIMIT (que cuenta filas, no conversaciones).
    //
    // Los dos juegos de condiciones llevan PREFIJOS DISTINTOS porque cada
    // placeholder puede aparecer una sola vez en la sentencia
    // (`EMULATE_PREPARES => false`). Y se recorren en paralelo: el termino i de
    // uno y el termino i del otro son la misma palabra tipeada, asi que van
    // dentro del mismo parentesis unidos por O -- "que esta palabra aparezca en
    // los datos de la conversacion O en alguno de sus mensajes".
    $q = trim((string) ($_GET['q'] ?? ''));
    [$condConv, $pConv] = busquedaWhere($q, ['c.origen', 'c.pagina', 'c.uuid', 'c.agente'], 'qc');
    [$condMsg, $pMsg]   = busquedaWhere($q, ['m.texto'], 'qm');
    foreach ($condConv as $i => $cond) {
        $where[] = '(' . $cond . ' OR EXISTS (SELECT 1 FROM conversaciones_mensajes m
                        WHERE m.conversacion = c.id AND ' . $condMsg[$i] . '))';
    }
    $params = array_merge($params, $pConv, $pMsg);

    $sql = 'SELECT c.id, c.uuid, c.iniciada, c.actividad, c.mensajes, c.origen,
                   c.agente, c.pagina,
                   (SELECT m.texto FROM conversaciones_mensajes m
                     WHERE m.conversacion = c.id AND m.rol = \'user\'
                     ORDER BY m.id LIMIT 1) AS consulta,
                   (SELECT COUNT(*) FROM conversaciones_mensajes m
                     WHERE m.conversacion = c.id) AS total,
                   (SELECT COALESCE(SUM(m.tokens_entrada + m.tokens_salida), 0)
                      FROM conversaciones_mensajes m
                     WHERE m.conversacion = c.id) AS tokens
              FROM conversaciones c';

    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);

    // Por `id` y no por `iniciada`: el id crece con la fecha y es la PK, asi que
    // el orden sale del indice. Mismo criterio que Notificaciones.
    $sql .= ' ORDER BY c.id DESC LIMIT ' . $limit;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $conversaciones = array_map(static function (array $r): array {
        $r['id']       = (int) $r['id'];
        $r['mensajes'] = (int) $r['mensajes'];
        $r['total']    = (int) $r['total'];
        $r['tokens']   = (int) $r['tokens'];
        return $r;
    }, $stmt->fetchAll());

    json_ok([
        'resumen'        => conversacionesResumen(),
        'conversaciones' => $conversaciones,
        'limit'          => $limit,
    ]);
}

/**
 * Los KPIs de la cabecera. Son del UNIVERSO y no de la consulta (`ABM.md`): un
 * contador que cambiara al buscar dejaria de ser un KPI.
 *
 * `costo_mes` es una estimacion en dolares y esta declarada como tal en la
 * pantalla: los precios por millon de tokens no viven en la base, cambian del
 * lado de OpenAI y dependen del modelo de cada fila. Sirve para contestar
 * "¿cuanto me esta saliendo esto?" con un orden de magnitud, que es la pregunta
 * que alguien se hace mirando este modulo, no para conciliar una factura.
 */
function conversacionesResumen(): array
{
    $total = (int) db()->query('SELECT COUNT(*) FROM conversaciones')->fetchColumn();

    $mensajes = (int) db()->query('SELECT COUNT(*) FROM conversaciones_mensajes')->fetchColumn();

    $hoy = (int) db()->query(
        'SELECT COUNT(*) FROM conversaciones WHERE iniciada >= CURDATE()'
    )->fetchColumn();

    $tokens = db()->query(
        'SELECT COALESCE(SUM(tokens_entrada), 0) AS entrada,
                COALESCE(SUM(tokens_salida),  0) AS salida
           FROM conversaciones_mensajes
          WHERE fecha >= NOW() - INTERVAL 30 DAY'
    )->fetch();

    return [
        'total'     => $total,
        'mensajes'  => $mensajes,
        'hoy'       => $hoy,
        'tokens'    => (int) $tokens['entrada'] + (int) $tokens['salida'],
        // A CUATRO DECIMALES Y NO A DOS: con el modelo chico que usa el chat,
        // un mes entero de trafico real puede costar menos de un centavo, y
        // redondeando a dos el KPI queda clavado en 0,00 para siempre. El
        // front decide como mostrarlo (`< USD 0,01`).
        'costo_mes' => round(
            ((int) $tokens['entrada'] / 1000000) * CONVERSACIONES_USD_ENTRADA
            + ((int) $tokens['salida'] / 1000000) * CONVERSACIONES_USD_SALIDA,
            4
        ),
    ];
}

/**
 * Una conversacion con TODOS sus mensajes, que es lo que muestra la ficha.
 *
 * Sin LIMIT en los mensajes: el tope por conversacion del sitio son 25 turnos
 * de la persona, asi que el peor caso son ~50 filas. Un LIMIT aca escondería
 * el final de la charla, que suele ser donde se ve si el bot resolvio o derivo.
 */
function handleGet(int $id): void
{
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('SELECT id, uuid, iniciada, actividad, mensajes, origen, agente, pagina
                             FROM conversaciones WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $conversacion = $stmt->fetch();

    if ($conversacion === false) json_error('Conversacion no encontrada', 404);

    $conversacion['id']       = (int) $conversacion['id'];
    $conversacion['mensajes'] = (int) $conversacion['mensajes'];

    // ORDER BY id y no por fecha: la pregunta y su respuesta caen en el mismo
    // segundo, asi que `fecha` no alcanza para ordenarlas y el dialogo saldria
    // dado vuelta.
    // `origen` es la URL COMPLETA desde la que se escribio ese mensaje, y OJO:
    // no significa lo mismo que `conversaciones`.`origen`, que es la IP. Son dos
    // columnas con el mismo nombre en dos tablas que se leen siempre juntas.
    $stmt = db()->prepare('SELECT id, fecha, rol, texto, origen, modelo, contexto,
                                  tokens_entrada, tokens_salida
                             FROM conversaciones_mensajes
                            WHERE conversacion = :id
                            ORDER BY id');
    $stmt->execute([':id' => $id]);

    $conversacion['dialogo'] = array_map(static function (array $m): array {
        $m['id']             = (int) $m['id'];
        $m['tokens_entrada'] = $m['tokens_entrada'] !== null ? (int) $m['tokens_entrada'] : null;
        $m['tokens_salida']  = $m['tokens_salida']  !== null ? (int) $m['tokens_salida']  : null;
        // Los `uuid` de las entradas que se le pasaron al modelo, ya partidos:
        // es lo unico que queda del contexto con el que contesto.
        $m['contexto'] = array_values(array_filter(
            array_map('trim', explode(',', (string) ($m['contexto'] ?? '')))
        ));
        return $m;
    }, $stmt->fetchAll());

    json_ok(['conversacion' => $conversacion]);
}

/**
 * Baja de una conversacion entera.
 *
 * NO BORRA LOS MENSAJES A MANO: la FK `fk_conversaciones_mensajes_conversacion`
 * es `ON DELETE CASCADE`, asi que se van solos y en la misma transaccion
 * implicita. Borrarlos antes con un DELETE aparte abriria la ventana en la que
 * la conversacion existe sin su dialogo.
 */
function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('DELETE FROM conversaciones WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) json_error('Conversacion no encontrada', 404);

    json_ok(['id' => $id]);
}
