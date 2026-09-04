<?php

declare(strict_types=1);

/**
 * Publicador MQTT 3.1.1 mínimo — sin Composer, sin extensiones.
 *
 * Es el port de `cTransceptor::enviar()` del legacy
 * (`reactor-api/framework/subframework.php`), que usa la librería phpMQTT de
 * Bluerhinos. Acá se implementan sólo los tres paquetes que hacen falta para
 * mandar una orden a un equipo —CONNECT, PUBLISH con QoS 0 y DISCONNECT— en
 * vez de arrastrar las 423 líneas de esa librería, que además trae suscripción
 * y bucle de recepción: la app **no** escucha el broker. Todo lo que entra lo
 * sigue procesando el motor Python (`reactor-api/motor/inicio.py`), que no se
 * toca.
 *
 * Va sobre `stream_socket_client()`, que es del core de PHP: la imagen no
 * tiene ni `ext-sockets` ni ninguna extensión de MQTT, y no las necesita.
 *
 * DE DÓNDE SALE EL BROKER — hay dos fuentes y la elección importa.
 *
 * 1. `transceptores`, por `dispositivos.transceptor`: es lo que hace
 *    `cTransceptor::enviar()`. Cada equipo dice a qué broker está conectado,
 *    y **la flota está repartida**: 242 equipos en `iot.reactor.com.ar:16273`
 *    y 8 en `korolev.reactor.com.ar:16273`. Es la única fuente que puede
 *    alcanzarlos a todos.
 *
 * 2. Las variables `MQTT_*` del entorno: un solo broker para todo.
 *
 * Esto arrancó usando (2) por una razón de seguridad válida —la base de
 * desarrollo es copia de producción, así que sus `transceptores` apuntan al
 * broker REAL y un botón apretado desde dev le encendería la luz a un
 * cliente—, pero estaba mal: `MQTT_HOST` de producción es
 * `radar.reactor.com.ar` (44.218.24.249) y los equipos están en
 * `iot.reactor.com.ar` (3.89.175.20). Son máquinas distintas. El mensaje salía
 * impecable a un broker donde no hay ningún equipo escuchando, y como la fila
 * de `senales` se escribe igual, desde la base se veía idéntico al del legacy.
 * Además un solo host no puede cubrir una flota repartida en dos brokers.
 *
 * Ahora manda `transceptores`, igual que el legacy, y las `MQTT_*` quedan como
 * REDIRECCIÓN explícita para no tocar equipos reales desde un entorno de
 * pruebas. Lo decide `MQTT_ORIGEN`:
 *
 *   MQTT_ORIGEN=transceptores  -> el broker de cada equipo (comportamiento legacy)
 *   MQTT_ORIGEN=env            -> todo al broker de las MQTT_*, sin tocar la calle
 *
 * Si la variable no está, se deduce de `APP_ENV`: producción usa
 * `transceptores` y cualquier otro entorno usa `env`. El default seguro es el
 * de desarrollo: para mandarle a un equipo real hay que pedirlo explícitamente.
 */

/** Excepción de transporte: no se pudo entregar el mensaje al broker. */
class MqttError extends RuntimeException
{
}

/**
 * Datos de conexión del broker que corresponde a un transceptor.
 *
 * @param int $transceptor `dispositivos.transceptor`. Se ignora cuando el
 *                         entorno redirige todo a las `MQTT_*`.
 * @return array{host:string, port:int, user:string, pass:string, origen:string}
 */
function mqttBroker(int $transceptor): array
{
    $origen = strtolower(trim((string) getenv('MQTT_ORIGEN')));
    if ($origen === '') {
        $origen = (defined('APP_ENV') && APP_ENV === 'production') ? 'transceptores' : 'env';
    }

    if ($origen === 'transceptores' && $transceptor > 0) {
        require_once __DIR__ . '/db.php';
        $stmt = db()->prepare(
            'SELECT host, puerto, usuario, contrasena FROM transceptores WHERE id = :t LIMIT 1'
        );
        $stmt->execute([':t' => $transceptor]);
        $row = $stmt->fetch();

        if ($row && trim((string) $row['host']) !== '') {
            return [
                'host'   => trim((string) $row['host']),
                'port'   => (int) $row['puerto'],
                'user'   => (string) ($row['usuario'] ?? ''),
                'pass'   => (string) ($row['contrasena'] ?? ''),
                'origen' => 'transceptores',
            ];
        }

        // Un equipo sin transceptor válido no se manda al broker de pruebas
        // por las dudas: se corta acá, con el motivo dicho.
        throw new MqttError('El equipo no tiene un transceptor válido asignado (id ' . $transceptor . ').');
    }

    return [
        'host'   => (string) getenv('MQTT_HOST'),
        'port'   => (int) getenv('MQTT_PORT'),
        'user'   => (string) getenv('MQTT_USER'),
        'pass'   => (string) getenv('MQTT_PASS'),
        'origen' => 'env',
    ];
}

/**
 * Conecta, publica un mensaje con QoS 0 y cierra.
 *
 * Igual que el legacy: una conexión por mensaje. No es lo más eficiente
 * —suma un handshake TCP+MQTT a cada botón— pero es lo que hay que hacer sin
 * un proceso residente, y mantiene el comportamiento observable idéntico.
 *
 * @param int $transceptor `dispositivos.transceptor` del equipo destinatario.
 *                         Define a qué broker se conecta (ver `mqttBroker()`).
 *
 * @throws MqttError si no se puede conectar, el broker rechaza las
 *                   credenciales o se corta la escritura.
 */
