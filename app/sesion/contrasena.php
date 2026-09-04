<?php

declare(strict_types=1);

/**
 * Paso 2 del login para `autenticacion = 'F'`: contraseña fija.
 *
 * Réplica de `reactor-app/sesion/contrasena.php`. La contraseña se valida
 * cifrando lo tipeado con el cifrado histórico y comparando contra
 * `usuarios.contrasena` — es la única forma de validar los ~2000 usuarios
 * existentes sin un reset masivo.
 *
 * PENDIENTE (no es del login): el legacy además resolvía perfil y panel acá y
 * los persistía en `usuarios.perfil` / `perfiles.panel`. Se hará cuando
 * `index.php` deje de ser un mockup y renderice paneles reales; hoy no
 * consume ninguna de las dos cosas.
 */

require_once __DIR__ . '/_layout.php';
require_once dirname(__DIR__) . '/lib/legacy_crypto.php';

if (appUser() !== null) {
    header('Location: /');
    exit;
}

// Sin paso 1 válido no hay nada que validar.
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

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $tipeada = (string) ($_POST['contrasena'] ?? '');

    // `contrasena` no viene en appUsuarioVigente(): se lee acá y solo acá.
    $stmt = db()->prepare('SELECT contrasena FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int) $usuario['id']]);
    $guardada = (string) ($stmt->fetchColumn() ?: '');

    if ($tipeada === '' || $guardada === '' || !hash_equals($guardada, reactor_legacy_encriptar($tipeada))) {
        $error = 'Contraseña incorrecta.';
    } else {
        appLoginPendienteCerrar();
        appSesionAbrir($usuario);
        header('Location: /');
        exit;
    }
}

ob_start();
?>
<form method="post" class="sesion-form" novalidate>
    <div class="sesion-campo">
        <input type="password"
               name="contrasena"
               id="contrasena"
               class="sesion-input"
               placeholder="Tu contrase&ntilde;a"
               autocomplete="current-password"
               autofocus>
        <i class="fa-solid fa-lock sesion-input-icono"></i>
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
<?php
sesionPantalla('Ingrese su contraseña', (string) ob_get_clean(), $error);
