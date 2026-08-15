<?php
declare(strict_types=1);

/*
 * Reactor App (end-user) — mockup visual del panel de control.
 *
 * Version de diseño sin auth ni queries a DB. Los panels, controles y
 * botones estan hardcodeados abajo. Cuando este validado el look & feel se
 * cablea contra `paneles` / `controles` / `botones` / `dispositivos` y contra
 * el auth JWT.
 */

$appName    = 'Reactor';
$versionFile = __DIR__ . '/version.txt';
$cacheBust  = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : (string) time();

$dominioNombre = 'Familia Alvarez';
$panelNombre   = 'General';

// ---- Data mock (equivalente a lo que devolveria la query de controles) ----
// Cada control tiene: nombre, color del display (yellow|gray), online,
// senal (%), bateria (%), canales (array de {n, on}), y botones con
// {icono FontAwesome, texto}.
$controles = [
    [
        'nombre'  => 'Comedor Centro',
        'color'   => 'yellow',
        'online'  => true,
        'senal'   => 68,
        'bateria' => 100,
        'canales' => [ ['n' => 1, 'on' => false], ['n' => 2, 'on' => false] ],
        'botones' => [
            ['icono' => 'fa-regular fa-lightbulb', 'texto' => 'Halogena Encender'],
            ['icono' => 'fa-solid fa-lightbulb',   'texto' => 'Halogena Apagar'],
            ['icono' => 'fa-regular fa-lightbulb', 'texto' => 'Fluorecente Encender'],
            ['icono' => 'fa-solid fa-lightbulb',   'texto' => 'Fluorecente Apagar'],
        ],
    ],
    [
        'nombre'  => 'Comedor Izquierda',
        'color'   => 'yellow',
        'online'  => true,
        'senal'   => 69,
        'bateria' => 100,
        'canales' => [ ['n' => 1, 'on' => false], ['n' => 2, 'on' => false] ],
        'botones' => [
            ['icono' => 'fa-regular fa-lightbulb', 'texto' => 'TV Encender'],
            ['icono' => 'fa-solid fa-lightbulb',   'texto' => 'TV Apagar'],
            ['icono' => 'fa-solid fa-fan',         'texto' => 'Fan On'],
            ['icono' => 'fa-solid fa-fan',         'texto' => 'Fan Off'],
        ],
    ],
];

