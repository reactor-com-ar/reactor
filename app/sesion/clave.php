<?php

declare(strict_types=1);

/**
 * Paso 2 del login para `autenticacion = 'T'`: código de verificación de 6
 * dígitos (29 usuarios de ~2080).
 *
 * EL CÓDIGO SALE POR CORREO, no por la cola `mensajes`. Hasta el 29/09/2026
 * esta pantalla replicaba a `reactor-app/sesion/clave.php`: generaba el código
 * y lo encolaba, con el canal elegido por lo tipeado en el paso 1 ('C' si tenía
 * '@', 'W' si no). En este monorepo nadie consume esa cola —la última fila
 * realmente enviada es del 2024-06-22— así que el mensaje no salía nunca y
 * estas 29 cuentas no podían entrar. Ahora el envío lo hace `lib/clave.php`
 * por el microservicio de Databox, el mismo canal de la recuperación de
 * contraseña y las invitaciones, y siempre al correo de la cuenta: no hay
 * cliente de WhatsApp en el repo, y prometer uno es el bug que se arregla.
 * Todos los porqués están en el docblock de `lib/clave.php`.
 *
 * CUÁNDO SE MANDA. Una vez por paso 1 —la primera vez que se llega acá con la
 * cookie del login pendiente sin marca de emisión— y después sólo si lo piden
 * con *Reenviar código*. Antes se generaba y encolaba uno nuevo en CADA GET,
 * así que un refresh invalidaba el código que la persona estaba tipeando y
 * disparaba otro mensaje.
 *
 * El POST de validación no emite nada: un código equivocado se vuelve a
 * intentar contra el mismo código, que es lo que la persona tiene en la
 * casilla.
 */

require_once __DIR__ . '/_layout.php';
require_once dirname(__DIR__) . '/lib/clave.php';

if (appUser() !== null) {
    header('Location: /');
    exit;
}

$pendiente = appLoginPendiente();
if ($pendiente === null) {
    header('Location: iniciar');
    exit;
}

$usuario = appUsuarioVigente($pendiente['uid']);
if ($usuario === null) {
    appLoginPendienteCerrar();
    header('Location: iniciar');
    exit;
}

$error   = '';
$nota    = '';
$metodo  = ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$mascara = claveCorreoEnmascarado((string) ($usuario['correo'] ?? ''));

/**
 * Manda el código y deja la marca en la cookie del paso 1. Reabrir la cookie
 * con el timestamp corre APP_LOGIN_TTL desde el envío, que es lo que le da al
 * código su vigencia (ver `appLoginPendienteAbrir()`).
 */
$emitir = static function () use ($usuario, $pendiente, &$error, &$nota, $mascara): void {
    $envio = claveEmitir($usuario);
    if (!$envio['ok']) {
        $error = (string) $envio['error'];
        return;
    }
    appLoginPendienteAbrir($pendiente['uid'], $pendiente['ing'], time());
    $nota = $mascara !== ''
        ? 'Te enviamos el código a ' . $mascara . '. Puede tardar un minuto en llegar.'
        : 'Te enviamos el código por correo. Puede tardar un minuto en llegar.';
};

if ($metodo === 'POST' && (string) ($_POST['accion'] ?? '') === 'reenviar') {
    $emitir();
} elseif ($metodo === 'POST') {
    $tipeada = trim((string) ($_POST['clave'] ?? ''));

    $stmt = db()->prepare('SELECT clave FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int) $usuario['id']]);
    $guardada = trim((string) ($stmt->fetchColumn() ?: ''));

    if ($tipeada === '' || $guardada === '' || !hash_equals($guardada, $tipeada)) {
        $error = 'Código de verificación incorrecto.';
    } else {
        claveConsumir((int) $usuario['id']);
        appLoginPendienteCerrar();
        appSesionAbrir($usuario);
        header('Location: /');
        exit;
    }
} elseif ($pendiente['clv'] === 0) {
    // Primera entrada a la pantalla con este paso 1: acá es donde sale el
    // correo. Un refresh ya trae `clv` cargado y no vuelve a mandar nada.
    $emitir();
}

$encabezado = $mascara !== ''
    ? 'Ingrese el código de verificación que enviamos a ' . $mascara
    : 'Ingrese el código de verificación que enviamos a su correo';

ob_start();
?>
<?php if ($nota !== ''): ?>
    <div class="sesion-nota"><?= htmlspecialchars($nota) ?></div>
<?php endif; ?>

<form method="post" class="sesion-form" novalidate>
    <div class="sesion-campo">
        <input type="text"
               name="clave"
               id="clave"
               class="sesion-input"
               placeholder="Tu c&oacute;digo"
               inputmode="numeric"
               pattern="[0-9]*"
               maxlength="<?= CLAVE_DIGITOS ?>"
               autocomplete="one-time-code"
               autofocus>
        <i class="fa-solid fa-key sesion-input-icono"></i>
    </div>

    <div class="sesion-fila">
        <a href="iniciar" class="sesion-btn sesion-btn-secundario">
            <i class="fa-solid fa-chevron-left"></i> Volver
        </a>
        <button type="submit" class="sesion-btn">
            Siguiente <i class="fa-solid fa-chevron-right"></i>
        </button>
    </div>
</form>

<!-- Formulario aparte y no un boton mas del de arriba, por lo mismo que el
     "Recuperar contraseña" de contrasena.php: es otra accion, no una segunda
     opcion de la validacion. Va por POST y no por GET porque manda un correo:
     un prefetch del navegador sobre un enlace lo dispararia solo. -->
<form method="post">
    <input type="hidden" name="accion" value="reenviar">
    <button type="submit" class="sesion-btn sesion-btn-secundario">
        <i class="fa-solid fa-rotate-right"></i> Reenviar c&oacute;digo
    </button>
</form>
<?php
sesionPantalla($encabezado, (string) ob_get_clean(), $error);
