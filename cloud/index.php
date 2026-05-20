<?php
declare(strict_types=1);

$appName = 'Reactor Cloud';
$versionFile = __DIR__ . '/version.txt';
$cacheBust = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : (string) time();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($appName) ?></title>

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
                        <span>admin</span>
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
