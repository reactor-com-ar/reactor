<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/env.php';
require_once __DIR__ . '/lib/auth_check.php';

$currentUser = authUser();
if ($currentUser === null) {
    header('Location: login.php');
    exit;
}

$appName = 'Reactor Cloud';
$versionFile = __DIR__ . '/version.txt';
$cacheBust = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : (string) time();

$userDisplay = $currentUser['nombre'] !== '' ? $currentUser['nombre'] : $currentUser['usuario'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($appName) ?></title>

    <link rel="shortcut icon" href="favicon.ico?v=<?= htmlspecialchars($cacheBust) ?>">
    <link rel="icon" type="image/x-icon" href="favicon.ico?v=<?= htmlspecialchars($cacheBust) ?>">
    <link rel="icon" type="image/png" sizes="16x16"  href="favicon/favicon-16x16.png?v=<?= htmlspecialchars($cacheBust) ?>">
    <link rel="icon" type="image/png" sizes="32x32"  href="favicon/favicon-32x32.png?v=<?= htmlspecialchars($cacheBust) ?>">
    <link rel="icon" type="image/png" sizes="96x96"  href="favicon/favicon-96x96.png?v=<?= htmlspecialchars($cacheBust) ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="favicon/android-icon-192x192.png?v=<?= htmlspecialchars($cacheBust) ?>">
    <link rel="apple-touch-icon"               href="favicon/apple-icon.png?v=<?= htmlspecialchars($cacheBust) ?>">
    <link rel="apple-touch-icon" sizes="57x57"   href="favicon/apple-icon-57x57.png?v=<?= htmlspecialchars($cacheBust) ?>">
    <link rel="apple-touch-icon" sizes="60x60"   href="favicon/apple-icon-60x60.png?v=<?= htmlspecialchars($cacheBust) ?>">
    <link rel="apple-touch-icon" sizes="72x72"   href="favicon/apple-icon-72x72.png?v=<?= htmlspecialchars($cacheBust) ?>">
    <link rel="apple-touch-icon" sizes="76x76"   href="favicon/apple-icon-76x76.png?v=<?= htmlspecialchars($cacheBust) ?>">
    <link rel="apple-touch-icon" sizes="114x114" href="favicon/apple-icon-114x114.png?v=<?= htmlspecialchars($cacheBust) ?>">
    <link rel="apple-touch-icon" sizes="120x120" href="favicon/apple-icon-120x120.png?v=<?= htmlspecialchars($cacheBust) ?>">
    <link rel="apple-touch-icon" sizes="144x144" href="favicon/apple-icon-144x144.png?v=<?= htmlspecialchars($cacheBust) ?>">
    <link rel="apple-touch-icon" sizes="152x152" href="favicon/apple-icon-152x152.png?v=<?= htmlspecialchars($cacheBust) ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="favicon/apple-icon-180x180.png?v=<?= htmlspecialchars($cacheBust) ?>">
    <link rel="manifest" href="favicon/manifest.json?v=<?= htmlspecialchars($cacheBust) ?>">
    <meta name="msapplication-TileColor" content="#C11313">
    <meta name="msapplication-TileImage" content="favicon/ms-icon-144x144.png?v=<?= htmlspecialchars($cacheBust) ?>">
    <meta name="msapplication-config" content="favicon/browserconfig.xml?v=<?= htmlspecialchars($cacheBust) ?>">
    <meta name="theme-color" content="#C11313">

    <?php
    // Font Awesome 6.5.1 Pro autohospedado (assets/fontawesome/). `all.min.css`
    // trae las familias Classic (solid/regular/light/thin), Duotone y Brands;
    // las cuatro hojas `sharp-*` agregan los @font-face de la familia Sharp,
    // que `all.min.css` mapea pero no declara.
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
<body data-version="<?= htmlspecialchars($cacheBust, ENT_QUOTES) ?>">

<div class="version-banner" id="version-banner" role="status" hidden>
    <span class="version-banner-text">Hay una nueva versión disponible.</span>
    <button type="button" class="version-banner-btn" id="version-banner-btn">Actualizar ahora</button>
</div>

<div class="layout">

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-logo">
            <img src="assets/img/reactor_white.png?v=<?= htmlspecialchars($cacheBust) ?>"
                 alt="Reactor" class="sidebar-logo-mark">
        </div>

        <nav class="sidebar-nav">
            <!-- Iconografia del menu: FontAwesome solid autohospedado
                 (assets/fontawesome/), no emojis. `.nav-icon` da el ancho fijo
                 de 20px que alinea todas las etiquetas en la misma columna. -->
            <div class="nav-group-wrap" data-group="inicio">
                <button type="button" class="nav-item nav-group-toggle">
                    <i class="fa-solid fa-house nav-icon"></i>
                    <span class="nav-group-label">Inicio</span>
                    <span class="nav-group-arrow">+</span>
                </button>
                <div class="nav-sub">
                    <a href="#/dashboard" data-route="dashboard" class="nav-item nav-sub-item active">
                        <i class="fa-solid fa-gauge-high nav-icon"></i> Dashboard
                    </a>
                </div>
            </div>

            <div class="nav-group-wrap" data-group="propiedad">
                <button type="button" class="nav-item nav-group-toggle">
                    <i class="fa-solid fa-building nav-icon"></i>
                    <span class="nav-group-label">Propiedad</span>
                    <span class="nav-group-arrow">+</span>
                </button>
                <div class="nav-sub">
                    <a href="#/dominios" data-route="dominios" class="nav-item nav-sub-item">
                        <i class="fa-solid fa-flag nav-icon"></i> Dominios
                    </a>
                </div>
            </div>

            <div class="nav-group-wrap" data-group="inventario">
                <button type="button" class="nav-item nav-group-toggle">
                    <i class="fa-solid fa-boxes-stacked nav-icon"></i>
                    <span class="nav-group-label">Inventario</span>
                    <span class="nav-group-arrow">+</span>
                </button>
                <div class="nav-sub">
                    <a href="#/dispositivos" data-route="dispositivos" class="nav-item nav-sub-item">
                        <i class="fa-solid fa-microchip nav-icon"></i> Dispositivos
                    </a>
                    <a href="#/chips" data-route="chips" class="nav-item nav-sub-item">
                        <i class="fa-solid fa-sim-card nav-icon"></i> Chips
                    </a>
                    <a href="#/transceptores" data-route="transceptores" class="nav-item nav-sub-item">
                        <i class="fa-solid fa-walkie-talkie nav-icon"></i> Transceptores
                    </a>
                </div>
            </div>

            <div class="nav-group-wrap" data-group="registros">
                <button type="button" class="nav-item nav-group-toggle">
                    <i class="fa-solid fa-clipboard-list nav-icon"></i>
                    <span class="nav-group-label">Registros</span>
                    <span class="nav-group-arrow">+</span>
                </button>
                <div class="nav-sub">
                    <a href="#/signals" data-route="signals" class="nav-item nav-sub-item">
                        <i class="fa-solid fa-signal-stream nav-icon"></i> Señales
                    </a>
                    <a href="#/registros" data-route="registros" class="nav-item nav-sub-item">
                        <i class="fa-solid fa-scroll nav-icon"></i> Registros
                    </a>
                    <a href="#/alerts" data-route="alerts" class="nav-item nav-sub-item">
                        <i class="fa-solid fa-bell nav-icon"></i> Alertas
                    </a>
                </div>
            </div>

            <div class="nav-group-wrap" data-group="seguridad">
                <button type="button" class="nav-item nav-group-toggle">
                    <i class="fa-solid fa-shield-halved nav-icon"></i>
                    <span class="nav-group-label">Seguridad</span>
                    <span class="nav-group-arrow">+</span>
                </button>
                <div class="nav-sub">
                    <a href="#/users" data-route="users" class="nav-item nav-sub-item">
                        <i class="fa-solid fa-users nav-icon"></i> Usuarios
                    </a>
                    <a href="#/profiles" data-route="profiles" class="nav-item nav-sub-item">
                        <i class="fa-solid fa-id-card nav-icon"></i> Perfiles
                    </a>
                </div>
            </div>

            <div class="nav-group-wrap" data-group="administracion">
                <button type="button" class="nav-item nav-group-toggle">
                    <i class="fa-solid fa-screwdriver-wrench nav-icon"></i>
                    <span class="nav-group-label">Administración</span>
                    <span class="nav-group-arrow">+</span>
                </button>
                <div class="nav-sub">
                    <a href="#/tools" data-route="tools" class="nav-item nav-sub-item">
                        <i class="fa-solid fa-toolbox nav-icon"></i> Herramientas
                    </a>
                </div>
            </div>
        </nav>

        <div class="sidebar-footer">v0.1.0</div>
    </aside>

    <div class="sidebar-overlay" id="sidebar-overlay"></div>

    <div class="main">
        <div class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger" aria-label="Abrir menú">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-title" id="view-title">Dashboard</div>
            </div>
            <div class="topbar-user">
                <div class="topbar-user-wrap">
                    <button class="topbar-username" id="btn-user">
                        <i class="fa-solid fa-circle-user"></i>
                        <span><?= htmlspecialchars($userDisplay) ?></span>
                        <i class="fa-solid fa-caret-down" style="font-size:.7rem"></i>
                    </button>
                    <div class="user-dropdown" id="user-dropdown">
                        <a href="#" class="user-dropdown-item" id="btn-logout">Salir</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="content" id="view"></div>
    </div>

</div>

<div class="toast" id="toast"></div>

<script src="assets/js/app.js?v=<?= htmlspecialchars($cacheBust) ?>"></script>
</body>
</html>
