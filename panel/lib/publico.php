<?php

declare(strict_types=1);

/**
 * Shell de las paginas publicas del panel.
 *
 * Son las unicas pantallas que se sirven SIN sesion, y hoy son dos familias:
 *
 *   - invitacion/  (ver / aceptar / rechazar): el destinatario todavia no
 *     tiene cuenta. La credencial es el uuid del enlace.
 *   - recuperar/   (pedir enlace / restablecer): el dueno de la cuenta
 *     perdio la contrasena. La credencial es el token del enlace.
 *
 * Ninguna pasa por api/bootstrap.php (que exige JWT) ni por el shell de la
 * SPA: son paginas sueltas que reusan la tarjeta roja del login.
 *
 * LAS RUTAS DE LOS ASSETS SON `../`, o sea que este layout sirve para
 * paginas UBICADAS UN NIVEL BAJO EL DOCROOT del panel (`invitacion/`,
 * `recuperar/`). Una pagina mas profunda tendria que pasar su propio
 * prefijo; hoy no existe ninguna y agregar el parametro sin usarlo seria
 * ruido.
 */

require_once dirname(__DIR__, 2) . '/env.php';
require_once __DIR__ . '/db.php';

if (APP_ENV !== 'production') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// Sin indexar: son URLs de un solo uso, no contenido publico.
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

/** Cache-bust compartido con el resto del panel. */
function publicoCacheBust(): string
{
    $file = dirname(__DIR__) . '/version.txt';
    return is_file($file) ? trim((string) file_get_contents($file)) : (string) time();
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * 'YYYY-MM-DD HH:MM:SS' -> 'DD/MM/YYYY HH:MM'. Los centinelas del sistema
 * viejo (1500-01-01 y 0000-00-00) salen como raya, igual que en la SPA.
 */
function publicoFechaLarga(?string $valor): string
{
    $s = trim((string) $valor);
    if ($s === '' || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/', $s, $m)) {
        return '—';
    }
    if ((int) $m[1] < 1900) {
        return '—';
    }
    return isset($m[4])
        ? sprintf('%s/%s/%s %s:%s', $m[3], $m[2], $m[1], $m[4], $m[5])
        : sprintf('%s/%s/%s', $m[3], $m[2], $m[1]);
}

/** Fila etiqueta / valor de las fichas de la tarjeta. */
function publicoDato(string $label, string $valor): string
{
    return '<div class="inv-dato">'
         . '<span class="inv-dato-label">' . e($label) . '</span>'
         . '<span class="inv-dato-valor">' . e($valor) . '</span>'
         . '</div>';
}

/**
 * Renderiza la pagina completa y termina el request.
 *
 * @param string $titulo Titulo de la tarjeta (y del <title>).
 * @param string $cuerpo HTML ya escapado del interior de la tarjeta.
 */
function publicoLayout(string $titulo, string $cuerpo): void
{
    $v     = publicoCacheBust();
    $faVer = @filemtime(dirname(__DIR__) . '/assets/fontawesome/css/all.min.css') ?: $v;
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> &middot; Reactor Panel</title>

    <link rel="shortcut icon" href="../favicon.ico?v=<?= e($v) ?>">
    <link rel="icon" type="image/x-icon" href="../favicon.ico?v=<?= e($v) ?>">
    <meta name="theme-color" content="#C11313">
    <meta name="robots" content="noindex, nofollow">

    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css?v=<?= e((string) $faVer) ?>">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= e($v) ?>">
</head>
<body class="login-page">

<main class="login-shell">
    <section class="login-card inv-card">
        <div class="login-brand">
            <img src="../assets/img/reactor_white.png?v=<?= e($v) ?>" alt="Reactor" class="login-logo">
        </div>
        <h1 class="login-title"><?= e($titulo) ?></h1>
        <?= $cuerpo ?>
    </section>
</main>

</body>
</html>
    <?php
    exit;
}

/**
 * Pantalla de corte: un aviso y nada mas. La usan las paginas cuando el
 * enlace no sirve (uuid/token inexistente, vencido o ya resuelto), para que
 * el visitante siempre vea el mismo formato.
 *
 * @param string $extra HTML opcional que va debajo del aviso (ej. el boton
 *                      para pedir un enlace nuevo).
 */
function publicoCorte(string $titulo, string $mensaje, string $tono = 'bad', string $extra = ''): void
{
    publicoLayout($titulo,
        '<div class="inv-note inv-note-' . e($tono) . '">' . e($mensaje) . '</div>' . $extra
    );
}
