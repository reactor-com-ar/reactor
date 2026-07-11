<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/env.php';
require_once __DIR__ . '/lib/auth_check.php';

$currentUser = authUser();
if ($currentUser === null) {
    header('Location: login.php');
    exit;
}

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
<body>

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
                    <a href="#" class="user-dropdown-item" id="btn-logout">Cerrar sesión</a>
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
