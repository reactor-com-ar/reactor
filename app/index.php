<?php
declare(strict_types=1);

/*
 * Reactor App (end-user) — panel de control.
 *
 * La AUTENTICACION ya es real (ver lib/auth.php): sin sesion valida esto
 * redirige a /sesion/iniciar.php, y si el navegador trae la cookie legacy
 * `sesionToken` la sesion se adopta sola, sin re-login.
 *
 * El CONTENIDO sigue siendo mockup: los panels, controles y botones estan
 * hardcodeados abajo. Cuando este validado el look & feel se cablea contra
 * `paneles` / `controles` / `botones` / `dispositivos`.
 */

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/contexto.php';
require_once __DIR__ . '/lib/controles.php';

$usuario = requireAuth();

$appName    = 'Reactor';
$versionFile = __DIR__ . '/version.txt';
$cacheBust  = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : (string) time();

// Dominio y panel que muestra la franja del encabezado. Son los MISMOS que
// marcan como actuales los modales "Cambiar de Dominio" y "Cambiar de Panel":
// el dominio sale del perfil activo y el panel de `perfiles.panel`, resueltos
// los dos en lib/contexto.php.
$contexto     = appContextoSesion($usuario);
$panelActivo  = appPanelesDelDominio($contexto['dominio'], $contexto['panel']);

$dominioNombre = $contexto['nombre'] !== '' ? $contexto['nombre'] : '—';
$panelNombre   = $panelActivo['nombre'];

// Detalles del dominio activo para el modal del mismo nombre. `Perfil` es el
// rol del usuario EN ESTE dominio (Administrador / Operador / ...), que sale
// del perfil activo; los tres contadores son columnas denormalizadas de
// `dominios`. El legacy (`dominio/detalles.php`) mostraba solo nombre,
// usuarios y dispositivos: perfil y chips se suman aca.
$contadores = appDominioContadores($contexto['dominio']);

$dominioDetalles = [
    'Nombre'       => $dominioNombre,
    'Perfil'       => $contexto['rol'] !== '' ? $contexto['rol'] : '—',
    'Usuarios'     => (string) $contadores['usuarios'],
    'Dispositivos' => (string) $contadores['dispositivos'],
    'Chips'        => (string) $contadores['chips'],
];

// Situacion del dominio (misma semantica que `dominios.situacion` en el legacy):
// 1 = normal, 2 = por suspender (advertencia), 3 = suspendido.
$dominioSituacion = 2;

// Mesa de ayuda: WhatsApp de soporte. Lo usan las dos entradas (topbar y Ajustes).
$soporteUrl = 'https://api.whatsapp.com/send/?phone=5491163099315&text=Hola+Reactor&type=phone_number&app_absent=0';

// Lado servidor del modal "Entorno": el equivalente del `print_r($_SESSION)`
// de `reactor-app/cuenta/entorno.php`.
//
// Lo que alla son variables de `$_SESSION` aca son CLAIMS DEL TOKEN, porque no
// hay sesion de servidor (ver `appContextoSesion()`). Se listan igual, y con
// los mismos nombres del legacy entre parentesis, para poder comparar las dos
// pantallas de un vistazo.
//
// Que NO se lista, y por que:
//   - El token en si (el `sesionToken` del legacy, que lo imprime entero). Es
//     HttpOnly justamente para que ningun script lo lea; imprimirlo en el HTML
//     anularia esa proteccion y dejaria la sesion a merced de cualquier XSS.
//     De cada cookie se muestra solo si esta o no.
//   - `sesionPermisos` y `sesionRoles`: son del back office (`roles2permisos()`
//     se usa para gatear pantallas de admin), no de esta app.
$entornoSesion = [
    'Id de usuario (usuarioId)'   => (string) ($usuario['id'] ?? ''),
    'Usuario'                     => (string) ($usuario['usuario'] ?? ''),
    'Nombre (usuarioNombre)'      => (string) ($usuario['nombre'] ?? ''),
    'Correo'                      => (string) ($usuario['correo'] ?? ''),
    'Habilitado'                  => ((string) ($usuario['habilitado'] ?? '') === '1') ? 'Si' : 'No',
    'Perfil (sesionPerfil)'       => (string) $contexto['perfil'],
    'Dominio (sesionDominio)'     => (string) $contexto['dominio'],
    'Nombre del dominio'          => $dominioNombre,
    'Rol en el dominio'           => $contexto['rol'] !== '' ? $contexto['rol'] : '—',
    'Panel (sesionPanel)'         => (string) $contexto['panel'],
    'Nombre del panel'            => $panelNombre !== '' ? $panelNombre : '—',
    // De donde salio el alcance de arriba: 'token' = lo traia el JWT (el caso
    // normal), 'db' = el token no lo traia o quedo viejo y se resolvio contra
    // la base. Es el mismo indicador que expone `sessionContext()` en panel/.
    'Origen del alcance'          => $contexto['origen'],
    'Version de la app'           => $cacheBust,
];

