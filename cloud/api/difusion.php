<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/databox.php';

/**
 * Modulo Difusion: envios masivos de correo a los usuarios del sistema.
 *
 * Escribe `difusiones` (la campana) y `difusiones_destinatarios` (una fila por
 * persona), creadas por cloud/sql/migrations/20260908_1000_crear_difusiones.sql,
 * que es donde estan escritas las decisiones del ESQUEMA. Aca van las del
 * ENDPOINT.
 *
 * EL CANAL ES EL MISMO QUE EL DE LAS INVITACIONES: el microservicio de Databox
 * (`POST /v4/aws/mensajes`), via `lib/databox.php` -- copia identica de la del
 * panel y la de la app. Sale por la misma cuenta SES, con la misma plantilla y
 * el mismo remite, asi que una difusion se ve como el resto de los correos de
 * Reactor sin configurar nada aparte.
 *
 * EL ENVIO SE DRENA POR LOTES Y LO MANEJA EL NAVEGADOR, no una sola request.
 * `POST ?enviar=1&id=N` manda hasta DIFUSION_LOTE pendientes y devuelve los
 * contadores; el front vuelve a llamar hasta que no queda ninguna. Por que asi:
 *
 *   - UNA SOLA REQUEST NO ENTRA. Son 2.065 cuentas habilitadas con correo en la
 *     base: a una llamada HTTP por destinatario contra un servicio externo, el
 *     envio global se come cualquier `max_execution_time` y cualquier timeout
 *     de nginx mucho antes de terminar.
 *   - CRON NO ES UNA OPCION SILENCIOSA. El Programador de tareas existe, pero
 *     depende de cronie + /etc/cron.d instalados al aprovisionar y el deploy no
 *     los toca: una difusion que dependiera de el podria quedarse sin salir con
 *     la pantalla diciendo que esta "enviando". Drenando desde el navegador, lo
 *     que se ve en la barra de progreso es lo que de verdad salio.
 *   - Y ES REANUDABLE. Si se cierra el navegador a mitad de camino, la difusion
 *     queda en `enviando` con filas pendientes y el menu de la fila ofrece
 *     `Reanudar envio`, que sigue por donde iba. No hay estado en memoria: el
 *     estado es la tabla.
 *
 * CADA DESTINATARIO ES UN CORREO APARTE, nunca un To/CC compartido. El
 * microservicio recibe un `destino` por llamada, y ademas mandar 2.000
 * direcciones en copia seria filtrar la lista de correos de todos los clientes
 * a todos los clientes.
 *
 * UN DESTINO QUE REBOTA NO CORTA EL LOTE: `databoxCorreoEncolar()` nunca lanza,
 * devuelve `['ok' => false, 'error' => ...]`, y esa fila queda `fallido` con el
 * motivo guardado. Cortar en el primer error dejaria el resto sin mandar por
 * una direccion mal escrita.
 */

/** Destinatarios que se intentan por cada `POST ?enviar=1`. */
const DIFUSION_LOTE = 20;

/** Tope de caracteres del cuerpo, alineado con la columna TEXT. */
const DIFUSION_CUERPO_MAX = 20000;

/** Tope del asunto, alineado con `difusiones`.`asunto` (varchar 200). */
const DIFUSION_ASUNTO_MAX = 200;

/** `tags` con el que salen los mensajes, para poder rastrearlos en Databox. */
const DIFUSION_TAG = 'difusion';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['impacto']))       { handleImpacto(); }
            elseif (isset($_GET['audiencia'])) { handleAudiencia(); }
            elseif (isset($_GET['id']))        { handleGet(); }
            else                               { handleList(); }
            break;
        case 'POST':
            if (isset($_GET['enviar']))        { handleEnviar(); }
            elseif (isset($_GET['cancelar']))  { handleCancelar(); }
            else                               { handleCreate(); }
            break;
        case 'DELETE':
            handleDelete();
            break;
        default:
            // No hay PUT: una difusion ya emitida no se edita. El texto que se
            // mando es lo que la gente recibio, y reescribirlo dejaria el
            // historial diciendo algo distinto de lo que salio.
            json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar la difusion: ' . $e->getMessage(), 500);
}