function mqttPublicar(string $topic, string $mensaje, int $transceptor = 0, int $timeout = 5): void
{
    $broker = mqttBroker($transceptor);

    $host = $broker['host'];
    $port = $broker['port'];
    $user = $broker['user'];
    $pass = $broker['pass'];

    if ($host === '' || $port <= 0) {
        throw new MqttError('El broker MQTT no está configurado (MQTT_HOST / MQTT_PORT).');
    }

    $socket = @stream_socket_client(
        'tcp://' . $host . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );
    if ($socket === false) {
        throw new MqttError(sprintf('No se pudo conectar al broker (%s:%d): %s', $host, $port, $errstr));
    }

    stream_set_timeout($socket, $timeout);

    try {
        // El client id tiene que ser único: dos clientes con el mismo id se
        // desconectan mutuamente. El legacy usa "ClientID".rand(); acá se le
        // suma el pid para que dos requests del mismo segundo no choquen.
        $clientId = 'reactor-app-' . getmypid() . '-' . bin2hex(random_bytes(4));

        mqttEscribir($socket, mqttPaqueteConnect($clientId, $user, $pass));
        mqttLeerConnack($socket);
        mqttEscribir($socket, mqttPaquetePublish($topic, $mensaje));

        // DISCONNECT: avisa al broker que el cierre es limpio, así no queda
        // registrado como caída del cliente.
        mqttEscribir($socket, "\xE0\x00");
    } finally {
        fclose($socket);
    }
}

// -----------------------------------------------------------------------
// Armado de paquetes (MQTT 3.1.1 — OASIS standard, sección 3)
// -----------------------------------------------------------------------

/** Campo de longitud variable: 7 bits por byte, el bit alto marca que sigue. */
function mqttLongitud(int $n): string
{
    $out = '';
    do {
        $byte = $n % 128;
        $n = intdiv($n, 128);
        if ($n > 0) {
            $byte |= 0x80;
        }
        $out .= chr($byte);
    } while ($n > 0);

    return $out;
}

/** String MQTT: 2 bytes de longitud big-endian + los bytes UTF-8. */
function mqttCadena(string $s): string
{
    return pack('n', strlen($s)) . $s;
}

/** CONNECT (§3.1). Clean session, con usuario y contraseña si los hay. */
function mqttPaqueteConnect(string $clientId, string $user, string $pass): string
{
    // 0x02 = clean session. El bit de usuario (0x80) y el de contraseña (0x40)
    // sólo se prenden si el valor viene: prenderlos con el campo vacío hace
    // que el broker rechace la conexión con "identificador incorrecto".
    $flags = 0x02;
    if ($user !== '') {
        $flags |= 0x80;
    }
    if ($pass !== '') {
        $flags |= 0x40;
    }

    $variable = mqttCadena('MQTT')      // nombre del protocolo
        . chr(0x04)                     // nivel 4 = 3.1.1
        . chr($flags)
        . pack('n', 60);                // keep alive (segundos)

    $payload = mqttCadena($clientId);
    if ($user !== '') {
        $payload .= mqttCadena($user);
    }
    if ($pass !== '') {
        $payload .= mqttCadena($pass);
    }

    $cuerpo = $variable . $payload;

    return chr(0x10) . mqttLongitud(strlen($cuerpo)) . $cuerpo;
}

/**
 * PUBLISH con QoS 0 (§3.3), igual que el legacy.
 *
 * Con QoS 0 el paquete no lleva identificador ni el broker lo confirma: se
 * manda y listo. Es "at most once" — si el mensaje se pierde en el camino,
 * nadie se entera. Es lo que viene haciendo el sistema desde siempre y lo que
 * esperan los equipos; subirlo a QoS 1 es una decisión aparte, que además
 * obliga a esperar el PUBACK.
 */
function mqttPaquetePublish(string $topic, string $mensaje): string
{
    $cuerpo = mqttCadena($topic) . $mensaje;

    return chr(0x30) . mqttLongitud(strlen($cuerpo)) . $cuerpo;
}

// -----------------------------------------------------------------------
// Socket
// -----------------------------------------------------------------------

function mqttEscribir($socket, string $datos): void
{
    $total = strlen($datos);
    $escrito = 0;
    while ($escrito < $total) {
        $n = @fwrite($socket, substr($datos, $escrito));
        if ($n === false || $n === 0) {
            throw new MqttError('Se cortó la conexión con el broker al escribir.');
        }
        $escrito += $n;
    }
}

/**
 * Lee el CONNACK (§3.2) y valida el código de retorno.
 *
 * Es el único paquete que se espera de vuelta. Sin esta lectura, unas
 * credenciales mal puestas se verían como un envío exitoso: el PUBLISH se
 * escribiría en un socket que el broker está por cerrar y no llegaría a
 * ningún lado.
 */
function mqttLeerConnack($socket): void
{
    $resp = '';
    while (strlen($resp) < 4) {
        $chunk = @fread($socket, 4 - strlen($resp));
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($socket);
            throw new MqttError(!empty($meta['timed_out'])
                ? 'El broker no respondió al CONNECT.'
                : 'El broker cerró la conexión durante el CONNECT.');
        }
        $resp .= $chunk;
    }

    if (ord($resp[0]) !== 0x20) {
        throw new MqttError('El broker respondió algo que no es un CONNACK.');
    }

    $codigo = ord($resp[3]);
    if ($codigo !== 0) {
        throw new MqttError(match ($codigo) {
            1       => 'El broker rechazó la versión del protocolo.',
            2       => 'El broker rechazó el identificador del cliente.',
            3       => 'El servicio MQTT no está disponible.',
            4       => 'Usuario o contraseña del broker incorrectos.',
            5       => 'El broker no autorizó la conexión.',
            default => 'El broker rechazó la conexión (código ' . $codigo . ').',
        });
    }
}
