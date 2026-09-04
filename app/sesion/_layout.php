<?php

declare(strict_types=1);

/**
 * Chrome compartido de las pantallas de sesión (iniciar / contraseña / código).
 *
 * Réplica del login legacy (`reactor-app/sesion/*.php`): fondo rojo pleno,
 * logo hexagonal centrado, encabezado en blanco y un formulario angosto con
 * campos e inputs tipo píldora. Los estilos viven en la sección "Pantallas de
 * sesión" de `assets/css/style.css`.
 */

require_once dirname(__DIR__) . '/lib/auth.php';

function sesionCacheBust(): string
{
    static $cb = null;
    if ($cb === null) {
        $file = dirname(__DIR__) . '/version.txt';
        $cb   = is_file($file) ? trim((string) file_get_contents($file)) : (string) time();
    }
    return $cb;
}

/**
 * Pinta una pantalla de sesión.
 *
 * @param string $encabezado Texto grande arriba del formulario.
 * @param string $formulario HTML del form (ya escapado por el llamador).
 * @param string $error      Mensaje de error, vacío si no hay.
 */
function sesionPantalla(string $encabezado, string $formulario, string $error = ''): void
{
    $cb = htmlspecialchars(sesionCacheBust(), ENT_QUOTES);
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>Ingresar &middot; Reactor</title>

    <link rel="shortcut icon"                        href="/favicon.ico?v=<?= $cb ?>">
    <link rel="icon" type="image/x-icon"             href="/favicon.ico?v=<?= $cb ?>">
    <link rel="icon" type="image/png" sizes="32x32"  href="/favicon/favicon-32x32.png?v=<?= $cb ?>">
    <link rel="apple-touch-icon" sizes="180x180"     href="/favicon/apple-icon-180x180.png?v=<?= $cb ?>">
    <meta name="theme-color" content="#C11313">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= $cb ?>">
</head>
<body class="sesion-page">

<main class="sesion-caja">

    <div class="sesion-logo">
        <img src="/assets/img/logo.png?v=<?= $cb ?>" alt="Reactor" width="100" height="100">
    </div>

    <h1 class="sesion-encabezado"><?= htmlspecialchars($encabezado) ?></h1>

    <?php if ($error !== ''): ?>
        <div class="sesion-error" role="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?= $formulario ?>

</main>

</body>
</html>
    <?php
}
