<?php

declare(strict_types=1);

/**
 * Paso 1 del login: identificar al usuario.
 *
 * Réplica de `reactor-app/sesion/iniciar.php` del legacy, incluida la forma de
 * buscar: el campo "usuario" se compara contra `celular` O `correo` (así lo
 * hace `cUsuario::usuario2id()`), NO contra la columna `usuarios.usuario`.
 * Ojo: `cloud/api/login.php` sí usa la columna `usuario`, son criterios
 * distintos a propósito porque son dos públicos distintos.
 *
 * Según `usuarios.autenticacion` el paso 2 es:
 *   'F' (fija)     -> contrasena.php   (2053 usuarios)
 *   'T' (temporal) -> clave.php        (29 usuarios)
 * Cualquier otro valor (hay 1 fila en NULL) cae en contraseña, que es el modo
 * con el que nacen los usuarios nuevos.
 */

require_once __DIR__ . '/_layout.php';

// Si ya hay sesión (propia o legacy adoptada), no mostramos el login.
if (appUser() !== null) {
    header('Location: /');
    exit;
}

$error     = '';
$ingresado = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $ingresado = trim((string) ($_POST['usuario'] ?? ''));

    if ($ingresado === '') {
        $error = 'Ingresá tu usuario.';
    } else {
        // Dos placeholders distintos para el mismo valor: la conexión va con
        // EMULATE_PREPARES en false y MySQL no acepta un named param repetido.
        $stmt = db()->prepare(
            'SELECT id, habilitado, autenticacion
             FROM usuarios
             WHERE celular = :cel OR correo = :cor
             ORDER BY id
             LIMIT 1'
        );
        $stmt->execute([':cel' => $ingresado, ':cor' => $ingresado]);
        $row = $stmt->fetch();

        if (!$row) {
            $error = 'Usuario no registrado.';
        } else {
            $habilitado = strtoupper(trim((string) ($row['habilitado'] ?? '')));
            if ($habilitado === '0' || $habilitado === 'N') {
                $error = 'Usuario deshabilitado.';
            } else {
                appLoginPendienteAbrir((int) $row['id'], $ingresado);
                $modo = strtoupper(trim((string) ($row['autenticacion'] ?? '')));
                header('Location: ' . ($modo === 'T' ? 'clave' : 'contrasena'));
                exit;
            }
        }
    }
}

ob_start();
?>
<form method="post" class="sesion-form" novalidate>
    <div class="sesion-campo">
        <input type="text"
               name="usuario"
               id="usuario"
               class="sesion-input"
               placeholder="Tu usuario"
               value="<?= htmlspecialchars($ingresado, ENT_QUOTES) ?>"
               autocomplete="username"
               autocapitalize="off"
               autocorrect="off"
               spellcheck="false"
               autofocus>
        <i class="fa-solid fa-user sesion-input-icono"></i>
    </div>

    <button type="submit" class="sesion-btn">
        Siguiente <i class="fa-solid fa-chevron-right"></i>
    </button>
</form>
<?php
sesionPantalla('Ingrese su usuario', (string) ob_get_clean(), $error);
