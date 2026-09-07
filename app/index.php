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
require_once __DIR__ . '/lib/analytics.php';

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

// SIN PERFIL HABILITADO NO HAY DOMINIO. `appContextoSesion()` devuelve todo en
// cero cuando el usuario no tiene ni un `perfiles` habilitado (ver el `$vacio`
// de `appDominioActivo()`), asi que `perfil = 0` es la senal de "esta cuenta no
// esta parada en ningun dominio".
//
// Con eso, los dos agrupadores que cuelgan del dominio no se dibujan:
//
//   - "Mi Dominio" (Detalles / Actividad / Invitar un Usuario) porque no hay
//     dominio del que mostrar nada: Detalles saldria con nombre "—" y los tres
//     contadores en 0, y Actividad pediria el historial de `dominio = 0`.
//   - "Ajustes" porque sus dos items tampoco aplican: las notificaciones son
//     del dominio, y la Mesa de Ayuda sigue estando en la topbar.
//
// Queda a la vista "Mi Cuenta" (para ver el usuario y salir) e "Instalar": son
// lo unico que no depende del dominio, y sin ellos la pantalla seria un callejon
// sin salida.
$sinPerfil = $contexto['perfil'] <= 0;

// PERMISOS DEL PERFIL (`perfiles`.`operacion` / `.invitacion`, migracion
// 20260907_1000). De la app gatean dos cosas, y son las dos que se piden aca:
//
//   - `operacion` -> LOS PANELES DE OPERACION: la franja con el nombre del
//     panel, los controles con sus botones y el boton "Cambiar de Panel" de la
//     topbar. Sin el permiso la app se abre igual —"Mi Cuenta", "Mi Dominio",
//     "Ajustes" e "Instalar" siguen ahi— pero no se dibuja ni un control.
//
//     SE GATEA LA OPERACION, NO LA ENTRADA. Cerrar la app entera dejaria a la
//     persona sin poder ver su cuenta ni cerrar sesion, que es un callejon sin
//     salida; y el pedido nombra explicitamente "ver los paneles de operacion"
//     como lo que abre el permiso.
//
//   - `invitacion` -> el item "Invitar un Usuario" de "Mi Dominio".
//
// Los endpoints los revalidan por su cuenta (api/paneles.php, api/canales.php,
// api/boton.php): esconder un boton no es un control de acceso.
$puedeOperar   = appPuede($contexto, 'operacion');
$puedeInvitar  = appPuede($contexto, 'invitacion');

// Detalles del dominio activo para el modal del mismo nombre. `Mi Perfil` es el
// rol del usuario EN ESTE dominio (Administrador / Operador / ...), que sale
// del perfil activo; los tres contadores son columnas denormalizadas de
// `dominios`. El legacy (`dominio/detalles.php`) mostraba solo nombre,
// usuarios y dispositivos: perfil, chips y administradores se suman aca.
//
// "Mi Perfil" y no "Perfil" porque abajo esta "Administradores", que son los
// perfiles DE LOS DEMAS: sin el posesivo las dos filas parecerian hablar de lo
// mismo.
//
// ADMINISTRADORES ES UNA LISTA, no un contador: es a quien pedirle algo que uno
// no puede hacer (permisos, invitaciones, facturacion). Van solo los nombres
// —sin correo ni celular— porque esta pantalla la ve cualquier operador del
// dominio y publicar el contacto de todos no hace falta para saber a quien
// buscar.
$contadores      = appDominioContadores($contexto['dominio']);
$administradores = appDominioAdministradores($contexto['dominio']);

$dominioDetalles = [
    'Nombre'           => $dominioNombre,
    'Mi Perfil'        => $contexto['rol'] !== '' ? $contexto['rol'] : '—',
    'Usuarios'         => (string) $contadores['usuarios'],
    'Dispositivos'     => (string) $contadores['dispositivos'],
    'Chips'            => (string) $contadores['chips'],
    // Sin ninguno queda el mismo guion que usan el resto de los campos vacios,
    // no una pildora en blanco.
    'Administradores'  => $administradores !== [] ? implode(', ', $administradores) : '—',
];

// Situacion del dominio, tal cual la usa el legacy en `panel/index.php`:
//
//   '1' normal      -> sin advertencia, controles a la vista
//   '2' limitado    -> advertencia de "pronto sera suspendida", PERO los
//                      controles se siguen mostrando (el servicio anda)
//   '3' suspendido  -> advertencia de "ha sido suspendida" y NO se dibuja
//                      ningun control
//
// Las etiquetas salen de `reactor-panel/inicio/widget011.php` ("Servicio
// normal / limitado / suspendido"). El legacy compara con `!= '3'` para
// decidir si dibuja los controles, o sea que cualquier valor raro (vacio,
// NULL, un 4) se comporta como normal. Se replica ese criterio: ante un dato
// inesperado conviene dejar al usuario operar, no bloquearlo.
$dominioSituacion = $contexto['situacion'];
$servicioSuspendido = ($dominioSituacion === '3');