// Presencia (no contenido) de las cookies de sesion.
$entornoCookies = [
    'Cookie ' . APP_COOKIE        => isset($_COOKIE[APP_COOKIE]) ? 'presente (HttpOnly)' : 'ausente',
    'Cookie ' . APP_COOKIE_LEGACY => isset($_COOKIE[APP_COOKIE_LEGACY]) ? 'presente (HttpOnly)' : 'ausente',
];

// Se mantiene el nombre viejo para la vista, que lo pinta como una sola tabla.
$entornoServidor = $entornoSesion + $entornoCookies;

// ---- Controles del panel abierto (datos reales) ----
// Port de los bucles de `reactor-app/panel/index.php`. La consulta vive en
// lib/controles.php porque `api/canales.php` la reusa para el sondeo del
// estado. Ojo: `canales.estado` lo escribe el motor Python cuando el equipo
// reporta; esta pantalla sólo lo lee.
$controles = appControlesDelPanel($contexto['panel'], $contexto['dominio']);

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

    <!-- La fuente "LCD" del titulo del display se sirve local
         (assets/fonts/small-lcd-sign.ttf, @font-face en style.css) -->
    <link rel="stylesheet"
          href="assets/css/style.css?v=<?= $cb ?>">
</head>
<body data-version="<?= $cb ?>">

<div class="version-banner" id="version-banner" role="status" hidden>
    <span class="version-banner-text">Hay una nueva versi&oacute;n disponible.</span>
    <button type="button" class="version-banner-btn" id="version-banner-btn">Actualizar ahora</button>
</div>

