<?php

declare(strict_types=1);

/**
 * Puente para los accesos directos del legacy.
 *
 * QUIEN CAE ACA. La app vieja vivia en `/panel/`, asi que todo el que se guardo
 * un favorito o se puso el icono en la pantalla de inicio lo tiene apuntando a
 * `https://app.reactor.com.ar/panel/`. Esas URLs quedaron congeladas: el
 * usuario no las va a actualizar, y en iOS ni siquiera puede editarlas. Este
 * archivo las recibe y las manda al inicio.
 *
 * POR QUE UNA PAGINA Y NO UN REDIRECT 302. Es por los accesos directos de iOS
 * hechos con "Añadir a pantalla de inicio":
 *
 *   - Safari guarda la URL EXACTA que estaba abierta y la usa como punto de
 *     arranque. Al abrirlo desde el icono, la pagina se muestra en modo
 *     standalone (sin barras del navegador) solo mientras la navegacion siga
 *     siendo "de la app".
 *   - Un redirect HTTP desde ese punto de arranque hace que iOS trate el
 *     destino como una salida y lo abra en Safari, con sus barras. El icono
 *     deja de comportarse como aplicacion, que es justo lo que el usuario
 *     instalo.
 *   - Ademas, todo redirect que arme mod_rewrite sale absoluto y con el
 *     esquema que ve Apache — que detras de nginx es siempre `http://`, aunque
 *     el usuario haya entrado por HTTPS. Eso degradaba la conexion y cambiaba
 *     de origen: `https://.../panel/` -> `http://.../` -> de vuelta a https.
 *     Tres saltos, uno en claro, y con un service worker registrado en el
 *     origen https ese rebote termina en ERR_FAILED. Sirviendo una pagina no
 *     hay redirect HTTP y el problema no puede volver.
 *
 * Los meta de `apple-mobile-web-app-*` estan repetidos aca a proposito: son los
 * que le dicen a iOS que esta pagina tambien es "de la app", asi el salto al
 * inicio ocurre adentro del modo standalone.
 */

// El archivo vive en la raiz de app/, al lado de version.txt.
$versionFile = __DIR__ . '/version.txt';
$cb = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : (string) time();
$cb = htmlspecialchars($cb, ENT_QUOTES);

// Sin caché: si el navegador se guardara esta página, el usuario quedaría
// rebotando por acá incluso después de que el acceso directo deje de usarse.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>Reactor</title>

    <!-- Sin JS igual sale de acá. Va primero para que actúe aunque el script
         falle en cargar. -->
    <noscript><meta http-equiv="refresh" content="0; url=/"></noscript>

    <link rel="canonical" href="/">

    <meta name="theme-color" content="#C11313">
    <link rel="manifest" href="/manifest.json?v=<?= $cb ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Reactor">
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="apple-touch-icon" href="/favicon/apple-icon.png?v=<?= $cb ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="/favicon/apple-icon-180x180.png?v=<?= $cb ?>">

    <style>
        /* El rojo institucional, el mismo `background_color` del manifest: el
           salto se ve como la app arrancando y no como una página en blanco. */
        html, body {
            height: 100%;
            margin: 0;
            background: #C11313;
        }
    </style>
</head>
<body>
    <script>
        // `replace` y no `href`: no deja entrada en el historial, así el botón
        // "atrás" del inicio no rebota de vuelta a esta página.
        window.location.replace('/');
    </script>
</body>
</html>
