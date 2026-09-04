<?php

declare(strict_types=1);

/**
 * Sesión de la app end-user.
 *
 * DOS TOKENS EN JUEGO
 *
 *   1. El token PROPIO de esta app: cookie `reactor_app_token`, HS256 firmado
 *      con APP_KEY_APP. Es el que emitimos de acá en adelante.
 *
 *   2. El token LEGACY: cookie `sesionToken`, HS256 firmado con
 *      APP_KEY_APP_LEGACY, payload `{usr, trm, vto}`. Es el que YA tienen
 *      guardado los celulares de los usuarios actuales, emitido por
 *      `reactor-app/sesion/*.php` del legacy.
 *
 * Como esta app se publica en el mismo host que la legacy
 * (app.reactor.com.ar) y la cookie legacy es de path `/` y dura un año, el
 * navegador nos la manda igual. `appUser()` la adopta: valida la firma, saca
 * el id de usuario y emite el token propio. Resultado: el día que esto salga a
 * producción, los usuarios entran con la sesión ya iniciada, sin volver a
 * loguearse.
 *
 * POR QUÉ NO SE VALIDA `vto`
 *
 *   El legacy pone `vto` = ahora + 15 minutos, pero `cAcceso::controlar()`
 *   nunca llama a `validate()`: solo decodifica y usa `usr`. Por eso las
 *   sesiones de los celulares duran años. Si acá exigiéramos `vto`,
 *   invalidaríamos TODOS los tokens existentes y el efecto sería exactamente
 *   el contrario al buscado. Por eso `jwt_verify(..., false)`: firma sí,
 *   vencimiento no.
 *
 * DURACIÓN
 *
 *   La cookie legacy dura 1 año y su token no vence nunca en la práctica. Para
 *   no degradar la experiencia (una PWA en el celular que se cierra sola sería
 *   una regresión), el token propio usa el mismo horizonte de 1 año y se
 *   renueva en cada visita.
 */

require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/db.php';

/** Cookie del token propio de la app. */
const APP_COOKIE = 'reactor_app_token';

/** Cookie del token legacy que ya tienen los celulares. */
const APP_COOKIE_LEGACY = 'sesionToken';

/** Cookie temporal del login en 2 pasos (usuario -> contraseña/código). */
const APP_COOKIE_LOGIN = 'reactor_app_login';

/** Duración de la sesión: 1 año, igual que la cookie legacy. */
const APP_SESION_TTL = 60 * 60 * 24 * 365;

/** Ventana para completar el segundo paso del login. */
const APP_LOGIN_TTL = 60 * 10;

// -----------------------------------------------------------------------
// Usuario de la sesión
// -----------------------------------------------------------------------

/**
 * Usuario autenticado o null. Cacheado por request.
 *
 * Orden: token propio -> token legacy (adopción). En los dos casos se releen
 * los datos de la base, así que un usuario deshabilitado deja de entrar en la
 * siguiente carga de página sin esperar a que venza ningún token.
 */
function appUser(): ?array
{
    static $resuelto = false;
    static $usuario  = null;
    if ($resuelto) {
        return $usuario;
    }
    $resuelto = true;

    $id = appTokenUsuarioId((string) ($_COOKIE[APP_COOKIE] ?? ''));
    if ($id > 0) {
        $usuario = appUsuarioVigente($id);
        if ($usuario !== null) {
            return $usuario;
        }
    }

    $usuario = appAdoptarSesionLegacy();
    return $usuario;
}

function requireAuth(): array
{
    $u = appUser();
    if ($u !== null) {
        return $u;
    }
    header('Location: /sesion/iniciar');
    exit;
}

/**
 * Lee el id de usuario de un token propio. 0 si falta o no valida.
 */
function appTokenUsuarioId(string $token): int
{
    $payload = appTokenPayload();
    return $payload === null ? 0 : (int) ($payload['uid'] ?? 0);
}

/**
 * Payload del token propio, o null si falta / no valida. Cacheado por request.
 *
 * Es el equivalente del `$_SESSION` del legacy: acá no hay sesión de servidor,
 * así que el estado de sesión —qué perfil, qué dominio y qué panel tiene
 * abierto el usuario— viaja adentro del token, en los claims `per` / `dom` /
 * `pan`. Ver `appContextoSesion()` en contexto.php, que es quien los lee.
 */
function appTokenPayload(): ?array
{
    static $resuelto = false;
    static $payload  = null;
    if ($resuelto) {
        return $payload;
    }
    $resuelto = true;

    $token = (string) ($_COOKIE[APP_COOKIE] ?? '');
    if ($token === '') {
        return null;
    }

    $payload = jwt_verify($token, APP_KEY_APP);
    return $payload;
}

/**
 * Adopta la sesión legacy: valida `sesionToken`, y si tiene un usuario válido
 * emite el token propio y devuelve el usuario.
 */
function appAdoptarSesionLegacy(): ?array
{
    $token = (string) ($_COOKIE[APP_COOKIE_LEGACY] ?? '');
    if ($token === '') {
        return null;
    }

    // `false`: la firma tiene que validar, el vencimiento NO se exige.
    // Ver el comentario de cabecera.
    $payload = jwt_verify($token, APP_KEY_APP_LEGACY, false);
    if ($payload === null) {
        return null;
    }

    $id = (int) ($payload['usr'] ?? 0);
    if ($id <= 0) {
        return null;
    }

    $usuario = appUsuarioVigente($id);
    if ($usuario === null) {
        return null;
    }

    appSesionAbrir($usuario);
    return $usuario;
}