<div class="layout">

    <!-- Topbar rojo: a la izquierda el hamburger y despues el logo (en ese
         orden); a la derecha las acciones. -->
    <header class="topbar">
        <button type="button" class="topbar-hamburger" id="hamburger" aria-label="Abrir men&uacute;">
            <i class="fa-solid fa-bars"></i>
        </button>

        <img src="assets/img/reactor_white.png?v=<?= $cb ?>"
             alt="Reactor" class="topbar-logo">

        <div class="topbar-spacer"></div>

        <nav class="topbar-actions">
            <button type="button" class="topbar-action active" title="Inicio" data-nav="inicio">
                <i class="fa-solid fa-house"></i>
            </button>
            <button type="button" class="topbar-action" title="Cambiar de Panel"
                    data-nav="panel" data-modal="modal-panel">
                <i class="fa-solid fa-pager"></i>
            </button>
            <button type="button" class="topbar-action" title="Cambiar de Dominio"
                    data-nav="dominio" data-modal="modal-dominio">
                <i class="fa-solid fa-location-dot"></i>
            </button>
            <a href="<?= htmlspecialchars($soporteUrl, ENT_QUOTES) ?>"
               target="_blank" rel="noopener noreferrer"
               class="topbar-action" title="Mesa de Ayuda" data-nav="soporte">
                <i class="fa-solid fa-headset"></i>
            </a>
        </nav>
    </header>

    <div class="main-row">

        <!-- Sidebar oscuro (menu vertical) -->
        <aside class="sidebar" id="sidebar">
            <nav class="sidebar-nav">
                <a href="#/inicio" class="nav-item active" data-route="inicio">
                    <i class="fa-solid fa-house"></i> Inicio
                </a>
                <div class="nav-group">
                    <a href="#/cuenta" class="nav-item nav-toggle" data-route="cuenta"
                       role="button" aria-expanded="false" aria-controls="submenu-cuenta">
                        <i class="fa-solid fa-circle-user"></i> Mi Cuenta
                        <i class="fa-solid fa-chevron-down nav-caret"></i>
                    </a>
                    <div class="nav-submenu" id="submenu-cuenta">
                        <a href="#/cuenta/usuario" class="nav-subitem" data-route="cuenta-usuario"
                           data-modal="modal-usuario">
                            <i class="fa-solid fa-user"></i> Mi Usuario
                        </a>
                        <a href="#/cuenta/entorno" class="nav-subitem" data-route="cuenta-entorno"
                           data-modal="modal-entorno">
                            <i class="fa-solid fa-layer-group"></i> Entorno
                        </a>
                        <a href="/sesion/cerrar" class="nav-subitem" data-route="cuenta-salir">
                            <i class="fa-solid fa-right-from-bracket"></i> Cerrar Sesi&oacute;n
                        </a>
                    </div>
                </div>
                <div class="nav-group">
                    <a href="#/dominio" class="nav-item nav-toggle" data-route="dominio"
                       role="button" aria-expanded="false" aria-controls="submenu-dominio">
                        <i class="fa-solid fa-location-dot"></i> Mi Dominio
                        <i class="fa-solid fa-chevron-down nav-caret"></i>
                    </a>
                    <div class="nav-submenu" id="submenu-dominio">
                        <a href="#/dominio/detalles" class="nav-subitem" data-route="dominio-detalles"
                           data-modal="modal-dominio-detalles">
                            <i class="fa-solid fa-circle-info"></i> Detalles
                        </a>
                        <a href="#/dominio/actividad" class="nav-subitem" data-route="dominio-actividad"
                           data-modal="modal-actividad">
                            <i class="fa-solid fa-clock-rotate-left"></i> Actividad
                        </a>
                        <a href="#/dominio/invitar" class="nav-subitem" data-route="dominio-invitar"
                           data-modal="modal-invitar">
                            <i class="fa-solid fa-user-plus"></i> Invitar un Usuario
                        </a>
                    </div>
                </div>
                <div class="nav-group">
                    <a href="#/ajustes" class="nav-item nav-toggle" data-route="ajustes"
                       role="button" aria-expanded="false" aria-controls="submenu-ajustes">
                        <i class="fa-solid fa-gear"></i> Ajustes
                        <i class="fa-solid fa-chevron-down nav-caret"></i>
                    </a>
                    <div class="nav-submenu" id="submenu-ajustes">
                        <a href="#/ajustes/notificaciones" class="nav-subitem" data-route="ajustes-notificaciones"
                           data-modal="modal-notificaciones">
                            <i class="fa-solid fa-bell"></i> Notificaciones
                        </a>
                        <a href="<?= htmlspecialchars($soporteUrl, ENT_QUOTES) ?>"
                           target="_blank" rel="noopener noreferrer"
                           class="nav-subitem" data-route="ajustes-ayuda">
                            <i class="fa-solid fa-headset"></i> Mesa de Ayuda
                        </a>
                    </div>
                </div>
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
                <?php if ($panelNombre !== ''): ?>
                    <br>
                    <span class="panel-nombre"><?= htmlspecialchars($panelNombre) ?></span>
                <?php endif; ?>
            </div>

            <div class="panel-feed">

                <?php if ($dominioSituacion === 2): ?>
                    <div class="advertencia" role="alert">
                        <h4>Advertencia</h4>
                        <div>Su cuenta pronto ser&aacute; suspendida por falta de pago.</div>
                    </div>
                <?php elseif ($dominioSituacion === 3): ?>
                    <div class="advertencia" role="alert">
                        <h4>Advertencia</h4>
                        <div>Su cuenta ha sido suspendida por falta de pago.
                             Comun&iacute;quese con el administrador del dominio.</div>
                    </div>
                <?php endif; ?>

                <?php if (!$controles): ?>
                    <p class="lista-aviso">Este panel no tiene controles.</p>
                <?php endif; ?>

                <?php foreach ($controles as $c): ?>
                    <?php $off = !$c['online']; ?>
                    <article class="panel" data-control="<?= (int) $c['id'] ?>">

                        <!-- El color sale de `controles.color` -> `colores.codigo`, inline
                             como en el legacy: es dato, no diseño, y cambia por control. -->
                        <div class="<?= $off ? 'display-pantalla off' : 'display-pantalla' ?>"
                             data-rol="display"
                             style="background: <?= htmlspecialchars($c['color'], ENT_QUOTES) ?>;">

                            <div class="display-status">
                                <span class="left" data-rol="enlace">
                                    <?php if ($off): ?>
                                        <i class="fa-solid fa-plug"></i> <?= htmlspecialchars($c['estadoTexto']) ?>
                                    <?php else: ?>
                                        <i class="fa-solid fa-wifi"></i> <?= (int) $c['senal'] ?>%
                                    <?php endif; ?>
                                </span>
                                <span class="right" data-rol="power">
                                    <?php if (!$off): ?>
                                        100% <i class="fa-solid fa-battery-full"></i>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <div class="display-titulo">
                                <?= htmlspecialchars($c['nombre']) ?>
                            </div>

                            <div class="canales-fila" data-rol="canales">
                                <?php foreach ($c['canales'] as $canal): ?>
                                    <?php if ($canal['sensor']): ?>
                                        <span class="canal-valor"><?= htmlspecialchars($canal['valor']) ?></span>
                                    <?php else: ?>
                                        <span class="canal-estado <?= $canal['on'] ? 'on' : '' ?>">
                                            <?= (int) $canal['n'] ?>
                                        </span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>

                        </div>

                        <div class="tec-btns">
                            <?php foreach ($c['botones'] as $b): ?>
                                <button type="button" class="tec-btn"
                                        data-boton="<?= (int) $b['id'] ?>"
                                        data-accion="<?= htmlspecialchars($b['texto'], ENT_QUOTES) ?>">
                                    <?php if ($b['icono'] !== ''): ?>
                                        <i class="<?= htmlspecialchars($b['icono'], ENT_QUOTES) ?>"></i>
                                    <?php endif; ?>
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

