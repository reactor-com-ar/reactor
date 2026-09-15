<?php

declare(strict_types=1);

/**
 * La pantalla de aviso: un cartel a página completa con un botón que lleva a
 * algún lado. La usan los formularios públicos para confirmar el envío.
 *
 * Reemplaza a `$oMensaje->aviso($texto, $destino)` del legacy, que guardaba el
 * texto en la sesión y redirigía a `/sistema/aviso`, donde otra página lo leía.
 * Acá se dibuja y se corta en el mismo request: sin sesión de por medio no hay
 * forma de que el cartel se pierda —o aparezca de nuevo— si la persona
 * recarga, y el sitio público no necesita abrir una sesión PHP para nada más.
 *
 * El HTML es el de `sistema/aviso.php` del legacy con dos arreglos: el
 * `<link rel="manifest">` al que le faltaba la comilla de cierre y las metas
 * `author` / `keywords` / `description`, que habían quedado con el texto de
 * demo del theme ("The Best Service Industry Theme").
 */

require_once __DIR__ . '/pagina.php';

/**
 * Dibuja el aviso y TERMINA EL REQUEST.
 *
 * El `exit` es parte del contrato: quien llama la invoca después de procesar un
 * formulario y no tiene que acordarse de cortar. Si devolviera, la página
 * seguiría dibujando el formulario debajo del cartel.
 *
 * @param string $texto   Qué pasó, en criollo.
 * @param string $destino A dónde va el botón Aceptar.
 */
function wwwAviso(string $texto, string $destino = '/', string $titulo = 'Aviso'): never
{
    $version = wwwVersion();
    ?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(wwwNombre() . ' | ' . $titulo) ?></title>

    <link rel="shortcut icon" href="/favicon/android-icon-192x192.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon/favicon-16x16.png">

    <link rel="stylesheet" href="/css/plugins.css?v=<?= e($version) ?>">
    <link rel="stylesheet" href="/quform/css/base.css?v=<?= e($version) ?>">
    <link rel="stylesheet" href="/css/styles-red.css?v=<?= e($version) ?>">
    <link rel="stylesheet" href="/css/custom.css?v=<?= e($version) ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.0.0-beta2/css/all.min.css">
</head>

<body>
    <section class="full-screen p-0 d-flex align-items-center min-vh-100">
        <div class="container">
            <div class="row align-items-center justify-content-center">
                <div class="col-12 col-lg-8">
                    <div class="card border-0 text-center">
                        <div class="card-body box-shadow2 p-1-6 p-sm-1-9 p-lg-2-5 p-xl-2-9">
                            <div class="text-center mb-1-9 mb-md-2-5">
                                <a href="/"><img src="/img/logos/logo.png" alt="Reactor"></a>
                            </div>
                            <h1 class="h3 mb-4"><?= e($titulo) ?></h1>
                            <p class="mb-2-5 lead"><?= e($texto) ?></p>
                            <a href="<?= e($destino) ?>" class="butn me-2 my-1">Aceptar</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</body>

</html>
    <?php
    exit;
}

/**
 * Lo mismo, pero además responde 404.
 *
 * El código HTTP importa y no es decorativo: una ficha que no existe
 * —`/blog/slug-inventado`— tiene que decirle al buscador que ahí no hay nada.
 * El legacy devolvía 200 con la página vacía, así que Google indexaba una
 * entrada en blanco por cada URL rota que encontrara.
 */
function wwwNoEncontrado(string $texto = 'La página que buscás no existe o dejó de estar publicada.', string $destino = '/'): never
{
    http_response_code(404);
    wwwAviso($texto, $destino, 'No encontramos esa página');
}
