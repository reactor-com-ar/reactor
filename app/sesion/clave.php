<?php

declare(strict_types=1);

/**
 * Paso 2 del login para `autenticacion = 'T'`: código de verificación de 6
 * dígitos (29 usuarios de ~2080).
 *
 * Réplica de `reactor-app/sesion/clave.php`: al entrar se genera un código
 * nuevo, se guarda en `usuarios.clave` y se encola el mensaje; el POST compara
 * lo tipeado contra esa columna. Canal por destino, igual que el legacy: si lo
 * que se tipeó en el paso 1 tiene '@' va por correo ('C'), si no por WhatsApp
 * ('W').
 *
 * OJO — HOY EL CÓDIGO NO SE ENTREGA. El mensaje se encola en `mensajes` igual
 * que en el legacy, pero en este monorepo todavía no hay worker que consuma
 * esa cola (la última fila enviada es de 2024-06). Hasta que exista, estos
 * usuarios no pueden completar el login por este camino.
 */

require_once __DIR__ . '/_layout.php';

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

$porCorreo = str_contains($pendiente['ing'], '@');
$error     = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $tipeada = trim((string) ($_POST['clave'] ?? ''));

    $stmt = db()->prepare('SELECT clave FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int) $usuario['id']]);
    $guardada = trim((string) ($stmt->fetchColumn() ?: ''));

    if ($tipeada === '' || $guardada === '' || !hash_equals($guardada, $tipeada)) {
        $error = 'Código de verificación incorrecto.';
    } else {
        appLoginPendienteCerrar();
        appSesionAbrir($usuario);
        header('Location: /');
        exit;
    }
} else {
    // GET: código nuevo en cada entrada a la pantalla, como el legacy.
    $clave = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $upd = db()->prepare('UPDATE usuarios SET clave = :c WHERE id = :id');
    $upd->execute([':c' => $clave, ':id' => (int) $usuario['id']]);

    $ins = db()->prepare(
        'INSERT INTO mensajes (canal, usuario, destinatario, destino, texto, encolado, estado)
         VALUES (:canal, :usuario, :destinatario, :destino, :texto, NOW(), :estado)'
    );
    $ins->execute([
        ':canal'        => $porCorreo ? 'C' : 'W',
        ':usuario'      => (int) $usuario['id'],
        ':destinatario' => (string) ($usuario['nombre'] ?? ''),
        ':destino'      => $porCorreo
            ? (string) ($usuario['correo'] ?? '')
            : (string) ($usuario['celular'] ?? ''),
        ':texto'        => 'Tu código de verificación de Reactor es ' . $clave,
        ':estado'       => '1',   // encolado
    ]);
}

$encabezado = $porCorreo
    ? 'Ingrese el código de verificación que recibirá por correo'
    : 'Ingrese el código de verificación que recibirá por whatsapp';

ob_start();
?>
<form method="post" class="sesion-form" novalidate>
    <div class="sesion-campo">
        <input type="text"
               name="clave"
               id="clave"
               class="sesion-input"
               placeholder="Tu c&oacute;digo"
               inputmode="numeric"
               pattern="[0-9]*"
               maxlength="6"
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
<?php
sesionPantalla($encabezado, (string) ob_get_clean(), $error);
