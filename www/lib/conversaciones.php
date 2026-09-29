<?php

declare(strict_types=1);

/**
 * Persistencia del chat: las tablas `conversaciones` y `conversaciones_mensajes`
 * ([cloud/sql/migrations/20260928_1000_crear_conversaciones.sql]).
 *
 * Acá no hay nada de OpenAI ni del prompt: eso es `lib/chat.php`. Esto guarda,
 * lee y cuenta.
 *
 * ES LA SEGUNDA TABLA EN LA QUE ESCRIBE EL SITIO PÚBLICO, y la primera en la que
 * escribe sin que nadie complete un formulario. `lib/db.php` dice que el único
 * INSERT del docroot es el de `tecnicos`; ahora son dos. La diferencia con aquél
 * es que **cada fila de acá dispara una llamada paga a un tercero**, así que los
 * cupos no son una protección contra el spam sino contra la factura.
 *
 * EL HISTORIAL SE ARMA ACÁ Y NO CON LO QUE MANDA EL NAVEGADOR. El cliente manda
 * el `uuid` de la conversación y el texto nuevo, nada más. Si el historial
 * viajara en el POST, cualquiera podría reescribir lo que "ya se dijo" —las
 * instrucciones del sistema incluidas— y de paso hacernos pagar el contexto que
 * se le antoje.
 *
 * TODAS LAS FECHAS SALEN DE `NOW()` DE LA BASE, nunca del reloj de PHP: es la
 * misma regla que ya siguen `recuperaciones` y `enlaces_acceso`, y acá importa
 * porque las ventanas de los cupos se comparan contra esas mismas fechas.
 */

require_once __DIR__ . '/db.php';

/** Cuántos mensajes de la conversación se le mandan al modelo como contexto. */
const CONVERSACIONES_HISTORIAL = 12;

/**
 * Una conversación nueva. Devuelve `['id' => int, 'uuid' => string]`.
 *
 * `$pagina` es la URL desde donde se abrió la burbuja; se recorta a lo que entra
 * en la columna en vez de dejar que MySQL la trunque en silencio.
 */
function conversacionAbrir(string $pagina): array
{
    $uuid = conversacionUuid();

    $sql = db()->prepare(
        'INSERT INTO conversaciones (uuid, iniciada, actividad, mensajes, origen, agente, pagina)
              VALUES (:uuid, NOW(), NOW(), 0, :origen, :agente, :pagina)'
    );
    $sql->execute([
        ':uuid'   => $uuid,
        ':origen' => conversacionOrigen(),
        ':agente' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ':pagina' => mb_substr(trim($pagina), 0, 500),
    ]);

    return ['id' => (int) db()->lastInsertId(), 'uuid' => $uuid];
}

/**
 * Toma un turno de la conversación: la encuentra, le suma el mensaje y le marca
 * actividad, todo en una sentencia.
 *
 * EL CANDADO ES EL `UPDATE` CONDICIONAL Y NO ES NEGOCIABLE. `SET mensajes =
 * mensajes + 1 ... WHERE uuid = :uuid AND mensajes < :tope` valida y cuenta
 * dentro del lock de fila de InnoDB; con un `SELECT` previo, dos POST
 * simultáneos del último turno disponible pasarían los dos. Es el mismo patrón
 * con el que `enlaces_acceso` cuenta sus usos.
 *
 * @return array{id:int,mensajes:int}|null  null si el uuid no existe o si ya
 *                                          gastó el cupo de la conversación.
 */
function conversacionTomarTurno(string $uuid, int $tope): ?array
{
    $uuid = trim($uuid);
    if ($uuid === '') {
        return null;
    }

    $sql = db()->prepare(
        'UPDATE conversaciones
            SET mensajes = mensajes + 1, actividad = NOW()
          WHERE uuid = :uuid AND mensajes < :tope'
    );
    $sql->execute([':uuid' => $uuid, ':tope' => $tope]);

    if ($sql->rowCount() === 0) {
        return null;
    }

    $sql = db()->prepare('SELECT id, mensajes FROM conversaciones WHERE uuid = :uuid');
    $sql->execute([':uuid' => $uuid]);
    $fila = $sql->fetch();

    return $fila === false ? null : ['id' => (int) $fila['id'], 'mensajes' => (int) $fila['mensajes']];
}

/** true si el uuid existe. Sirve para distinguir "no existe" de "sin cupo". */
function conversacionExiste(string $uuid): bool
{
    $sql = db()->prepare('SELECT 1 FROM conversaciones WHERE uuid = :uuid');
    $sql->execute([':uuid' => trim($uuid)]);

    return $sql->fetchColumn() !== false;
}

/**
 * Guarda un mensaje.
 *
 * `$rol` son los dos valores del ENUM, que son los de la API de OpenAI: `user`
 * para la persona y `assistant` para el modelo. Las tres columnas de medición
 * (`modelo`, `contexto`, tokens) sólo aplican a la respuesta, y `origen` sólo a
 * lo que escribió la persona.
 */
function conversacionMensajeAlta(
    int $conversacion,
    string $rol,
    string $texto,
    ?string $origen = null,
    ?string $modelo = null,
    ?string $contexto = null,
    ?int $tokensEntrada = null,
    ?int $tokensSalida = null
): void {
    $sql = db()->prepare(
        'INSERT INTO conversaciones_mensajes
                (conversacion, fecha, rol, texto, origen, modelo, contexto, tokens_entrada, tokens_salida)
         VALUES (:conversacion, NOW(), :rol, :texto, :origen, :modelo, :contexto, :te, :ts)'
    );
    $sql->execute([
        ':conversacion' => $conversacion,
        ':rol'          => $rol,
        ':texto'        => $texto,
        ':origen'       => $origen,
        ':modelo'       => $modelo,
        ':contexto'     => $contexto === null ? null : mb_substr($contexto, 0, 500),
        ':te'           => $tokensEntrada,
        ':ts'           => $tokensSalida,
    ]);
}