<!-- Modal "Mi Usuario": datos de solo lectura + cambio de contraseña.
     Nombre, correo y celular NO se editan desde la app; lo unico que el
     usuario puede cambiar de si mismo es la contraseña. -->
<!-- Modal "Cambiar de Panel": un boton por panel del dominio. La lista puede
     ser larga, asi que scrollea dentro del modal. -->
<div class="modal-fondo" id="modal-panel">
    <div class="modal-caja" role="dialog" aria-modal="true" aria-labelledby="modal-panel-titulo">

        <header class="modal-cabecera">
            <h2 class="modal-titulo" id="modal-panel-titulo">
                <i class="fa-solid fa-pager"></i> Cambiar de Panel
            </h2>
            <button type="button" class="modal-cerrar" data-modal-cerrar aria-label="Cerrar">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </header>

        <!-- La lista la trae api/paneles al abrir: son los paneles del dominio
             activo, que cambia sin que cambie esta pagina. -->
        <div class="modal-cuerpo">
            <div class="opcion-lista" id="panel-lista">
                <p class="lista-aviso">Cargando&hellip;</p>
            </div>
        </div>
    </div>
</div>

<!-- Modal "Actividad": ultimos 50 registros del dominio. El contenido lo trae
     api/actividad.php al abrir (datos reales, no mock). -->
<div class="modal-fondo" id="modal-actividad">
    <div class="modal-caja" role="dialog" aria-modal="true"
         aria-labelledby="modal-actividad-titulo">

        <header class="modal-cabecera">
            <h2 class="modal-titulo" id="modal-actividad-titulo">
                <i class="fa-solid fa-clock-rotate-left"></i> Actividad
            </h2>
            <button type="button" class="modal-cerrar" data-modal-cerrar aria-label="Cerrar">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </header>

        <div class="modal-cuerpo modal-cuerpo-lista" id="actividad-lista">
            <p class="lista-aviso">Cargando&hellip;</p>
        </div>
    </div>
