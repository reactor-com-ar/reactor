<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/env.php';
require_once __DIR__ . '/lib/auth_check.php';

// Si ya esta logueado, ir directo a la SPA.
if (authUser() !== null) {
    header('Location: index');
    exit;
}

$appName     = 'Reactor Cloud';
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
    // Font Awesome 6.5.1 Pro autohospedado — ver el comentario de index.php.
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
        <p class="login-subtitle">Ingres&aacute; con tu correo para continuar.</p>


        <form id="login-form" class="login-form" autocomplete="on" novalidate>
            <div class="form-group">
                <label for="login-correo">Correo</label>
                <input type="email"
                       id="login-correo"
                       name="correo"
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
    </section>
</main>

<script>
(() => {
    'use strict';
    const form     = document.getElementById('login-form');
    const correo   = document.getElementById('login-correo');
    const password = document.getElementById('login-contrasena');
    const errBox   = document.getElementById('login-error');
    const submit   = document.getElementById('login-submit');
    const submitLbl = submit.querySelector('span');

    function showError(msg) {
        errBox.textContent = msg;
        errBox.hidden = false;
        correo.classList.toggle('input-invalid', !correo.value.trim());
        password.classList.add('input-invalid');
    }
    function clearError() {
        errBox.hidden = true;
        errBox.textContent = '';
        correo.classList.remove('input-invalid');
        password.classList.remove('input-invalid');
    }
    correo.addEventListener('input', clearError);
    password.addEventListener('input', clearError);

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearError();

        const c = correo.value.trim().toLowerCase();
        const p = password.value;
        if (!c || !p) {
            showError('Correo y contraseña son obligatorios.');
            return;
        }

        submit.disabled = true;
        const prev = submitLbl.textContent;
        submitLbl.textContent = 'Ingresando…';

        try {
            const res = await fetch('api/login', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ correo: c, contrasena: p }),
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

            // Login ok → SPA.
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
