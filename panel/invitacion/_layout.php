<?php

declare(strict_types=1);

/**
 * Shell de las paginas publicas de invitacion.
 *
 * Estas tres pantallas (index / aceptar / rechazar) son las unicas del panel
 * que se sirven SIN sesion: el destinatario de una invitacion todavia no
 * tiene cuenta. Por eso no incluyen api/bootstrap.php (que exige JWT) ni el
 * shell de la SPA: son paginas sueltas que reusan la tarjeta roja del login.
 *
 * La credencial es el uuid del enlace, igual que en el legacy.
 */

require_once dirname(__DIR__, 2) . '/env.php';
require_once dirname(__DIR__) . '/lib/invitaciones.php';

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
function invitacionCacheBust(): string
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
function invitacionFechaLarga(?string $valor): string
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

/** Fila etiqueta / valor de la ficha de la invitacion. */
function invitacionDato(string $label, string $valor): string
{
    return '<div class="inv-dato">'
         . '<span class="inv-dato-label">' . e($label) . '</span>'
         . '<span class="inv-dato-valor">' . e($valor) . '</span>'
         . '</div>';
}

/**
 * Renderiza la pagina completa y termina el request.
 *
 * @param string $titulo   Titulo de la tarjeta (y del <title>).
 * @param string $cuerpo   HTML ya escapado del interior de la tarjeta.
 */
function invitacionLayout(string $titulo, string $cuerpo): void
{
    $v     = invitacionCacheBust();
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
 * Pantalla de corte: un aviso y nada mas. La usan los tres archivos cuando
 * el enlace no sirve (uuid inexistente, invitacion ya cerrada, error de
 * envio), para que el invitado siempre vea el mismo formato.
 */
function invitacionCorte(string $titulo, string $mensaje, string $tono = 'bad'): void
{
    invitacionLayout($titulo,
        '<div class="inv-note inv-note-' . e($tono) . '">' . e($mensaje) . '</div>'
    );
}