</div>

<!-- Modal "Notificaciones": ultimas 50 del usuario. Abrirlo las marca leidas,
     igual que la pantalla del legacy. -->
<div class="modal-fondo" id="modal-notificaciones">
    <div class="modal-caja" role="dialog" aria-modal="true"
         aria-labelledby="modal-notificaciones-titulo">

        <header class="modal-cabecera">
            <h2 class="modal-titulo" id="modal-notificaciones-titulo">
                <i class="fa-solid fa-bell"></i> Notificaciones
            </h2>
            <button type="button" class="modal-cerrar" data-modal-cerrar aria-label="Cerrar">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </header>

        <div class="modal-cuerpo modal-cuerpo-lista" id="notificaciones-lista">
            <p class="lista-aviso">Cargando&hellip;</p>
        </div>
    </div>
</div>

<!-- Modal "Invitar un Usuario": todavia no esta implementado. El item queda en
     el menu (el legacy tiene la pantalla en `dominio/invitar.php`) pero avisa
     que falta, en vez de abrir algo a medio hacer. -->
<div class="modal-fondo" id="modal-invitar">
    <div class="modal-caja" role="dialog" aria-modal="true" aria-labelledby="modal-invitar-titulo">

        <header class="modal-cabecera">
            <h2 class="modal-titulo" id="modal-invitar-titulo">
                <i class="fa-solid fa-user-plus"></i> Invitar un Usuario
            </h2>
            <button type="button" class="modal-cerrar" data-modal-cerrar aria-label="Cerrar">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </header>

        <div class="modal-cuerpo">
            <div class="proximamente">
                <i class="fa-solid fa-clock proximamente-ico"></i>
                <p class="proximamente-titulo">Pr&oacute;ximamente</p>
                <p class="proximamente-texto">
                    Muy pronto vas a poder invitar usuarios a tu dominio desde ac&aacute;.
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Modal "Detalles de Dominio": solo informacion, sin acciones. Campos en el
     formato del legacy: etiqueta a la izquierda y valor en pildora gris. -->
<div class="modal-fondo" id="modal-dominio-detalles">
    <div class="modal-caja" role="dialog" aria-modal="true"
         aria-labelledby="modal-dominio-detalles-titulo">

        <header class="modal-cabecera">
            <h2 class="modal-titulo" id="modal-dominio-detalles-titulo">
                <i class="fa-solid fa-circle-info"></i> Detalles de Dominio
            </h2>
            <button type="button" class="modal-cerrar" data-modal-cerrar aria-label="Cerrar">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </header>

        <div class="modal-cuerpo">
            <?php foreach ($dominioDetalles as $etiqueta => $valor): ?>
                <div class="dato-fila">
                    <span class="dato-fila-label"><?= htmlspecialchars($etiqueta) ?></span>
                    <span class="dato-fila-valor"><?= htmlspecialchars((string) $valor) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Modal "Cambiar de Dominio": mismo formato que el de panel, pero la lista
     suele ser bastante mas larga (un boton por perfil del usuario). -->
<div class="modal-fondo" id="modal-dominio">
    <div class="modal-caja" role="dialog" aria-modal="true" aria-labelledby="modal-dominio-titulo">

        <header class="modal-cabecera">
            <h2 class="modal-titulo" id="modal-dominio-titulo">
                <i class="fa-solid fa-location-dot"></i> Cambiar de Dominio
            </h2>
            <button type="button" class="modal-cerrar" data-modal-cerrar aria-label="Cerrar">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </header>

        <!-- La lista la trae api/dominios al abrir: son los perfiles del
             usuario, que cambian sin que cambie esta pagina. -->
        <div class="modal-cuerpo">
            <div class="opcion-lista" id="dominio-lista">
                <p class="lista-aviso">Cargando&hellip;</p>
            </div>
        </div>
    </div>
</div>

