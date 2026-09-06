<?php

declare(strict_types=1);

/**
 * Piezas compartidas del ciclo de invitaciones del panel.
 *
 * Las usan el endpoint del alta (api/invitaciones.php) y las tres paginas
 * publicas de invitacion/ (ver, aceptar, rechazar), que no pasan por el
 * bootstrap de la API porque no exigen sesion.
 *
 * El ciclo es el mismo que el del legacy (cInvitacion en
 * reactor-api/framework/subframework.php), con dos diferencias:
 *   - el envio va por correo (Databox v4) en vez de WhatsApp;
 *   - el enlace apunta a panel.reactor.com.ar, no a app.reactor.com.ar.
 * El legacy queda intacto y sigue funcionando sobre las mismas tablas.
 */

// env.php es idempotente (guard SECRETS_LOADED). Se requiere aca y no solo
// desde el caller porque abajo se leen APP_ENV y las constantes de Databox:
// depender del orden de includes de cada punto de entrada es una trampa.
require_once dirname(__DIR__, 2) . '/env.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/databox.php';
require_once __DIR__ . '/base_url.php';

/* Codigos de `invitaciones.estado` (varchar(1)), heredados del legacy. */
const INVITACION_PENDIENTE = '1';
const INVITACION_RECHAZADA = '2';
const INVITACION_ACEPTADA  = '3';
const INVITACION_ANULADA   = '0';

/** Centinela historico de "sin fecha" del sistema viejo. */
const INVITACION_SIN_FECHA = '1500-01-01 00:00:00';

/** Largo del uuid publico. La columna es varchar(16); el legacy usa 10. */
const INVITACION_UUID_LARGO = 10;

/**
 * Enlace publico que recibe el invitado.
 * `panelBaseUrl()` vive en lib/base_url.php: la comparte con el enlace de
 * recuperacion de contrasena, que tiene la misma regla de host fijo.
 */
function invitacionUrl(string $uuid): string
{
    return panelBaseUrl() . '/invitacion/?uid=' . rawurlencode($uuid);
}

/**
 * uuid publico nuevo, unico en la tabla.
 *
 * `invitaciones.uuid` no tiene UNIQUE en la base, asi que la unicidad se
 * comprueba aca. Se usa random_int (CSPRNG) y no rand() como el legacy:
 * el uuid es la unica credencial del enlace, adivinarlo es entrar.
 */
function invitacionUuidNuevo(PDO $pdo): string
{
    $alfabeto = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $tope     = strlen($alfabeto) - 1;
    $stmt     = $pdo->prepare('SELECT id FROM invitaciones WHERE uuid = :u LIMIT 1');

    for ($intento = 0; $intento < 10; $intento++) {
        $uuid = '';
        for ($i = 0; $i < INVITACION_UUID_LARGO; $i++) {
            $uuid .= $alfabeto[random_int(0, $tope)];
        }
        $stmt->execute([':u' => $uuid]);
        if (!$stmt->fetchColumn()) {
            return $uuid;
        }
    }

    throw new RuntimeException('No se pudo generar un identificador unico para la invitacion');
}

/**
 * Lee una invitacion por su uuid publico. Devuelve null si no existe.
 * No filtra por dominio: el uuid ES la credencial (igual que en el legacy).
 */
function invitacionPorUuid(string $uuid): ?array
{
    if ($uuid === '' || !preg_match('/^[A-Za-z0-9]{1,16}$/', $uuid)) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT i.id, i.uuid, i.dominio, i.emisor, i.nombre, i.celular, i.correo,
                i.emitida, i.abierta, i.estado,
                d.nombre AS dominio_nombre,
                u.nombre AS emisor_nombre
         FROM invitaciones i
         LEFT JOIN dominios d ON d.id = i.dominio
         LEFT JOIN usuarios u ON u.id = i.emisor
         WHERE i.uuid = :u
         LIMIT 1'
    );
    $stmt->execute([':u' => $uuid]);

    return $stmt->fetch() ?: null;
}

/**
 * Motivo por el que una invitacion no se puede usar, o null si esta vigente.
 * Centralizado para que las tres paginas publicas den el mismo mensaje.
 */
