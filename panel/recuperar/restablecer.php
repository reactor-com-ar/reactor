<?php

declare(strict_types=1);

/**
 * Paso 2 de la recuperacion: elegir la contrasena nueva.
 *
 * Es la pagina a la que apunta el enlace del correo (`?t=<token>`). El
 * token ES la credencial: no hay sesion y no se pide la contrasena vieja
 * (justamente, es la que la persona no tiene).
 *
 * DOS CAMPOS, NUEVA Y REPETIR, y no un campo con el ojito que muestra la
 * contrasena como en app/. La pantalla se abre desde un enlace de correo,
 * asi que puede terminar en una maquina prestada o con alguien mirando; y
 * si la contrasena se tipea mal la persona queda afuera de la cuenta que
 * acaba de recuperar, con el enlace ya consumido. La confirmacion es la
 * defensa barata contra las dos cosas.
 *
 * El token viaja de nuevo en un hidden del POST: la URL con el `?t=` la
 * puede perder el navegador al postear y ademas asi el POST se valida por
 * el mismo camino que el GET.
 */

require_once dirname(__DIR__) . '/lib/publico.php';
require_once dirname(__DIR__) . '/lib/recuperacion.php';

/** Boton para volver a pedir un enlace, que acompana a todos los cortes. */
const RECUPERAR_DE_NUEVO = '<div class="inv-acciones" style="margin-top:4px">'
    . '<a class="btn btn-primary" href="./">'
    . '<i class="fa-solid fa-rotate-right"></i> Pedir un enlace nuevo'
    . '</a></div>';

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$token  = (string) ($_POST['t'] ?? $_GET['t'] ?? '');

$rec = recuperacionPorToken($token);
if ($rec === null) {
    publicoCorte(
        'Enlace no válido',
        'El enlace no corresponde a ningún pedido de recuperación. Verificá que lo hayas copiado completo.',
        'bad',
        RECUPERAR_DE_NUEVO
    );
}

$motivo = recuperacionMotivoNoVigente($rec);
if ($motivo !== null) {
    publicoCorte('Enlace no disponible', $motivo, 'bad', RECUPERAR_DE_NUEVO);
}

$cuenta = (string) ($rec['cuenta'] ?? '');
$error  = '';

if ($metodo === 'POST') {
    $nueva    = (string) ($_POST['contrasena'] ?? '');
    $repetida = (string) ($_POST['repetir']    ?? '');

    $error = recuperacionValidarContrasena($nueva, $repetida);

    if ($error === '') {
        if (!recuperacionConsumir($rec, $nueva)) {
            // Perdio la carrera contra otro envio del mismo enlace (dos
            // pestanas, un reintento del navegador) o vencio entre el GET
            // y el POST.
            publicoCorte(
                'Enlace no disponible',
                'Este enlace ya se usó o venció mientras completabas el formulario.',
                'bad',
                RECUPERAR_DE_NUEVO
            );
        }
        publicoLayout('Contraseña actualizada', pantallaListo($cuenta));
    }
}

$cuerpo = '
    <p class="inv-lead">
        Elegí la contraseña nueva para tu cuenta.
    </p>

    <div class="inv-datos">' . publicoDato('Usuario', $cuenta) . '</div>

    <form method="post" class="login-form" novalidate>
        <input type="hidden" name="t" value="' . e($token) . '">

        <div class="form-group">
            <label for="rec-contrasena">Contraseña nueva</label>
            <input type="password" id="rec-contrasena" name="contrasena"
                   minlength="' . RECUPERACION_CONTRASENA_MIN . '"
                   maxlength="' . RECUPERACION_CONTRASENA_MAX . '"
                   autocomplete="new-password" autofocus required>
        </div>

        <div class="form-group">
            <label for="rec-repetir">Repetir contraseña</label>
            <input type="password" id="rec-repetir" name="repetir"
                   minlength="' . RECUPERACION_CONTRASENA_MIN . '"
                   maxlength="' . RECUPERACION_CONTRASENA_MAX . '"
                   autocomplete="new-password" required>
        </div>

        ' . ($error !== '' ? '<div class="inv-note inv-note-bad">' . e($error) . '</div>' : '') . '

        <p class="inv-lead" style="text-align:left">
            Entre ' . RECUPERACION_CONTRASENA_MIN . ' y ' . RECUPERACION_CONTRASENA_MAX . ' caracteres.
        </p>

        <button type="submit" class="btn btn-primary login-submit">
            <i class="fa-solid fa-check"></i> Guardar contraseña
        </button>
    </form>
';

publicoLayout('Nueva contraseña', $cuerpo);

/* ------------------------------------------------------------------ */
/* Pantallas                                                           */
/* ------------------------------------------------------------------ */

function pantallaListo(string $cuenta): string
{
    return '<div class="inv-note inv-note-ok">Tu contraseña quedó actualizada.</div>'
         . '<div class="inv-datos">' . publicoDato('Usuario', $cuenta) . '</div>'
         . '<p class="inv-lead">Ya podés ingresar al panel con la contraseña nueva.</p>'
         . '<div class="inv-acciones">'
         . '<a class="btn btn-primary" href="../login">'
         . '<i class="fa-solid fa-right-to-bracket"></i> Ingresar'
         . '</a></div>';
}