// Mesa de ayuda: WhatsApp de soporte. Lo usan las dos entradas (topbar y Ajustes).
$soporteUrl = 'https://api.whatsapp.com/send/?phone=5491163099315&text=Hola+Reactor&type=phone_number&app_absent=0';

// "Entorno" es una herramienta de diagnostico, no una pantalla de usuario: solo
// la ve esta cuenta. Es un id concreto y no un rol a proposito — no existe hoy
// ningun permiso que represente "puede ver el diagnostico", y colgarlo del rol
// Administrador se lo abriria a 469 perfiles.
const APP_USUARIO_DIAGNOSTICO = 3;

$verEntorno = ((int) ($usuario['id'] ?? 0)) === APP_USUARIO_DIAGNOSTICO;

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
    'Habilitado'                  => esHabilitado($usuario['habilitado'] ?? 0) ? 'Si' : 'No',
    'Perfil (sesionPerfil)'       => (string) $contexto['perfil'],
    'Dominio (sesionDominio)'     => (string) $contexto['dominio'],
    'Nombre del dominio'          => $dominioNombre,
    'Rol en el dominio'           => $contexto['rol'] !== '' ? $contexto['rol'] : '—',
    'Panel (sesionPanel)'         => (string) $contexto['panel'],
    'Nombre del panel'            => $panelNombre !== '' ? $panelNombre : '—',
    // Los tres permisos del perfil. Van al diagnostico porque son lo primero que
    // hay que mirar cuando alguien reporta "no me aparecen los controles" o "no
    // me sale el boton de invitar": las dos pantallas se esconden en silencio, y
    // sin esto la unica forma de saber por que es entrar a la base.
    'Permiso operación'           => $puedeOperar  ? 'Si' : 'No',
    'Permiso invitación'          => $puedeInvitar ? 'Si' : 'No',
    'Permiso facturación'         => appPuede($contexto, 'facturacion') ? 'Si' : 'No',
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
// Sin permiso de operacion no se consulta: la lista no se va a dibujar, y es
// la consulta mas cara de la pantalla (controles + canales + botones del panel).
$controles = $puedeOperar
    ? appControlesDelPanel($contexto['panel'], $contexto['dominio'])
    : [];

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

    <!-- PWA. El manifest va en `/manifest.json`, la misma ruta que usa el
         legacy, y declara `"id": "/"`: asi los celulares que ya tienen la app
         instalada la ACTUALIZAN en vez de que les aparezca una segunda. -->
    <link rel="manifest" href="/manifest.json?v=<?= $cb ?>">

    <!-- iOS no lee el manifest: el modo standalone y la barra de estado se
         piden con estos meta. -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Reactor">
    <meta name="mobile-web-app-capable" content="yes">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <!-- La fuente "LCD" del titulo del display se sirve local
         (assets/fonts/small-lcd-sign.ttf, @font-face en style.css) -->
    <link rel="stylesheet"
          href="assets/css/style.css?v=<?= $cb ?>">

    <!-- Google Analytics (solo si hay propiedad configurada; ver lib/analytics.php) -->
    <?php appAnalytics(); ?>
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
            <?php // "Cambiar de Panel" no tiene sentido sin permiso de
                  // operacion: no hay panel que abrir. ?>
            <?php if ($puedeOperar): ?>
            <button type="button" class="topbar-action" title="Cambiar de Panel"
                    data-nav="panel" data-modal="modal-panel">
                <i class="fa-solid fa-pager"></i>
            </button>
            <?php endif; ?>
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
                        <?php if ($verEntorno): ?>
                            <a href="#/cuenta/entorno" class="nav-subitem" data-route="cuenta-entorno"
                               data-modal="modal-entorno">
                                <i class="fa-solid fa-layer-group"></i> Entorno
                            </a>
                        <?php endif; ?>
                        <a href="/sesion/cerrar" class="nav-subitem" data-route="cuenta-salir">
                            <i class="fa-solid fa-right-from-bracket"></i> Cerrar Sesi&oacute;n
                        </a>
                    </div>
                </div>
                <?php // Los dos agrupadores del dominio: sin perfil habilitado no se dibujan. ?>
                <?php if (!$sinPerfil): ?>
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
                        <?php // Item gateado por el permiso `invitacion`. ?>
                        <?php if ($puedeInvitar): ?>
                        <a href="#/dominio/invitar" class="nav-subitem" data-route="dominio-invitar"
                           data-modal="modal-invitar">
                            <i class="fa-solid fa-user-plus"></i> Invitar un Usuario
                        </a>
                        <?php endif; ?>
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
                <?php endif; ?>
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
                <?php // El nombre del panel abierto solo se muestra si hay panel
                      // que abrir: sin permiso de operacion no lo hay. ?>
                <?php if ($puedeOperar && $panelNombre !== ''): ?>
                    <br>
                    <span class="panel-nombre"><?= htmlspecialchars($panelNombre) ?></span>
                <?php endif; ?>
            </div>

            <div class="panel-feed">

                <?php if ($dominioSituacion === '2'): ?>
                    <div class="advertencia" role="alert">
                        <h4>Advertencia</h4>
                        <div>Su cuenta pronto ser&aacute; suspendida por falta de pago.</div>
                    </div>
                <?php elseif ($servicioSuspendido): ?>
                    <div class="advertencia" role="alert">
                        <h4>Advertencia</h4>
                        <div>Su cuenta ha sido suspendida por falta de pago.
                             Comun&iacute;quese con el administrador del dominio.</div>
                    </div>
                <?php endif; ?>

                <?php // SIN PERMISO DE OPERACION no se dibuja ningun control, y
                      // se dice por que: una pantalla vacia no distingue "tu
                      // perfil no puede operar" de "el panel esta vacio" ni de
                      // "se rompio algo", y las tres se arreglan distinto. El
                      // aviso manda a quien administra el dominio, que es quien
                      // puede darle el permiso desde panel.reactor.com.ar. ?>
                <?php if (!$puedeOperar): ?>
                    <p class="lista-aviso">
                        Tu perfil no tiene permiso para operar los paneles de este dominio.
                        Ped&iacute;selo a quien administra <?= htmlspecialchars($dominioNombre) ?>.
                    </p>

                <?php // Con el servicio suspendido el legacy no dibuja ni un control. ?>
                <?php elseif (!$servicioSuspendido): ?>

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

                <?php endif; ?>
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

