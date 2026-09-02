<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/env.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sesion.php';

$currentUser = authUser();
if ($currentUser === null) {
    header('Location: login.php');
    exit;
}

// Datos de alcance de la sesion (dominio, perfil). Se inyectan en la pagina
// para que el front los tenga en el arranque, sin un request extra.
$sesion = sessionContext() ?? [];

$appName     = 'Reactor Panel';
$versionFile = __DIR__ . '/version.txt';
$cacheBust   = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : (string) time();

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
                 de 20px que alinea todas las etiquetas en la misma columna.

                 TODAS las categorias arrancan con `.open`: el menu se ve
                 desplegado por completo al entrar. Colapsar es una accion
                 del usuario (no se persiste), y `setActiveLink()` de app.js
                 reabre la categoria de la ruta activa. -->
            <div class="nav-group-wrap open" data-group="general">
                <button type="button" class="nav-item nav-group-toggle">
                    <i class="fa-solid fa-house nav-icon"></i>
                    <span class="nav-group-label">General</span>
                    <span class="nav-group-arrow">+</span>
                </button>
                <div class="nav-sub">
                    <a href="#/dashboard" data-route="dashboard" class="nav-item nav-sub-item active">
                        <i class="fa-solid fa-gauge-high nav-icon"></i> Dashboard
                    </a>
                </div>
            </div>

            <!-- Menu portado del legacy. -->
            <div class="nav-group-wrap open" data-group="inventario">
                <button type="button" class="nav-item nav-group-toggle">
                    <i class="fa-solid fa-boxes-stacked nav-icon"></i>
                    <span class="nav-group-label">Inventario</span>
                    <span class="nav-group-arrow">+</span>
                </button>
                <div class="nav-sub">
                    <a href="#/dominio" data-route="dominio" class="nav-item nav-sub-item">
                        <i class="fa-solid fa-flag nav-icon"></i> Dominio
                    </a>
                    <a href="#/usuarios" data-route="usuarios" class="nav-item nav-sub-item">
                        <i class="fa-solid fa-users nav-icon"></i> Usuarios
                    </a>
                    <a href="#/dispositivos" data-route="dispositivos" class="nav-item nav-sub-item">
                        <i class="fa-solid fa-microchip nav-icon"></i> Dispositivos
                    </a>
                    <a href="#/chips" data-route="chips" class="nav-item nav-sub-item">
                        <i class="fa-solid fa-sim-card nav-icon"></i> Chips
                    </a>
                </div>
            </div>

            <div class="nav-group-wrap open" data-group="historial">
                <button type="button" class="nav-item nav-group-toggle">
                    <i class="fa-solid fa-clock-rotate-left nav-icon"></i>
                    <span class="nav-group-label">Historial</span>
                    <span class="nav-group-arrow">+</span>
                </button>
                <div class="nav-sub">
                    <a href="#/actividad" data-route="actividad" class="nav-item nav-sub-item">
                        <i class="fa-solid fa-clipboard-list nav-icon"></i> Actividad
                    </a>
                    <a href="#/invitaciones" data-route="invitaciones" class="nav-item nav-sub-item">
                        <i class="fa-solid fa-envelope-open-text nav-icon"></i> Invitaciones
                    </a>
                </div>
            </div>

            <div class="nav-group-wrap open" data-group="cuenta">
                <button type="button" class="nav-item nav-group-toggle">
                    <i class="fa-solid fa-wallet nav-icon"></i>
                    <span class="nav-group-label">Cuenta</span>
                    <span class="nav-group-arrow">+</span>
                </button>
                <div class="nav-sub">
                    <a href="#/facturas" data-route="facturas" class="nav-item nav-sub-item">
                        <i class="fa-solid fa-file-invoice-dollar nav-icon"></i> Facturas
                    </a>
                    <a href="#/recibos" data-route="recibos" class="nav-item nav-sub-item">
                        <i class="fa-solid fa-receipt nav-icon"></i> Recibos
                    </a>
                    <a href="#/facturacion" data-route="facturacion" class="nav-item nav-sub-item">
                        <i class="fa-solid fa-calculator nav-icon"></i> Facturación
                    </a>
                </div>
            </div>
        </nav>
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
                <button class="topbar-username" id="btn-user" type="button">
                    <i class="fa-solid fa-circle-user"></i>
                    <span><?= htmlspecialchars($userDisplay) ?></span>
                    <i class="fa-solid fa-caret-down" style="font-size:.7rem"></i>
                </button>
                <div class="user-dropdown" id="user-dropdown">
                    <a href="#" class="user-dropdown-item" id="btn-dominio">
                        <i class="fa-solid fa-recycle"></i>Cambiar dominio
                    </a>
                    <a href="#" class="user-dropdown-item" id="btn-perfil">Mi cuenta</a>
                    <a href="#" class="user-dropdown-item" id="btn-logout">Cerrar sesión</a>
                </div>
            </div>
        </div>

        <div class="content" id="view"></div>
    </div>

</div>

<div class="toast" id="toast"></div>

<script id="panel-sesion" type="application/json">
<?= json_encode($sesion, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
</script>

<script src="assets/js/app.js?v=<?= htmlspecialchars($cacheBust) ?>"></script>
</body>
</html>
