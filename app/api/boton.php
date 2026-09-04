<?php

declare(strict_types=1);

/**
 * Apretar un botón del panel: manda la orden al equipo por MQTT.
 *
 * POST, body {"boton": <id>}.
 *
 * Port de `reactor-app/panel/procesar.php` + `cCanal::encender()` / `apagar()`
 * / `invertir()` (`reactor-api/framework/subframework.php`). La cadena es la
 * misma y el mensaje que sale al aire es byte por byte el mismo, así que los
 * equipos que hay en la calle no notan la diferencia:
 *
 *   1. Resolver el botón -> su canal -> su dispositivo.
 *   2. Componer `CMD=CEN|CNL=<n>` (encender) o `CMD=CAP|CNL=<n>` (apagar).
 *   3. Registrar la señal saliente en `senales`.
 *   4. Publicar en el topic del equipo (`$` + `dispositivos.identidad`).
 *   5. Anotar el uso en `registros`.
 *
 * LO QUE ESTE ENDPOINT NO HACE: tocar `canales.estado`. No es un olvido —el
 * legacy tampoco lo toca— y es lo que hace que el tablero diga la verdad. El
 * estado lo escribe el motor Python (`reactor-api/motor/inicio.py`) recién
 * cuando el equipo REPORTA que ejecutó la orden (`REP=CEN` / `REP=CAP`). Si la
 * app lo escribiera al mandar el comando, un equipo desconectado se vería
 * encendido en la pantalla sin que la luz haya cambiado. El motor sigue
 * corriendo tal cual está y no se modificó nada de él.
 *
 * DIFERENCIAS DELIBERADAS CON EL LEGACY
 *
 * - **Pide sesión.** En `procesar.php` la línea `$xAcceso->controlar();` está
 *   comentada: el endpoint es público y la única credencial es el uuid del
 *   botón, que va impreso en el HTML del panel. Acá se exige sesión y además
 *   se comprueba que el botón sea del dominio del usuario, así un id ajeno no
 *   opera el equipo de otro cliente.
 * - **El botón se identifica por id, no por uuid**, igual que el resto de los
 *   endpoints de esta app.
 * - **La señal se registra DESPUÉS de publicar.** El legacy la registra antes,
 *   así que si el broker no contesta, `senales` igual dice que la orden salió.
 * - **Si el publish falla, el usuario se entera.** El legacy hace
 *   `echo "Imposible conectar (...)"` dentro del div oculto donde jQuery
 *   inyecta la respuesta: nadie lo ve nunca.
 */

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/contexto.php';
require_once dirname(__DIR__) . '/lib/mqtt.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $status, array $cuerpo): never
{
    http_response_code($status);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    responder(405, ['ok' => false, 'error' => 'Método no permitido.']);
}

$sesion = appUser();
if ($sesion === null) {
    responder(401, ['ok' => false, 'error' => 'Sesión vencida. Volvé a ingresar.']);
}

$crudo   = file_get_contents('php://input');
$entrada = json_decode($crudo !== false ? $crudo : '', true);
if (!is_array($entrada)) {
    responder(400, ['ok' => false, 'error' => 'Pedido inválido.']);
}

$botonId = (int) ($entrada['boton'] ?? 0);
if ($botonId <= 0) {
    responder(400, ['ok' => false, 'error' => 'Falta el botón.']);
}