/**
 * Lee el usuario de la base y devuelve null si no existe o está deshabilitado.
 *
 * `habilitado` no tiene una convención única en la tabla: '1' en 2064 filas,
 * '0' en 17, y una 'S' y una 'N' sueltas. El legacy solo bloqueaba con '0';
 * acá también se bloquea 'N', que significa lo mismo y de otro modo dejaría
 * entrar a un usuario que el operador dio de baja.
 */
function appUsuarioVigente(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT id, nombre, usuario, correo, celular, habilitado, autenticacion, perfil, dominio
         FROM usuarios
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $habilitado = strtoupper(trim((string) ($row['habilitado'] ?? '')));
    if ($habilitado === '0' || $habilitado === 'N') {
        return null;
    }

    return $row;
}

// -----------------------------------------------------------------------
// Apertura y cierre de sesión
// -----------------------------------------------------------------------

/**
 * Emite la cookie propia y marca el ingreso. Es el único punto que abre sesión:
 * lo usan el login por contraseña, el login por código y la adopción del token
 * legacy.
 *
 * El token lleva, además de quién es el usuario, el ALCANCE de la sesión:
 * `per` (perfil), `dom` (dominio) y `pan` (panel abierto). Son el equivalente
 * de `sesionPerfil` / `sesionDominio` / `sesionPanel` del legacy, que allá
 * viven en `$_SESSION` y acá no tienen dónde vivir: no hay sesión de servidor.
 * Se resuelven contra la base una sola vez, al abrir — igual que hacía
 * `cAcceso::controlar()` cuando `sesionUsuario` venía vacío.
 */
function appSesionAbrir(array $usuario, bool $marcarIngreso = true): void
{
    require_once __DIR__ . '/contexto.php';

    // Si la resolución falla (usuario sin perfiles, base caída) la sesión se
    // abre igual y sin claims: `appContextoSesion()` los vuelve a resolver
    // desde la base en el próximo request. Quedarse sin poder entrar por no
    // haber podido calcular el panel sería peor.
    $claims = ['per' => 0, 'dom' => 0, 'pan' => 0];
    try {
        $claims = appClaimsDeSesion($usuario);
    } catch (Throwable $_) { /* noop */ }

    appSesionEmitir($usuario, $claims);

    if ($marcarIngreso) {
        // No bloquea el login si falla.
        try {
            $upd = db()->prepare('UPDATE usuarios SET ingresado = NOW() WHERE id = :id');
            $upd->execute([':id' => (int) $usuario['id']]);
        } catch (Throwable $_) { /* noop */ }
    }
}

/**
 * Firma y manda la cookie con el usuario y el alcance dados.
 *
 * Separado de `appSesionAbrir()` porque el alcance cambia sin que la sesión se
 * reabra: al elegir otro panel hay que reemitir el token con el `pan` nuevo
 * (`api/paneles.php`), que es el análogo de reescribir `sesionPanel` en el
 * `$_SESSION` del legacy.
 *
 * @param array{per:int, dom:int, pan:int} $claims
 */
function appSesionEmitir(array $usuario, array $claims): void
{
    $token = jwt_sign([
        'uid' => (int) $usuario['id'],
        'usr' => (string) ($usuario['usuario'] ?? ''),
        'nom' => (string) ($usuario['nombre'] ?? ''),
        'per' => (int) ($claims['per'] ?? 0),
        'dom' => (int) ($claims['dom'] ?? 0),
        'pan' => (int) ($claims['pan'] ?? 0),
    ], APP_KEY_APP, APP_SESION_TTL);

    appCookie(APP_COOKIE, $token, time() + APP_SESION_TTL);
}

/**
 * Cierra la sesión.
 *
 * Borra TAMBIÉN la cookie legacy: si quedara, la próxima carga de página la
 * adoptaría de nuevo y el "Cerrar sesión" no cerraría nada.
 */
function appSesionCerrar(): void
{
    appCookie(APP_COOKIE, '', time() - 3600);
    appCookie(APP_COOKIE_LEGACY, '', time() - 3600);
    appCookie(APP_COOKIE_LOGIN, '', time() - 3600);
}

// -----------------------------------------------------------------------
// Login en 2 pasos (paso 1 = usuario, paso 2 = contraseña o código)
// -----------------------------------------------------------------------

/**
 * Guarda el usuario elegido en el paso 1. Cookie firmada y de vida corta en
 * vez de sesión PHP nativa: el resto del monorepo tampoco usa `$_SESSION`.
 */
function appLoginPendienteAbrir(int $usuarioId, string $ingresado): void
{
    $token = jwt_sign([
        'uid' => $usuarioId,
        'ing' => $ingresado,   // lo que tipeó el usuario, para repintarlo al volver
    ], APP_KEY_APP, APP_LOGIN_TTL);

    appCookie(APP_COOKIE_LOGIN, $token, time() + APP_LOGIN_TTL);
}

/**
 * Devuelve `['uid' => int, 'ing' => string]` del paso 1, o null si venció.
 */
function appLoginPendiente(): ?array
{
    $token = (string) ($_COOKIE[APP_COOKIE_LOGIN] ?? '');
    if ($token === '') {
        return null;
    }
    $payload = jwt_verify($token, APP_KEY_APP);
    if ($payload === null || (int) ($payload['uid'] ?? 0) <= 0) {
        return null;
    }
    return [
        'uid' => (int) $payload['uid'],
        'ing' => (string) ($payload['ing'] ?? ''),
    ];
}

function appLoginPendienteCerrar(): void
{
    appCookie(APP_COOKIE_LOGIN, '', time() - 3600);
}

// -----------------------------------------------------------------------

function appCookie(string $nombre, string $valor, int $expira): void
{
    setcookie($nombre, $valor, [
        'expires'  => $expira,
        'path'     => '/',
        'secure'   => (defined('APP_ENV') && APP_ENV === 'production'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
