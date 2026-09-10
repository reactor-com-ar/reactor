<?php

declare(strict_types=1);

/**
 * Paso 2 de la recuperacion: elegir la contrasena nueva y entrar.
 *
 * Es la pagina a la que apunta el enlace del correo (`?t=<token>`). El token ES
 * la credencial: no hay sesion y no se pide la contrasena vieja (justamente, es
 * la que la persona no tiene).
 *
 * DOS CAMPOS, NUEVA Y REPETIR, y no un campo con el ojito que muestra la
 * contrasena como en el modal de Mi Cuenta. La pantalla se abre desde un enlace
 * de correo, asi que puede terminar en una maquina prestada o con alguien
 * mirando; y si la contrasena se tipea mal la persona queda afuera de la cuenta
 * que acaba de recuperar, con el enlace ya consumido. La confirmacion es la
 * defensa barata contra las dos cosas.
 *
 * AL GUARDAR SE ABRE LA SESION Y EL BOTON ENTRA DIRECTO A LA APP, que es la
 * diferencia con la copia del panel (alla el boton manda al login). Se puede
 * porque esta pantalla corre en el MISMO host que la app, asi que
 * `appSesionAbrir()` —el mismo punto que el login por contrasena— escribe la
 * cookie sin intermediarios; el panel tiene que emitir un enlace de un solo uso
 * en `enlaces_acceso` justamente porque no puede.
 *
 * Y SE PUEDE SIN ABRIR NINGUN AGUJERO, a diferencia de la aceptacion de una
 * invitacion —que abre sesion SOLO para la cuenta recien creada, ver
 * `sesionAbiertaSiCorresponde()` en invitacion/aceptar.php—. Alla la credencial
 * es el `uuid` del enlace y ese uuid LO VE EL EMISOR en su propio listado, asi
 * que abrir sesion sobre una cuenta ajena seria un secuestro servido. Aca la
 * credencial son 32 bytes de CSPRNG que salieron por correo A LA PROPIA CASILLA
 * de la cuenta y de los que en la base queda solo el SHA-256: nadie del sistema
 * puede verlos. Y quien lo tiene acaba de elegir la contrasena, con lo cual
 * podria loguearse igual — la sesion no le da nada que no tenga ya.
 *
 * El token viaja de nuevo en un hidden del POST: la URL con el `?t=` la puede
 * perder el navegador al postear y ademas asi el POST se valida por el mismo
 * camino que el GET.
 */

require __DIR__ . '/_layout.php';
// `appSesionAbrir()` / `appUsuarioVigente()`: el mismo punto de entrada que el
// login por contrasena y que el canje de un enlace magico.
require_once dirname(__DIR__) . '/lib/auth.php';

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$token  = (string) ($_POST['t'] ?? $_GET['t'] ?? '');

$rec = recuperacionPorToken($token);
if ($rec === null) {
    recuperarCorte(
        'Enlace no válido',
        'El enlace no corresponde a ningún pedido de recuperación. Verificá que lo hayas copiado completo.'
    );
}

$motivo = recuperacionMotivoNoVigente($rec);
if ($motivo !== null) {
    recuperarCorte('Enlace no disponible', $motivo);
}

$cuenta = recuperacionCuentaVisible($rec);
$error  = '';

if ($metodo === 'POST') {
    $nueva    = (string) ($_POST['contrasena'] ?? '');
    $repetida = (string) ($_POST['repetir']    ?? '');

    $error = recuperacionValidarContrasena($nueva, $repetida);

    if ($error === '') {
        if (!recuperacionConsumir($rec, $nueva)) {
            // Perdio la carrera contra otro envio del mismo enlace (dos
            // pestanas, un reintento del navegador) o vencio entre el GET y el
            // POST.
            recuperarCorte(
                'Enlace no disponible',
                'Este enlace ya se usó o venció mientras completabas el formulario.'
            );
        }

        recuperarLayout('Contraseña actualizada', pantallaListo($cuenta, sesionAbierta((int) $rec['usuario'])));
    }
}