/* ------------------------------------------------------------------ */
/* Audiencia                                                           */
/* ------------------------------------------------------------------ */

/**
 * Resuelve a quien le llegaria una difusion con el alcance pedido.
 *
 * EL ALCANCE POR DOMINIO SALE DE `perfiles`, NO DE `usuarios`.`dominio`. Esa
 * columna es el dominio ACTIVO -- el ultimo que la persona uso en cualquiera de
 * los sistemas que comparten la tabla -- y no la lista de dominios a los que
 * puede entrar. Medido en dev: el dominio 135 tiene 148 cuentas con ese dominio
 * activo y 143 con perfil habilitado ahi, y ninguna de las dos listas contiene a
 * la otra (el 241 da 140 contra 141). Quien tiene acceso al dominio es quien
 * tiene un perfil habilitado en el, que es el mismo criterio con el que el
 * modulo Usuarios del panel lista su gente.
 *
 * SE DEDUPLICA POR CORREO EN MINUSCULAS. La misma persona puede tener varias
 * cuentas (10 correos repetidos en dev) y varios perfiles en el mismo dominio;
 * sin el DISTINCT recibiria el mismo mensaje dos o tres veces. Se queda la
 * cuenta de id mas bajo, que es la mas vieja.
 *
 * SE EXCLUYE LO QUE NO ES UN CORREO. Una fila tiene 'robertomaguero.se@gmail',
 * sin dominio de primer nivel: el filtro `LIKE '%_@_%._%'` la deja afuera en vez
 * de encolar un mensaje que el servicio va a rechazar. Cuantas quedaron afuera
 * viaja en la respuesta -- descartarlas en silencio haria que el total de la
 * pantalla no cierre con el de la base y nadie sepa por que.
 *
 * @return array{destinatarios: list<array{id:int,correo:string,nombre:?string}>,
 *               cuentas:int, descartados:int}
 */
function difusionAudiencia(int $dominio, bool $soloHabilitados): array
{
    $where  = [];
    $params = [];

    if ($soloHabilitados) {
        // `usuarios`.`habilitado` es tinyint(1) NOT NULL: "no habilitado" es
        // `= 0`, asi que basta con pedir `= 1` (CLAUDE.md, bandera habilitado).
        $where[] = 'u.habilitado = ' . HABILITADO;
    }
    // Sin correo no hay a donde mandar. `TRIM` porque la columna es texto libre
    // del sistema historico y admite el vacio.
    $where[] = "u.correo IS NOT NULL AND TRIM(u.correo) <> ''";

    $join = '';
    if ($dominio > 0) {
        $join = 'JOIN perfiles p ON p.usuario = u.id
                              AND p.dominio  = :dom
                              AND p.habilitado = ' . HABILITADO;
        $params[':dom'] = $dominio;
    }

    $sql = "SELECT u.id, TRIM(u.correo) AS correo, u.nombre
            FROM usuarios u
            $join
            WHERE " . implode(' AND ', $where) . '
            ORDER BY u.id ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $porCorreo   = [];
    $descartados = 0;
    $cuentas     = 0;

    foreach ($stmt->fetchAll() as $r) {
        $cuentas++;
        $correo = trim((string) $r['correo']);
        if (!difusionCorreoValido($correo)) {
            $descartados++;
            continue;
        }
        $clave = mb_strtolower($correo);
        // El primero gana: el ORDER BY es por id, o sea la cuenta mas vieja.
        if (isset($porCorreo[$clave])) {
            continue;
        }
        $porCorreo[$clave] = [
            'id'     => (int) $r['id'],
            'correo' => $correo,
            'nombre' => $r['nombre'] !== null ? trim((string) $r['nombre']) : null,
        ];
    }

    return [
        'destinatarios' => array_values($porCorreo),
        'cuentas'       => $cuentas,
        'descartados'   => $descartados,
    ];
}

