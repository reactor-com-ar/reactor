<?php
declare(strict_types=1);

require_once __DIR__ . '/api/auth.php';
reactor_session_start();

$currentUser = reactor_current_user();
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

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet"
          href="assets/css/style.css?v=<?= htmlspecialchars($cacheBust) ?>">
</head>
<body>

<div class="layout">

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-logo">
            <img src="assets/img/reactor_white.png?v=<?= htmlspecialchars($cacheBust) ?>"
                 alt="Reactor" class="sidebar-logo-mark">
        </div>

        <nav class="sidebar-nav">
            <div class="nav-group-wrap" data-group="inicio">
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

            <div class="nav-group-wrap" data-group="propiedad">
                <button type="button" class="nav-item nav-group-toggle">
                    <span class="nav-icon">🏢</span>
                    <span class="nav-group-label">Propiedad</span>
                    <span class="nav-group-arrow">+</span>
                </button>
                <div class="nav-sub">
                    <a href="#/dominios" data-route="dominios" class="nav-item nav-sub-item">
                        <span class="nav-icon">🗂️</span> Dominios
                    </a>
                </div>
            </div>

            <div class="nav-group-wrap" data-group="inventario">
                <button type="button" class="nav-item nav-group-toggle">
                    <span class="nav-icon">📦</span>
                    <span class="nav-group-label">Inventario</span>
                    <span class="nav-group-arrow">+</span>
                </button>
                <div class="nav-sub">
                    <a href="#/dispositivos" data-route="dispositivos" class="nav-item nav-sub-item">
                        <span class="nav-icon">🛰️</span> Dispositivos
                    </a>
                    <a href="#/chips" data-route="chips" class="nav-item nav-sub-item">
                        <span class="nav-icon">📶</span> Chips
                    </a>
                    <a href="#/transceptores" data-route="transceptores" class="nav-item nav-sub-item">
                        <span class="nav-icon">📻</span> Transceptores
                    </a>
                </div>
            </div>

            <div class="nav-group-wrap" data-group="registros">
                <button type="button" class="nav-item nav-group-toggle">
                    <span class="nav-icon">📋</span>
                    <span class="nav-group-label">Registros</span>
                    <span class="nav-group-arrow">+</span>
                </button>
                <div class="nav-sub">
                    <a href="#/signals" data-route="signals" class="nav-item nav-sub-item">
                        <span class="nav-icon">📡</span> Señales
                    </a>
                    <a href="#/registros" data-route="registros" class="nav-item nav-sub-item">
                        <span class="nav-icon">📜</span> Registros
                    </a>
                    <a href="#/alerts" data-route="alerts" class="nav-item nav-sub-item">
                        <span class="nav-icon">🔔</span> Alertas
                    </a>
                </div>
            </div>

            <div class="nav-group-wrap" data-group="seguridad">
                <button type="button" class="nav-item nav-group-toggle">
                    <span class="nav-icon">🔐</span>
                    <span class="nav-group-label">Seguridad</span>
                    <span class="nav-group-arrow">+</span>
                </button>
                <div class="nav-sub">
                    <a href="#/users" data-route="users" class="nav-item nav-sub-item">
                        <span class="nav-icon">👥</span> Usuarios
                    </a>
                    <a href="#/profiles" data-route="profiles" class="nav-item nav-sub-item">
                        <span class="nav-icon">🪪</span> Perfiles
                    </a>
                </div>
            </div>

            <div class="nav-group-wrap" data-group="administracion">
                <button type="button" class="nav-item nav-group-toggle">
                    <span class="nav-icon">🛠️</span>
                    <span class="nav-group-label">Administración</span>
                    <span class="nav-group-arrow">+</span>
                </button>
                <div class="nav-sub">
                    <a href="#/tools" data-route="tools" class="nav-item nav-sub-item">
                        <span class="nav-icon">🧪</span> Herramientas
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
                <button class="hamburger" id="hamburger" aria-label="Abrir menú">☰</button>
                <div class="topbar-title" id="view-title">Dashboard</div>
            </div>
            <div class="topbar-user">
                <button class="btn btn-ghost btn-sm" id="btn-refresh" title="Refrescar">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </button>
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