<!-- Los cuatro modales que cuelgan de "Mi Dominio" y "Ajustes" van dentro del
     mismo `if` que sus items del menu, igual que el de Entorno: sin perfil
     habilitado no hay como abrirlos, y "Detalles de Dominio" ademas imprime
     `$dominioDetalles` en el HTML — servirlo con el dominio en "—" y los
     contadores en 0 seria mandar al navegador una ficha vacia de un dominio que
     el usuario no tiene. -->
<?php if (!$sinPerfil): ?>

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

<!-- Modal "Invitar un Usuario": UN SOLO CAMPO, el correo.
     Es el destino del mensaje, y es lo unico que hace falta: el nombre y el
     celular los completa la persona invitada al aceptar
     (`invitacion/aceptar.php`). Es el mismo modal que el "+ Nuevo usuario" del
     panel y el espejo del alta legacy (`dominio/invitar.php` pedia un solo
     campo tambien, el celular, porque el canal era WhatsApp).

     Va gateado por el permiso `invitacion`, igual que el item del menu que lo
     abre: sin el no hay forma de llegar hasta aca. El corte de verdad igual lo
     hace `api/invitaciones.php`, que revalida contra la base. -->
<?php if ($puedeInvitar): ?>
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
            <p class="modal-lead">
                Le mandamos un correo con un enlace para sumarse a
                <strong><?= htmlspecialchars($dominioNombre, ENT_QUOTES) ?></strong>.
                El resto de los datos los completa al aceptar la invitaci&oacute;n.
            </p>

            <form id="form-invitar" novalidate>
                <div class="campo">
                    <label for="inv-correo">Correo electr&oacute;nico</label>
                    <input type="email" id="inv-correo" name="correo"
                           maxlength="100" inputmode="email"
                           autocomplete="off" autocapitalize="none" spellcheck="false"
                           placeholder="nombre@ejemplo.com" required>
                </div>

                <div class="form-aviso" id="inv-aviso" role="status"></div>

                <button type="submit" class="modal-btn" id="inv-submit" disabled>
                    <i class="fa-solid fa-paper-plane"></i> Enviar invitaci&oacute;n
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

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

<?php endif; ?>

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
     (cookies, storage, pantalla) la arma app.js recien al abrir el modal.
     Va dentro del mismo `if` que el item del menu: si no, el HTML con los
     datos de sesion se serviria igual a todos, aunque no hubiera como abrirlo. -->
<?php if ($verEntorno): ?>
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
<?php endif; ?>

<div class="toast" id="toast" role="status"></div>

<script src="assets/js/app.js?v=<?= $cb ?>"></script>
</body>
</html>