$cuerpo = '
    <p class="inv-lead">
        Elegí la contraseña nueva de tu cuenta.
    </p>

    <div class="inv-datos">' . recuperarDato('Ingresás con', $cuenta) . '</div>

    <form method="post" class="sesion-form" novalidate>
        <input type="hidden" name="t" value="' . e($token) . '">

        <div class="sesion-campo">
            <input type="password" name="contrasena" class="sesion-input"
                   placeholder="Contraseña nueva"
                   minlength="' . RECUPERACION_CONTRASENA_MIN . '"
                   maxlength="' . RECUPERACION_CONTRASENA_MAX . '"
                   autocomplete="new-password" autofocus required>
            <i class="fa-solid fa-lock sesion-input-icono"></i>
        </div>

        <div class="sesion-campo">
            <input type="password" name="repetir" class="sesion-input"
                   placeholder="Repetir contraseña"
                   minlength="' . RECUPERACION_CONTRASENA_MIN . '"
                   maxlength="' . RECUPERACION_CONTRASENA_MAX . '"
                   autocomplete="new-password" required>
            <i class="fa-solid fa-lock sesion-input-icono"></i>
        </div>

        <button type="submit" class="sesion-btn">
            <i class="fa-solid fa-check"></i> Guardar contraseña
        </button>
    </form>

    <p class="inv-lead" style="margin-top:14px">
        Entre ' . RECUPERACION_CONTRASENA_MIN . ' y ' . RECUPERACION_CONTRASENA_MAX . ' caracteres.
    </p>
';

recuperarLayout('Nueva contraseña', $cuerpo, $error);

/* ------------------------------------------------------------------ */
/* Logica                                                              */
/* ------------------------------------------------------------------ */

/**
 * Abre la sesion de la app para la cuenta que acaba de recuperar. Devuelve
 * `true` si quedo abierta.
 *
 * Va DESPUES del commit de `recuperacionConsumir()` y no adentro: `setcookie()`
 * no se revierte con un rollback, y una cookie de una sesion que no llego a
 * existir es peor que ninguna.
 *
 * `appUsuarioVigente()` revalida `habilitado` contra la base — ya lo mira
 * `recuperacionMotivoNoVigente()` al abrir la pantalla, pero entre eso y el POST
 * pueden pasar minutos. Si no valida, la pantalla cae sola al boton que manda al
 * login: la contrasena ya quedo guardada igual.
 */
function sesionAbierta(int $usuarioId): bool
{
    $usuario = appUsuarioVigente($usuarioId);
    if ($usuario === null) {
        return false;
    }

    // El login a medio hacer que pudiera haber quedado abierto en ESTE
    // navegador ya no sirve para nada: la sesion esta abierta. Es el mismo
    // orden que usa sesion/contrasena.php.
    appLoginPendienteCerrar();

    // El mismo punto que el login por contrasena: resuelve el ALCANCE de la
    // sesion (`per` / `dom` / `pan`) y marca `ingresado`. Firmar la cookie a
    // mano dejaria una sesion sin alcance, distinguible de una normal.
    appSesionAbrir($usuario);

    return true;
}

/* ------------------------------------------------------------------ */
/* Pantallas                                                           */
/* ------------------------------------------------------------------ */

function pantallaListo(string $cuenta, bool $sesion): string
{
    // CON LA SESION YA ABIERTA, `Ingresar` va a la app y no al login: mandar al
    // formulario a alguien que ya tiene la cookie es pedirle la contrasena que
    // acaba de elegir. Sin sesion (la cuenta se deshabilito en el medio) va al
    // login, que es donde el rebote le va a decir que pasa.
    $destino = $sesion ? '/' : '/sesion/iniciar';

    $aviso = $sesion
        ? '<p class="inv-lead">Ya podés entrar a la app. La próxima vez ingresá con esta contraseña.</p>'
        : '<p class="inv-lead">Ya podés ingresar a la app con la contraseña nueva.</p>';

    return '<div class="inv-nota inv-nota-ok">Tu contraseña quedó actualizada.</div>'
         . '<div class="inv-datos">' . recuperarDato('Ingresás con', $cuenta) . '</div>'
         . $aviso
         . '<div class="inv-acciones inv-acciones-sola">'
         . '<a class="sesion-btn" href="' . e(appBaseUrl() . $destino) . '">'
         . '<i class="fa-solid fa-right-to-bracket"></i> Ingresar'
         . '</a></div>';
}