/**
 * Forma minima de correo. `filter_var` con FILTER_VALIDATE_EMAIL, que es la
 * misma validacion que hace el resto del repo, y no una expresion regular
 * propia: las direcciones raras del sistema historico ya dieron bastante.
 */
function difusionCorreoValido(string $correo): bool
{
    return filter_var($correo, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * `GET ?audiencia=1&dominio=N&habilitados=1` -> cuantos recibirian el mensaje.
 *
 * Lo consulta el modal de alta cada vez que se cambia el alcance, para que el
 * numero que el operador confirma sea el que se va a congelar. Devuelve tambien
 * una muestra de los primeros correos: un total sin ninguna cara al lado es
 * facil de aceptar sin mirar.
 */
function handleAudiencia(): void
{
    $dominio = isset($_GET['dominio']) ? (int) $_GET['dominio'] : 0;
    $soloHab = ($_GET['habilitados'] ?? '1') !== '0';

    $audiencia = difusionAudiencia($dominio, $soloHab);

    json_ok([
        'total'       => count($audiencia['destinatarios']),
        'cuentas'     => $audiencia['cuentas'],
        'descartados' => $audiencia['descartados'],
        'muestra'     => array_slice(array_column($audiencia['destinatarios'], 'correo'), 0, 5),
    ]);
}

/* ------------------------------------------------------------------ */
/* Listado y ficha                                                     */
/* ------------------------------------------------------------------ */

/** SELECT comun del listado y de la ficha. */
function difusionSelect(): string
{
    return 'SELECT d.id, d.asunto, d.cuerpo, d.dominio, d.estado,
                   d.destinatarios, d.enviados, d.fallidos,
                   d.emisor, d.creada, d.terminada,
                   dom.nombre AS dominio_nombre,
                   c.nombre   AS emisor_nombre,
                   c.correo   AS emisor_correo
            FROM difusiones d
            LEFT JOIN dominios      dom ON dom.id = d.dominio
            LEFT JOIN controladores c   ON c.id   = d.emisor';
}

/** Normaliza los tipos de una fila de `difusiones` para el front. */
function difusionMap(array $r): array
{
    $r['id']            = (int) $r['id'];
    $r['dominio']       = $r['dominio'] !== null ? (int) $r['dominio'] : null;
    $r['emisor']        = $r['emisor']  !== null ? (int) $r['emisor']  : null;
    $r['destinatarios'] = (int) $r['destinatarios'];
    $r['enviados']      = (int) $r['enviados'];
    $r['fallidos']      = (int) $r['fallidos'];
    $r['pendientes']    = max(0, $r['destinatarios'] - $r['enviados'] - $r['fallidos']);
    return $r;
}

function handleList(): void
{
    $dominio = isset($_GET['dominio']) ? (int) $_GET['dominio'] : 0;
    $estado  = isset($_GET['estado'])  ? strtolower(trim((string) $_GET['estado'])) : '';
    $limit   = isset($_GET['limit'])   ? (int) $_GET['limit'] : 100;
    if ($limit <= 0 || $limit > 2000) $limit = 100;
    if (!in_array($estado, ['pendiente', 'enviando', 'enviada', 'cancelada'], true)) $estado = '';

    $sql    = difusionSelect();
    $where  = [];
    $params = [];
    if ($dominio > 0) {
        $where[]        = 'd.dominio = :dom';
        $params[':dom'] = $dominio;
    }
    if ($estado !== '') {
        $where[]        = 'd.estado = :est';
        $params[':est'] = $estado;
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY d.id DESC LIMIT ' . $limit;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $difusiones = array_map('difusionMap', $stmt->fetchAll());

    $resumen = db()->query(
        "SELECT COUNT(*)                                        AS total,
                COALESCE(SUM(estado IN ('pendiente','enviando')), 0) AS en_curso,
                COALESCE(SUM(enviados), 0)                      AS enviados,
                COALESCE(SUM(fallidos), 0)                      AS fallidos
         FROM difusiones"
    )->fetch();

    json_ok([
        'resumen' => [
            'total'    => (int) $resumen['total'],
            'en_curso' => (int) $resumen['en_curso'],
            'enviados' => (int) $resumen['enviados'],
            'fallidos' => (int) $resumen['fallidos'],
        ],
        'difusiones' => $difusiones,
        'catalogos'  => ['dominios' => difusionDominios()],
        'limit'      => $limit,
    ]);
}

/**
 * Catalogo de dominios para el select de alcance y el de filtros.
 *
 * VAN TODOS, tambien los deshabilitados (95 de 148 en dev lo estan), con la
 * bandera para que el front los marque. Un dominio apagado sigue teniendo
 * usuarios con acceso, y esconderlo dejaria sin forma de escribirles.
 */
function difusionDominios(): array
{
    return array_map(static function (array $d): array {
        return [
            'id'         => (int) $d['id'],
            'nombre'     => (string) $d['nombre'],
            'habilitado' => esHabilitado($d['habilitado']),
        ];
    }, db()->query('SELECT id, nombre, habilitado FROM dominios ORDER BY nombre')->fetchAll());
}

/**
 * `GET ?id=N` -> la difusion con sus destinatarios.
 *
 * Los destinatarios vienen en la misma respuesta y no bajo demanda: es una
 * consulta por indice `(difusion, estado, id)` sobre a lo sumo un par de miles
 * de filas, y son justamente lo que se abre a mirar cuando algo no llego.
 */
function handleGet(): void
{
    $id = (int) $_GET['id'];
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare(difusionSelect() . ' WHERE d.id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) json_error('La difusion no existe', 404);

    $dest = db()->prepare(
        'SELECT id, usuario, correo, nombre, estado, error, enviado
           FROM difusiones_destinatarios
          WHERE difusion = :id
          ORDER BY id ASC'
    );
    $dest->execute([':id' => $id]);

    json_ok([
        'difusion'      => difusionMap($row),
        'destinatarios' => array_map(static function (array $r): array {
            $r['id']      = (int) $r['id'];
            $r['usuario'] = $r['usuario'] !== null ? (int) $r['usuario'] : null;
            return $r;
        }, $dest->fetchAll()),
    ]);
}

/**
 * `GET ?impacto=1&id=N` -> que arrastra la baja (ABM.md, "Eliminar").
 *
 * La unica FK que apunta a `difusiones` es la de los destinatarios y es
 * CASCADE, asi que no hay bloqueos: la baja siempre se puede hacer y lo que el
 * modal informa es cuantas filas de historial se van con ella.
 */
function handleImpacto(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare(
        "SELECT COUNT(*)                              AS total,
                COALESCE(SUM(estado = 'enviado'), 0)  AS enviados,
                COALESCE(SUM(estado = 'pendiente'),0) AS pendientes
           FROM difusiones_destinatarios WHERE difusion = :id"
    );
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch();

    json_ok([
        'elimina' => [
            ['label' => 'Destinatarios de la difusion', 'cantidad' => (int) $r['total']],
        ],
        'detalle' => [
            'enviados'   => (int) $r['enviados'],
            'pendientes' => (int) $r['pendientes'],
        ],
        'bloqueos' => [],
    ]);
}

/* ------------------------------------------------------------------ */
/* Alta                                                                */
/* ------------------------------------------------------------------ */

function handleCreate(): void
{
    $body = difusionBody();

    $asunto  = trim((string) ($body['asunto'] ?? ''));
    $cuerpo  = trim((string) ($body['cuerpo'] ?? ''));
    $dominio = (int) ($body['dominio'] ?? 0);
    $soloHab = ($body['habilitados'] ?? true) !== false;

    if ($asunto === '') json_error('El asunto es obligatorio', 422);
    if ($cuerpo === '') json_error('El mensaje es obligatorio', 422);
    if (mb_strlen($asunto) > DIFUSION_ASUNTO_MAX) {
        json_error('El asunto no puede superar ' . DIFUSION_ASUNTO_MAX . ' caracteres', 422);
    }
    if (mb_strlen($cuerpo) > DIFUSION_CUERPO_MAX) {
        json_error('El mensaje no puede superar ' . DIFUSION_CUERPO_MAX . ' caracteres', 422);
    }
    if ($dominio > 0) {
        $chk = db()->prepare('SELECT id FROM dominios WHERE id = :id LIMIT 1');
        $chk->execute([':id' => $dominio]);
        if (!$chk->fetch()) json_error('El dominio elegido no existe', 422);
    }

    $audiencia = difusionAudiencia($dominio, $soloHab);
    $destinos  = $audiencia['destinatarios'];
    if (!$destinos) {
        json_error('El alcance elegido no tiene ningun destinatario con correo valido', 422);
    }

    $emisor = difusionEmisorId();

    // EL ALTA Y LA CONGELACION DE LA LISTA VAN EN UNA TRANSACCION. Una difusion
    // sin sus destinatarios no es "una difusion vacia": es una campana que la
    // pantalla muestra con 0 de 0 y que nadie puede reanudar. Se escriben las
    // dos cosas o ninguna.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT INTO difusiones
                (asunto, cuerpo, dominio, estado, destinatarios, enviados, fallidos, emisor, creada)
             VALUES (:a, :c, :d, :e, :n, 0, 0, :em, NOW())'
        );
        $ins->execute([
            ':a'  => $asunto,
            ':c'  => $cuerpo,
            // `?: null` y no el entero pelado: es una FK y el 0 del sistema
            // historico ya no es un valor valido (mismo criterio que
            // `perfiles`.`registrante`). Ademas NULL significa "todos".
            ':d'  => $dominio ?: null,
            ':e'  => 'pendiente',
            ':n'  => count($destinos),
            ':em' => $emisor ?: null,
        ]);
        $difusionId = (int) $pdo->lastInsertId();

        $insDest = $pdo->prepare(
            'INSERT INTO difusiones_destinatarios (difusion, usuario, correo, nombre, estado)
             VALUES (:d, :u, :c, :n, :e)'
        );
        foreach ($destinos as $d) {
            $insDest->execute([
                ':d' => $difusionId,
                ':u' => $d['id'] ?: null,
                ':c' => $d['correo'],
                ':n' => ($d['nombre'] ?? '') !== '' ? $d['nombre'] : null,
                ':e' => 'pendiente',
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $stmt = db()->prepare(difusionSelect() . ' WHERE d.id = :id LIMIT 1');
    $stmt->execute([':id' => $difusionId]);

    json_ok([
        'difusion'    => difusionMap($stmt->fetch()),
        'descartados' => $audiencia['descartados'],
    ], 201);
}

/* ------------------------------------------------------------------ */
/* Envio                                                               */
/* ------------------------------------------------------------------ */

/**
 * `POST ?enviar=1&id=N` -> manda hasta DIFUSION_LOTE pendientes.
 *
 * Devuelve los contadores para que el front pinte la barra y decida si vuelve a
 * llamar. `pendientes = 0` cierra el ciclo.
 *
 * CADA DESTINATARIO SE MARCA APENAS VUELVE SU LLAMADA, no al terminar el lote:
 * si el proceso se corta a la mitad (timeout, red, la persona cierra la
 * pestana), lo ya mandado queda registrado y no se repite al reanudar. Es lo
 * que hace que reanudar sea seguro: sin esto, un lote interrumpido volveria a
 * mandarle a los mismos.
 */
function handleEnviar(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('SELECT id, asunto, cuerpo, estado FROM difusiones WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $dif = $stmt->fetch();
    if (!$dif) json_error('La difusion no existe', 404);

    if ($dif['estado'] === 'cancelada') {
        json_error('La difusion esta cancelada: no se puede seguir enviando', 409);
    }

    $lote = db()->prepare(
        "SELECT id, correo, nombre
           FROM difusiones_destinatarios
          WHERE difusion = :id AND estado = 'pendiente'
          ORDER BY id ASC
          LIMIT " . DIFUSION_LOTE
    );
    $lote->execute([':id' => $id]);
    $pendientes = $lote->fetchAll();

    $cuerpoHtml = difusionCuerpoHtml((string) $dif['cuerpo']);

    $marcar = db()->prepare(
        'UPDATE difusiones_destinatarios
            SET estado = :e, error = :err, enviado = :env
          WHERE id = :id'
    );

    foreach ($pendientes as $d) {
        $res = databoxCorreoEncolar([
            'destino'      => (string) $d['correo'],
            'destinatario' => trim((string) ($d['nombre'] ?? '')),
            'asunto'       => (string) $dif['asunto'],
            'cuerpo'       => $cuerpoHtml,
            'prioridad'    => 4,
            'tags'         => DIFUSION_TAG,
        ]);

        $marcar->execute([
            ':e'   => $res['ok'] ? 'enviado' : 'fallido',
            // El motivo del rechazo es lo unico que despues explica por que a
            // esa persona no le llego: se guarda recortado a la columna.
            ':err' => $res['ok'] ? null : mb_substr((string) ($res['error'] ?? 'Error desconocido'), 0, 255),
            ':env' => $res['ok'] ? date('Y-m-d H:i:s') : null,
            ':id'  => (int) $d['id'],
        ]);
    }

    $estado = difusionRecontar($id);

    json_ok($estado);
}

/**
 * Recalcula los contadores de la difusion CONTANDO SOBRE LA TABLA HIJA, nunca
 * sumandole el lote a lo que habia. Un `enviados = enviados + N` se
 * desincroniza en cuanto dos pestanas drenan la misma difusion o un lote se
 * corta a la mitad; el COUNT siempre dice la verdad y cuesta un indice.
 *
 * De paso mueve el estado de la difusion, que es derivado y no una decision
 * aparte: quedan pendientes -> `enviando`; no quedan -> `enviada` y se sella
 * `terminada`. La `cancelada` no se toca: es la unica que decidio una persona.
 */
function difusionRecontar(int $id): array
{
    $stmt = db()->prepare(
        "SELECT COUNT(*)                               AS total,
                COALESCE(SUM(estado = 'enviado'), 0)   AS enviados,
                COALESCE(SUM(estado = 'fallido'), 0)   AS fallidos,
                COALESCE(SUM(estado = 'pendiente'), 0) AS pendientes
           FROM difusiones_destinatarios WHERE difusion = :id"
    );
    $stmt->execute([':id' => $id]);
    $c = $stmt->fetch();

    $pendientes = (int) $c['pendientes'];

    $estadoActual = db()->prepare('SELECT estado FROM difusiones WHERE id = :id LIMIT 1');
    $estadoActual->execute([':id' => $id]);
    $estado = (string) $estadoActual->fetchColumn();

    if ($estado !== 'cancelada') {
        $estado = $pendientes > 0 ? 'enviando' : 'enviada';
    }

    $upd = db()->prepare(
        'UPDATE difusiones
            SET enviados = :e, fallidos = :f, estado = :s,
                terminada = CASE WHEN :p = 0 AND terminada IS NULL THEN NOW() ELSE terminada END
          WHERE id = :id'
    );
    $upd->execute([
        ':e'  => (int) $c['enviados'],
        ':f'  => (int) $c['fallidos'],
        ':s'  => $estado,
        ':p'  => $pendientes,
        ':id' => $id,
    ]);

    return [
        'id'         => $id,
        'estado'     => $estado,
        'total'      => (int) $c['total'],
        'enviados'   => (int) $c['enviados'],
        'fallidos'   => (int) $c['fallidos'],
        'pendientes' => $pendientes,
    ];
}

/**
 * `POST ?cancelar=1&id=N` -> corta el envio.
 *
 * Las filas pendientes SE QUEDAN PENDIENTES: cancelar no es descartar la lista,
 * es dejar de mandar. Lo que ya salio no se puede volver atras y la pantalla lo
 * dice; lo que faltaba queda visible en la ficha, que es la unica forma de
 * saber a quien no le llego.
 */
function handleCancelar(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('SELECT estado FROM difusiones WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $estado = $stmt->fetchColumn();
    if ($estado === false) json_error('La difusion no existe', 404);
    if ($estado === 'enviada')   json_error('La difusion ya termino de enviarse', 409);
    if ($estado === 'cancelada') json_error('La difusion ya estaba cancelada', 409);

    $upd = db()->prepare("UPDATE difusiones SET estado = 'cancelada' WHERE id = :id");
    $upd->execute([':id' => $id]);

    json_ok(['id' => $id, 'estado' => 'cancelada']);
}

/* ------------------------------------------------------------------ */
/* Baja                                                                */
/* ------------------------------------------------------------------ */

/**
 * La baja borra la difusion y, por CASCADE, su historial de destinatarios. El
 * DELETE de los hijos va explicito igual: es lo que hace que el borrado no
 * dependa de que la FK este declarada en la base que corre.
 */
function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('SELECT estado FROM difusiones WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $estado = $stmt->fetchColumn();
    if ($estado === false) json_error('La difusion no existe', 404);

    // Una difusion a medio mandar no se borra: primero se cancela. Si no, el
    // operador se queda sin la lista de a quien le llego y a quien no, que es
    // justo lo que hay que mirar despues de interrumpir un envio.
    if ($estado === 'enviando') {
        json_error('La difusion se esta enviando: cancelala antes de eliminarla', 409);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM difusiones_destinatarios WHERE difusion = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM difusiones WHERE id = :id')->execute([':id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    json_ok(['id' => $id]);
}

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/** Body JSON del request, o [] si no vino nada legible. */
function difusionBody(): array
{
    $raw  = file_get_contents('php://input');
    $body = $raw === false || $raw === '' ? [] : json_decode($raw, true);
    return is_array($body) ? $body : [];
}

/**
 * Id del controlador de la sesion, para `difusiones`.`emisor`.
 *
 * Sale del JWT, que desde el 07/09/2026 se emite contra `controladores` y lleva
 * `src = 'ctl'` (lo exige `authUser()`). Es el mismo id que la FK referencia.
 */
function difusionEmisorId(): int
{
    $u = authUser();
    return $u !== null ? (int) ($u['id'] ?? 0) : 0;
}

/**
 * Convierte el texto que escribio el operador en el fragmento HTML del correo.
 *
 * EL CUERPO SE ESCAPA Y NO SE INTERPRETA COMO HTML. El operador escribe texto
 * en un textarea; los saltos de linea se vuelven `<br>` y el resto viaja
 * escapado. Aceptar HTML crudo dejaria que un `<div>` sin cerrar rompa la
 * plantilla de Databox para los 2.000 destinatarios, y no hay pantalla de
 * previsualizacion que lo muestre antes. El dia que haga falta formato, va un
 * editor que produzca el HTML, no el permiso de pegarlo.
 *
 * Es un FRAGMENTO, no un documento: la plantilla `reactor` del microservicio lo
 * inserta en {cuerpo} y aporta encabezado y pie. Mismo criterio que
 * `invitacionCuerpoCorreo()` en el panel.
 */
function difusionCuerpoHtml(string $texto): string
{
    $escapado = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
    // Los parrafos se arman por linea en blanco; los saltos sueltos, con <br>.
    $parrafos = preg_split("/\r\n\r\n|\n\n|\r\r/", $escapado) ?: [$escapado];
    $html = '';
    foreach ($parrafos as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $html .= '<p>' . nl2br($p, false) . '</p>';
    }
    return $html !== '' ? $html : '<p></p>';
}
