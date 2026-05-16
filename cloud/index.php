<?php
declare(strict_types=1);
$appName = 'cloud';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reactor · <?= htmlspecialchars($appName) ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>

<div class="app-shell">

    <aside class="sidebar">
        <div class="sidebar-brand">
            <i class="bi bi-cpu-fill"></i>
            <div>
                <div class="brand-title">REACTOR</div>
                <div class="brand-subtitle">cloud</div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <a href="#/dashboard" data-route="dashboard" class="nav-item active">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <a href="#/devices" data-route="devices" class="nav-item">
                <i class="bi bi-hdd-stack"></i> Dispositivos
            </a>
            <a href="#/alerts" data-route="alerts" class="nav-item">
                <i class="bi bi-bell"></i> Alertas
            </a>
            <a href="#/users" data-route="users" class="nav-item">
                <i class="bi bi-people"></i> Usuarios
            </a>
            <a href="#/settings" data-route="settings" class="nav-item">
                <i class="bi bi-gear"></i> Configuracion
            </a>
        </nav>

        <div class="sidebar-footer">
            <small>v0.1.0</small>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div class="topbar-title" id="view-title">Dashboard</div>
            <div class="topbar-actions">
                <button class="btn btn-sm btn-outline-light" id="btn-refresh">
                    <i class="bi bi-arrow-clockwise"></i> Refrescar
                </button>
                <div class="user-chip">
                    <i class="bi bi-person-circle"></i>
                    <span>admin</span>
                </div>
            </div>
        </header>

        <section class="content" id="view"></section>
    </main>

</div>

<script src="assets/js/app.js"></script>
</body>
</html>