/**
 * La URL que manda el navegador, lista para guardar — o null si no sirve.
 *
 * SE VALIDA AUNQUE SÓLO SE VAYA A MOSTRAR. Este campo lo escribe el cliente, o
 * sea cualquiera que arme el POST a mano, y lo termina leyendo un operador en el
 * panel de cloud. Ahí se imprime escapado, así que no hay XSS; lo que se evita
 * es que en esa pantalla aparezcan cadenas que *parecen* enlaces y no lo son
 * (`javascript:…`, `data:…`) o texto arbitrario disfrazado de URL.
 *
 * SÓLO SE ACEPTA `http` Y `https` Y SÓLO DEL PROPIO SITIO: el chat vive
 * únicamente en las páginas de este host, así que una URL de otro dominio no
 * puede ser cierta — o el POST no vino de acá, o alguien lo escribió a mano. En
 * los dos casos el dato no sirve y queda en NULL, que es lo que ya significa
 * "no se sabe" para las filas viejas.
 */
function conversacionOrigenValido(string $url): ?string
{
    $url = trim($url);
    if ($url === '' || mb_strlen($url) > 500) {
        return null;
    }

    $partes = parse_url($url);
    if ($partes === false || !isset($partes['scheme'], $partes['host'])) {
        return null;
    }
    if (!in_array(strtolower($partes['scheme']), ['http', 'https'], true)) {
        return null;
    }

    // El host del request ya viene sin puerto en `HTTP_HOST` sólo a veces, así
    // que se lo saca a los dos lados antes de comparar.
    $propio = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($propio === '' || strtolower($partes['host']) !== $propio) {
        return null;
    }

    return $url;
}

/**
 * Los últimos mensajes de la conversación, del más viejo al más nuevo, con la
 * forma que espera la API (`['role' => ..., 'content' => ...]`).
 *
 * El `ORDER BY id` y no `fecha`: dos mensajes del mismo segundo tienen la misma
 * `fecha` —la pregunta y la respuesta suelen estar a segundos— y el orden
 * quedaría librado al motor. Se pide `DESC` con `LIMIT` para quedarse con los
 * ÚLTIMOS y recién ahí se da vuelta: un `ASC` con límite devolvería el arranque
 * de la conversación y perdería lo que se acaba de decir.
 */
function conversacionHistorial(int $conversacion, int $limite = CONVERSACIONES_HISTORIAL): array
{
    $limite = max(1, min(50, $limite));

    // El límite va interpolado y no bindeado: MySQL no acepta un placeholder en
    // LIMIT con `EMULATE_PREPARES => false`. Ya quedó acotado a un entero.
    $sql = db()->prepare(
        'SELECT rol, texto
           FROM conversaciones_mensajes
          WHERE conversacion = :conversacion
          ORDER BY id DESC
          LIMIT ' . $limite
    );
    $sql->execute([':conversacion' => $conversacion]);

    $mensajes = [];
    foreach (array_reverse($sql->fetchAll()) as $fila) {
        $mensajes[] = ['role' => (string) $fila['rol'], 'content' => (string) $fila['texto']];
    }

    return $mensajes;
}

/** Mensajes de una IP en la última hora. Es el cupo que frena a un solo abusador. */
function conversacionesPorOrigen(): int
{
    $origen = conversacionOrigen();
    if ($origen === null) {
        return 0;
    }

    $sql = db()->prepare(
        'SELECT COUNT(*)
           FROM conversaciones_mensajes m
           JOIN conversaciones c ON c.id = m.conversacion
          WHERE c.origen = :origen
            AND m.rol = :rol
            AND m.fecha > NOW() - INTERVAL 1 HOUR'
    );
    $sql->execute([':origen' => $origen, ':rol' => 'user']);

    return (int) $sql->fetchColumn();
}

/**
 * Mensajes de todo el sitio en el día corriente. Es el freno de última
 * instancia: si alguien descubre el endpoint y lo usa de proxy gratis, lo que
 * corta el gasto es este número y no el cupo por IP (que se esquiva rotando IP).
 */
function conversacionesDelDia(): int
{
    $sql = db()->prepare(
        'SELECT COUNT(*)
           FROM conversaciones_mensajes
          WHERE rol = :rol AND fecha >= CURDATE()'
    );
    $sql->execute([':rol' => 'user']);

    return (int) $sql->fetchColumn();
}

/**
 * La IP de quien está del otro lado, o null si no consta.
 *
 * SE LEE `REMOTE_ADDR` Y NADA MÁS. `X-Forwarded-For` la escribe el cliente, así
 * que usarla para el cupo sería regalar la forma de esquivarlo: una cabecera
 * distinta por request y el límite por IP no cuenta nunca dos veces lo mismo. Si
 * algún día el sitio queda detrás de un proxy o de un CDN, hay que leer la
 * cabecera que ESE proxy garantiza y sólo si el request viene de él.
 */
function conversacionOrigen(): ?string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    return $ip === '' ? null : mb_substr($ip, 0, 45);
}

/**
 * Un UUID v4 con `random_bytes()`.
 *
 * El CSPRNG y no `uniqid()`: el uuid es lo único que identifica la conversación,
 * así que uno adivinable dejaría leer —y continuar— la conversación de otro.
 */
function conversacionUuid(): string
{
    $bytes = random_bytes(16);

    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // versión 4
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variante RFC 4122

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}