$cb = htmlspecialchars($cacheBust, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title><?= htmlspecialchars($appName) ?></title>

    <link rel="shortcut icon"                       href="favicon.ico?v=<?= $cb ?>">
    <link rel="icon" type="image/x-icon"            href="favicon.ico?v=<?= $cb ?>">
    <link rel="icon" type="image/png" sizes="16x16"  href="favicon/favicon-16x16.png?v=<?= $cb ?>">
    <link rel="icon" type="image/png" sizes="32x32"  href="favicon/favicon-32x32.png?v=<?= $cb ?>">
    <link rel="icon" type="image/png" sizes="96x96"  href="favicon/favicon-96x96.png?v=<?= $cb ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="favicon/android-icon-192x192.png?v=<?= $cb ?>">
    <link rel="apple-touch-icon"               href="favicon/apple-icon.png?v=<?= $cb ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="favicon/apple-icon-180x180.png?v=<?= $cb ?>">
    <meta name="theme-color" content="#C11313">
    <meta name="msapplication-TileColor" content="#C11313">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <!-- Doto (pixel / dot-matrix) — titulo del display amarillo -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Doto:wght@100..900&display=swap">
    <link rel="stylesheet"
          href="assets/css/style.css?v=<?= $cb ?>">
</head>
<body data-version="<?= $cb ?>">

<div class="version-banner" id="version-banner" role="status" hidden>
    <span class="version-banner-text">Hay una nueva versi&oacute;n disponible.</span>
    <button type="button" class="version-banner-btn" id="version-banner-btn">Actualizar ahora</button>
</div>

<div class="layout">

    <!-- Topbar rojo (logo + hamburger + acciones) -->
    <header class="topbar">
        <img src="assets/img/reactor_white.png?v=<?= $cb ?>"
             alt="Reactor" class="topbar-logo">

        <button type="button" class="topbar-hamburger" id="hamburger" aria-label="Abrir men&uacute;">
            <i class="fa-solid fa-bars"></i>
        </button>

        <div class="topbar-spacer"></div>

        <nav class="topbar-actions">
            <button type="button" class="topbar-action active" title="Inicio" data-nav="inicio">
                <i class="fa-solid fa-house"></i>
            </button>
            <button type="button" class="topbar-action" title="Dominio" data-nav="dominio">
                <i class="fa-solid fa-hard-drive"></i>
            </button>
            <button type="button" class="topbar-action" title="Ubicaci&oacute;n" data-nav="ubicacion">
                <i class="fa-solid fa-location-dot"></i>
            </button>
            <button type="button" class="topbar-action" title="Soporte" data-nav="soporte">
                <i class="fa-solid fa-headset"></i>
            </button>
        </nav>
    </header>

    <div class="main-row">

        <!-- Sidebar oscuro (menu vertical) -->
        <aside class="sidebar" id="sidebar">
            <nav class="sidebar-nav">
                <a href="#/inicio" class="nav-item active" data-route="inicio">
                    <i class="fa-solid fa-house"></i> Inicio
                </a>
                <a href="#/cuenta" class="nav-item" data-route="cuenta">
                    <i class="fa-solid fa-circle-user"></i> Mi Cuenta
                </a>
                <a href="#/dominio" class="nav-item" data-route="dominio">
                    <i class="fa-solid fa-location-dot"></i> Mi Dominio
                </a>
                <a href="#/ajustes" class="nav-item" data-route="ajustes">
                    <i class="fa-solid fa-gear"></i> Ajustes
                </a>
                <a href="#/instalar" class="nav-item" data-route="instalar" id="btn-install">
                    <i class="fa-solid fa-download"></i> Instalar
                </a>
            </nav>
        </aside>

        <div class="sidebar-overlay" id="sidebar-overlay"></div>

        <!-- Contenido: dominio + panels de controles -->
        <main class="content">

            <div class="dominio-nombre">
                <?= htmlspecialchars($dominioNombre) ?>
                <br>
                <span class="panel-nombre"><?= htmlspecialchars($panelNombre) ?></span>
            </div>

            <div class="panel-feed">
                <?php foreach ($controles as $c): ?>
                    <?php
                        $off = !($c['online'] ?? true);
                        $displayClass = $off ? 'display-pantalla off' : 'display-pantalla';
                    ?>
                    <article class="panel">

                        <div class="<?= $displayClass ?>">

                            <div class="display-status">
                                <span class="left">
                                    <?php if ($off): ?>
                                        <i class="fa-solid fa-plug"></i> Desconectado
                                    <?php else: ?>
                                        <i class="fa-solid fa-wifi"></i> <?= (int) $c['senal'] ?>%
                                    <?php endif; ?>
                                </span>
                                <span class="right">
                                    <?php if (!$off): ?>
                                        <?= (int) $c['bateria'] ?>% <i class="fa-solid fa-battery-full"></i>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <div class="display-titulo">
                                <?= htmlspecialchars($c['nombre']) ?>
                            </div>

                            <div class="canales-fila">
                                <?php foreach ($c['canales'] as $canal): ?>
                                    <span class="canal-estado <?= !empty($canal['on']) ? 'on' : '' ?>">
                                        <?= (int) $canal['n'] ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>

                        </div>

                        <div class="tec-btns">
                            <?php foreach ($c['botones'] as $b): ?>
                                <button type="button" class="tec-btn"
                                        data-control="<?= htmlspecialchars($c['nombre'], ENT_QUOTES) ?>"
                                        data-accion="<?= htmlspecialchars($b['texto'], ENT_QUOTES) ?>">
                                    <i class="<?= htmlspecialchars($b['icono'], ENT_QUOTES) ?>"></i>
                                    <span><?= htmlspecialchars($b['texto']) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>

                    </article>
                <?php endforeach; ?>
            </div>

        </main>

    </div>

</div>

<div class="toast" id="toast" role="status"></div>

<script src="assets/js/app.js?v=<?= $cb ?>"></script>
</body>
</html>
