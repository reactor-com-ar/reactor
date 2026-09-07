<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/env.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/lib/acceso.php';

// Si ya esta logueado Y su sesion habilita el panel, ir directo a la SPA.
//
// La segunda condicion es la que evita el rebote: sin ella, una sesion sin
// perfil de Administrador entra aca, la mandamos a index.php, index.php la
// devuelve al login y asi para siempre. Con ella se queda en el formulario y
// puede ingresar con otra cuenta.
if (authUser() !== null && sessionTienePerfilValido()) {
    header('Location: index');
    exit;
}

// Aviso del rechazo por rol. Lo pone requireAdministrador() al redirigir desde
// index.php, y app.js al recibir un 403 con `motivo: 'rol'` (perfil revocado
// con la sesion abierta). Es un aviso, no un error del formulario: la
// contrasena que la persona esta por tipear no tiene nada que ver.
$avisoRol = (($_GET['motivo'] ?? '') === 'perfil');

$appName     = 'Reactor Panel';
$versionFile = __DIR__ . '/version.txt';
$cacheBust   = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : (string) time();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingresar &middot; <?= htmlspecialchars($appName) ?></title>

    <link rel="shortcut icon" href="favicon.ico?v=<?= htmlspecialchars($cacheBust) ?>">
    <link rel="icon" type="image/x-icon" href="favicon.ico?v=<?= htmlspecialchars($cacheBust) ?>">
    <meta name="theme-color" content="#C11313">

    <?php
    // Font Awesome 6.5.1 Pro autohospedado (assets/fontawesome/). `all.min.css`
    // trae las familias Classic (solid/regular/light/thin), Duotone y Brands;
    // las cuatro hojas `sharp-*` agregan los @font-face de la familia Sharp,
    // que `all.min.css` mapea pero no declara.
    $faVer = @filemtime(__DIR__ . '/assets/fontawesome/css/all.min.css') ?: $cacheBust;
    ?>
    <link rel="stylesheet" href="assets/fontawesome/css/all.min.css?v=<?= htmlspecialchars((string) $faVer) ?>">
    <link rel="stylesheet" href="assets/fontawesome/css/sharp-solid.min.css?v=<?= htmlspecialchars((string) $faVer) ?>">
    <link rel="stylesheet" href="assets/fontawesome/css/sharp-regular.min.css?v=<?= htmlspecialchars((string) $faVer) ?>">
    <link rel="stylesheet" href="assets/fontawesome/css/sharp-light.min.css?v=<?= htmlspecialchars((string) $faVer) ?>">
    <link rel="stylesheet" href="assets/fontawesome/css/sharp-thin.min.css?v=<?= htmlspecialchars((string) $faVer) ?>">
    <link rel="stylesheet"
          href="assets/css/style.css?v=<?= htmlspecialchars($cacheBust) ?>">
</head>
<body class="login-page">

<main class="login-shell">
    <section class="login-card" aria-labelledby="login-title">
        <div class="login-brand">
            <img src="assets/img/reactor_white.png?v=<?= htmlspecialchars($cacheBust) ?>"
                 alt="Reactor" class="login-logo">
        </div>
        <h1 class="login-title" id="login-title"><?= htmlspecialchars($appName) ?></h1>
        <p class="login-subtitle">Ingres&aacute; con tu usuario para continuar.</p>

        <?php if ($avisoRol): ?>
            <!-- `.inv-note` es la caja de aviso de la tarjeta roja (CSS §19):
                 nace en las paginas publicas pero esta calibrada para vivir
                 dentro de .login-card, que es esta misma tarjeta. -->
            <div class="inv-note inv-note-bad">
                <?= htmlspecialchars(PANEL_MENSAJE_SIN_PERFIL) ?>
                Ingres&aacute; con una cuenta que tenga ese perfil.
            </div>
        <?php endif; ?>

        <form id="login-form" class="login-form" autocomplete="on" novalidate>
            <div class="form-group">
                <label for="login-usuario">Usuario</label>
                <input type="text"
                       id="login-usuario"
                       name="usuario"
                       autocomplete="username"
                       autocapitalize="off"
                       autocorrect="off"
                       spellcheck="false"
                       autofocus
                       required>
            </div>
            <div class="form-group">
                <label for="login-contrasena">Contrase&ntilde;a</label>
                <input type="password"
                       id="login-contrasena"
                       name="contrasena"
                       autocomplete="current-password"
                       required>
            </div>

            <div class="field-error login-error" id="login-error" hidden></div>

            <button type="submit" class="btn btn-primary login-submit" id="login-submit">
                <i class="fa-solid fa-right-to-bracket"></i>
                <span>Ingresar</span>
            </button>
        </form>

        <a class="login-alt" href="recuperar/">&iquest;Olvidaste tu contrase&ntilde;a?</a>
    </section>
</main>

<script>
(() => {
    'use strict';
    const form      = document.getElementById('login-form');
    const usuario   = document.getElementById('login-usuario');
    const password  = document.getElementById('login-contrasena');
    const errBox    = document.getElementById('login-error');
    const submit    = document.getElementById('login-submit');
    const submitLbl = submit.querySelector('span');

    function showError(msg) {
        errBox.textContent = msg;
        errBox.hidden = false;
        usuario.classList.toggle('input-invalid', !usuario.value.trim());
        password.classList.add('input-invalid');
    }
    function clearError() {
        errBox.hidden = true;
        errBox.textContent = '';
        usuario.classList.remove('input-invalid');
        password.classList.remove('input-invalid');
    }
    usuario.addEventListener('input', clearError);
    password.addEventListener('input', clearError);

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearError();

        const u = usuario.value.trim();
        const p = password.value;
        if (!u || !p) {
            showError('Usuario y contraseña son obligatorios.');
            return;
        }

        submit.disabled = true;
        const prev = submitLbl.textContent;
        submitLbl.textContent = 'Ingresando…';

        try {
            const res = await fetch('api/login', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ usuario: u, contrasena: p }),
                credentials: 'same-origin',
            });
            let body = null;
            try { body = await res.json(); } catch (_) { body = null; }

            if (!res.ok || !body || body.ok === false) {
                const msg = (body && body.error) ? body.error : `Error de autenticación (HTTP ${res.status}).`;
                showError(msg);
                submit.disabled = false;
                submitLbl.textContent = prev;
                password.focus();
                password.select();
                return;
            }

            window.location.href = 'index';
        } catch (err) {
            showError('No se pudo conectar con el servidor. Intentá nuevamente.');
            submit.disabled = false;
            submitLbl.textContent = prev;
        }
    });
})();
</script>
</body>
</html>