try {
    $ctx = appContextoSesion($sesion);
    if ($ctx['dominio'] <= 0) {
        responder(409, ['ok' => false, 'error' => 'Tu cuenta no tiene un dominio activo.']);
    }

    // Botón -> canal -> dispositivo, en una sola lectura y ya filtrado por el
    // dominio de la sesión.
    //
    // El filtro va por `dispositivos.dominio` y no por `botones.dominio`:
    // `botones` arrastra su propia copia del dominio del sistema viejo, y el
    // dueño real del equipo —el que decide quién puede operarlo— es el de
    // `dispositivos`. Si las dos columnas discrepan, la que manda es ésta.
    $stmt = db()->prepare(
        'SELECT b.id, b.accion, b.texto,
                c.id AS canal_id, c.canal, c.habilitado AS canal_habilitado,
                d.id AS dispositivo, d.identidad, d.transceptor,
                d.habilitado AS dispositivo_habilitado, d.dominio
         FROM botones b
         LEFT JOIN canales      c ON c.id = b.canal
         LEFT JOIN dispositivos d ON d.id = c.dispositivo
         WHERE b.id = :b AND b.habilitado = \'1\' AND d.dominio = :dom
         LIMIT 1'
    );
    $stmt->execute([':b' => $botonId, ':dom' => $ctx['dominio']]);
    $boton = $stmt->fetch();

    if (!$boton) {
        responder(404, ['ok' => false, 'error' => 'Ese botón no es de este dominio.']);
    }

    // Las mismas dos guardas que `cCanal::encender()`.
    if ((int) $boton['canal_habilitado'] !== 1) {
        responder(409, ['ok' => false, 'error' => 'El canal está deshabilitado.']);
    }
    if ((string) $boton['dispositivo_habilitado'] !== '1') {
        responder(409, ['ok' => false, 'error' => 'El equipo está deshabilitado.']);
    }

    $identidad = trim((string) ($boton['identidad'] ?? ''));
    if ($identidad === '') {
        responder(409, ['ok' => false, 'error' => 'El equipo no tiene identidad asignada.']);
    }

    $canal = (int) $boton['canal'];

    // `botones.accion`: '1' encender, '0' apagar, '2' invertir.
    //
    // Invertir se resuelve leyendo `canales.estado` —el que mantiene el motor—
    // y mandando el comando contrario, que es lo que hace `cCanal::invertir()`.
    // En los datos actuales no hay ningún botón con accion='2' (98 son '1' y
    // 22 son '0'), así que esta rama nace sin uso; se porta igual para no
    // dejar un botón mudo si alguien crea uno desde el back office.
    $accion = (string) $boton['accion'];
    if ($accion === '2') {
        $est = db()->prepare('SELECT estado FROM canales WHERE id = :c');
        $est->execute([':c' => (int) $boton['canal_id']]);
        $accion = trim((string) $est->fetchColumn()) === '1' ? '0' : '1';
    }

    if ($accion !== '1' && $accion !== '0') {
        responder(409, ['ok' => false, 'error' => 'El botón no tiene una acción válida.']);
    }

    $comando = $accion === '1' ? 'CEN' : 'CAP';
    $mensaje = 'CMD=' . $comando . '|CNL=' . $canal;

    // El topic lleva el `$` adelante, igual que `cTransceptor::enviar()`.
    // En `senales` se guarda sin él, como hace `cSenal::registrar()`, para que
    // las filas que escribe esta app se lean igual que las del legacy.
    $topic = '$' . $identidad;

    // El transceptor decide a qué broker se conecta: la flota está repartida
    // entre varios (242 equipos en uno, 8 en otro) y un solo host no los
    // alcanza a todos. Ver `mqttBroker()`.
    try {
        mqttPublicar($topic, $mensaje, (int) $boton['transceptor']);
    } catch (MqttError $e) {
        responder(502, ['ok' => false, 'error' => 'No se pudo entregar la orden al equipo. ' . $e->getMessage()]);
    }

    // Recién ahora, con la orden ya entregada, se deja el rastro.
    $ins = db()->prepare(
        'INSERT INTO senales (serie, fecha, sentido, transceptor, dispositivo, canal, topic, mensaje, estado)
         VALUES (0, NOW(), \'S\', :t, :d, :c, :topic, :m, 2)'
    );
    $ins->execute([
        ':t'     => (int) $boton['transceptor'],
        ':d'     => (int) $boton['dispositivo'],
        ':c'     => (int) $boton['canal_id'],
        ':topic' => $identidad,
        ':m'     => $mensaje,
    ]);

    // `registros` es el "quién apretó qué", que alimenta el modal Actividad.
    // El legacy lo escribe sólo para las acciones 1 y 0 —invertir no deja
    // rastro—; acá lo escriben las tres, porque a esta altura `$accion` ya se
    // resolvió a encender o apagar.
    $reg = db()->prepare(
        'INSERT INTO registros (fecha, sentido, usuario, dominio, dispositivo, canal, estado)
         VALUES (NOW(), \'S\', :u, :dom, :d, :c, :e)'
    );
    $reg->execute([
        ':u'   => (int) $sesion['id'],
        ':dom' => (int) $boton['dominio'],
        ':d'   => (int) $boton['dispositivo'],
        ':c'   => (int) $boton['canal_id'],
        ':e'   => $accion,
    ]);

    responder(200, [
        'ok'      => true,
        'accion'  => $accion,
        'canal'   => $canal,
        'mensaje' => $mensaje,
        // El estado NO viaja de vuelta a propósito: todavía no cambió. Lo va a
        // reflejar el sondeo de `api/canales` cuando el equipo reporte.
    ]);
} catch (Throwable $_) {
    responder(500, ['ok' => false, 'error' => 'No se pudo procesar el botón.']);
}