<div class="modal-fondo" id="modal-usuario">
    <div class="modal-caja" role="dialog" aria-modal="true" aria-labelledby="modal-usuario-titulo">

        <header class="modal-cabecera">
            <h2 class="modal-titulo" id="modal-usuario-titulo">
                <i class="fa-solid fa-user"></i> Mi Usuario
            </h2>
            <button type="button" class="modal-cerrar" data-modal-cerrar aria-label="Cerrar">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </header>

        <div class="modal-cuerpo">

            <dl class="dato-lista">
                <div class="dato">
                    <dt>Nombre</dt>
                    <dd><?= htmlspecialchars((string) ($usuario['nombre'] ?: '—')) ?></dd>
                </div>
                <div class="dato">
                    <dt>Correo</dt>
                    <dd><?= htmlspecialchars((string) ($usuario['correo'] ?: '—')) ?></dd>
                </div>
                <div class="dato">
                    <dt>Celular</dt>
                    <dd><?= htmlspecialchars((string) ($usuario['celular'] ?: '—')) ?></dd>
                </div>
            </dl>

            <!-- La contraseña actual se precarga (enmascarada) desde
                 api/contrasena.php al abrir el modal: no se pide la anterior,
                 el ojo la revela. -->
            <form class="form-contrasena" id="form-contrasena" novalidate>
                <h3 class="modal-subtitulo">Cambiar contrase&ntilde;a</h3>

                <div class="campo">
                    <label for="pass-nueva">Contrase&ntilde;a</label>
                    <div class="campo-input">
                        <input type="password" id="pass-nueva" name="nueva"
                               autocomplete="off" minlength="6" maxlength="36"
                               placeholder="Cargando&hellip;" disabled required>
                        <button type="button" class="campo-ojo" id="pass-ojo"
                                aria-label="Mostrar contrase&ntilde;a" aria-pressed="false">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-aviso" id="pass-aviso" role="status"></div>

                <button type="submit" class="modal-btn" id="pass-submit" disabled>
                    <i class="fa-solid fa-key"></i> Guardar contrase&ntilde;a
                </button>
            </form>

        </div>
    </div>
</div>

<!-- Modal "Entorno": diagnostico de la sesion, pensado para pasarle datos a la
     mesa de ayuda. La parte del servidor se imprime aca; la del navegador
     (cookies, storage, pantalla) la arma app.js recien al abrir el modal. -->
<div class="modal-fondo" id="modal-entorno">
    <div class="modal-caja" role="dialog" aria-modal="true"
         aria-labelledby="modal-entorno-titulo">

        <header class="modal-cabecera">
            <h2 class="modal-titulo" id="modal-entorno-titulo">
                <i class="fa-solid fa-layer-group"></i> Entorno
            </h2>
            <button type="button" class="modal-cerrar" data-modal-cerrar aria-label="Cerrar">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </header>

        <div class="modal-cuerpo">

            <section class="entorno-seccion">
                <h3 class="modal-subtitulo">
                    <i class="fa-solid fa-server"></i> Sesi&oacute;n (servidor)
                </h3>
                <dl class="entorno-lista">
                    <?php foreach ($entornoServidor as $clave => $valor): ?>
                        <div class="entorno-fila">
                            <dt><?= htmlspecialchars((string) $clave) ?></dt>
                            <dd><?= htmlspecialchars($valor !== '' ? (string) $valor : '—') ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
                <p class="entorno-nota">
                    Los tokens de sesi&oacute;n son <code>HttpOnly</code>: no se muestran
                    ac&aacute; ni son visibles para el JavaScript de la p&aacute;gina.
                </p>
            </section>

            <!-- Lo llena cargarEntorno() en app.js -->
            <div id="entorno-cliente"></div>

        </div>

        <!-- Fuera del cuerpo: el scroll vive en `.modal-cuerpo`, asi que el
             boton queda anclado abajo y siempre a la vista. -->
        <footer class="modal-pie">
            <button type="button" class="modal-btn" id="entorno-copiar">
                <i class="fa-regular fa-copy"></i> Copiar todo
            </button>
        </footer>
    </div>
</div>

<div class="toast" id="toast" role="status"></div>

<script src="assets/js/app.js?v=<?= $cb ?>"></script>
</body>
</html>