function invitacionMotivoNoVigente(array $inv): ?string
{
    switch ((string) $inv['estado']) {
        case INVITACION_ACEPTADA:  return 'Esta invitación ya fue aceptada.';
        case INVITACION_RECHAZADA: return 'Esta invitación fue rechazada.';
        case INVITACION_ANULADA:   return 'Esta invitación fue anulada.';
        case INVITACION_PENDIENTE: return null;
        default:                   return 'Esta invitación no está disponible.';
    }
}

/**
 * Cuerpo HTML del correo de invitacion.
 *
 * Es un fragmento, no un documento: la plantilla `reactor` de Databox lo
 * inserta en {cuerpo} y aporta el encabezado y el pie. Si se desactiva la
 * plantilla (DATABOX_PLANTILLA= en el .env) el fragmento igual se lee bien.
 */
function invitacionCuerpoCorreo(string $emisor, string $dominio, string $url): string
{
    $emisorHtml  = htmlspecialchars($emisor,  ENT_QUOTES, 'UTF-8');
    $dominioHtml = htmlspecialchars($dominio, ENT_QUOTES, 'UTF-8');
    $urlHtml     = htmlspecialchars($url,     ENT_QUOTES, 'UTF-8');

    return '<p><strong>' . $emisorHtml . '</strong> te envió una invitación para ingresar '
         . 'al dominio de Reactor <strong>' . $dominioHtml . '</strong>.</p>'
         . '<p>Para aceptarla, abrí el siguiente enlace:</p>'
         . '<p><a href="' . $urlHtml . '">' . $urlHtml . '</a></p>'
         . '<p>Si no esperabas esta invitación, podés ignorar este mensaje.</p>';
}

/**
 * Cuerpo HTML del correo con las credenciales, que sale cuando el invitado
 * acepta y se le crea la cuenta.
 *
 * La contrasena viaja en claro porque el sistema la guarda de forma
 * reversible (cifrado historico de Reactor) y no hay pantalla de "definir
 * contrasena": es el mismo criterio que usa el legacy en
 * reactor-app/sesion/recuperar.php, que manda la contrasena por correo.
 */
function invitacionCuerpoCredenciales(string $dominio, string $usuario, string $contrasena, string $url): string
{
    $dominioHtml = htmlspecialchars($dominio,    ENT_QUOTES, 'UTF-8');
    $usuarioHtml = htmlspecialchars($usuario,    ENT_QUOTES, 'UTF-8');
    $claveHtml   = htmlspecialchars($contrasena, ENT_QUOTES, 'UTF-8');
    $urlHtml     = htmlspecialchars($url,        ENT_QUOTES, 'UTF-8');

    return '<p>Tu cuenta en el dominio <strong>' . $dominioHtml . '</strong> ya está activa.</p>'
         . '<p>Estos son tus datos de acceso:</p>'
         . '<ul>'
         . '<li>Usuario: <strong>' . $usuarioHtml . '</strong></li>'
         . '<li>Contraseña: <strong>' . $claveHtml . '</strong></li>'
         . '</ul>'
         . '<p>Ingresá desde <a href="' . $urlHtml . '">' . $urlHtml . '</a>.</p>'
         . '<p>Te recomendamos cambiar la contraseña después del primer ingreso.</p>';
}

/**
 * Encola el correo de la invitacion. Devuelve el resultado de databox tal
 * cual (['ok' => bool, 'id' => ?int, 'error' => ?string]).
 */
function invitacionEnviarCorreo(array $inv): array
{
    $dominio = trim((string) ($inv['dominio_nombre'] ?? '')) ?: ('#' . (int) $inv['dominio']);
    $emisor  = trim((string) ($inv['emisor_nombre']  ?? '')) ?: 'Un usuario del dominio';
    $url     = invitacionUrl((string) $inv['uuid']);

    return databoxCorreoEncolar([
        'destino'      => (string) $inv['correo'],
        'destinatario' => trim((string) ($inv['nombre'] ?? '')),
        'asunto'       => 'Invitación para ingresar a ' . $dominio,
        'cuerpo'       => invitacionCuerpoCorreo($emisor, $dominio, $url),
        'prioridad'    => 4,
        'tags'         => 'invitacion',
    ]);
}
