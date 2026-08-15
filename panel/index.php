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

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
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
            <div class="nav-group-wrap open" data-group="inicio">
                <button type="button" class="nav-item nav-group-toggle">
                    <span class="nav-icon">🏠</span>
                    <span class="nav-group-label">Inicio</span>
                    <span class="nav-group-arrow">+</span>
                </button>
                <div class="nav-sub">
                    <a href="#/dashboard" data-route="dashboard" class="nav-item nav-sub-item active">
                        <span class="nav-icon">📊</span> Dashboard
                    </a>
                </div>
            </div>

            <!-- Menu portado del legacy. Los sub-items todavia no tienen
                 modulo: se muestran, pero no navegan (clase .nav-soon). -->
            <div class="nav-group-wrap" data-group="inventario">
                <button type="button" class="nav-item nav-group-toggle">
                    <span class="nav-icon">🔌</span>
                    <span class="nav-group-label">Inventario</span>
                    <span class="nav-group-arrow">+</span>
                </button>
                <div class="nav-sub">
                    <a href="#" class="nav-item nav-sub-item nav-soon">
                        <span class="nav-icon">🏳️</span> Dominio
                    </a>
                    <a href="#/usuarios" data-route="usuarios" class="nav-item nav-sub-item">
                        <span class="nav-icon">👥</span> Usuarios
                    </a>
                    <a href="#" class="nav-item nav-sub-item nav-soon">
                        <span class="nav-icon">📟</span> Dispositivos
                    </a>
                    <a href="#" class="nav-item nav-sub-item nav-soon">
                        <span class="nav-icon">💳</span> Chips
                    </a>
                </div>
            </div>

            <div class="nav-group-wrap" data-group="historial">
                <button type="button" class="nav-item nav-group-toggle">
                    <span class="nav-icon">🕘</span>
                    <span class="nav-group-label">Historial</span>
                    <span class="nav-group-arrow">+</span>
                </button>
                <div class="nav-sub">
                    <a href="#" class="nav-item nav-sub-item nav-soon">
                        <span class="nav-icon">🔑</span> Invitaciones
                    </a>
                </div>
            </div>

            <div class="nav-group-wrap" data-group="cuenta">
                <button type="button" class="nav-item nav-group-toggle">
                    <span class="nav-icon">🚩</span>
                    <span class="nav-group-label">Cuenta</span>
                    <span class="nav-group-arrow">+</span>
                </button>
                <div class="nav-sub">
                    <a href="#" class="nav-item nav-sub-item nav-soon">
                        <span class="nav-icon">📄</span> Facturas
                    </a>
                    <a href="#" class="nav-item nav-sub-item nav-soon">
                        <span class="nav-icon">🧾</span> Recibos
                    </a>
                    <a href="#" class="nav-item nav-sub-item nav-soon">
                        <span class="nav-icon">🧮</span> Facturación
                    </a>
                </div>
            </div>
        </nav>
    </aside>

    <div class="sidebar-overlay" id="sidebar-overlay"></div>

    <div class="main">
        <div class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger" aria-label="Abrir menú">☰</button>
                <div class="topbar-title" id="view-title">Dashboard</div>
            </div>
            <div class="topbar-user">
                <button class="topbar-username" id="btn-user" type="button">
                    <i class="fa-solid fa-circle-user"></i>
                    <span><?= htmlspecialchars($userDisplay) ?></span>
                    <i class="fa-solid fa-caret-down" style="font-size:.7rem"></i>
                </button>
                <div class="user-dropdown" id="user-dropdown">
                    <a href="#" class="user-dropdown-item" id="btn-perfil">Perfil</a>
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
