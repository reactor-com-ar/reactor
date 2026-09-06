<?php

declare(strict_types=1);

/**
 * Paso 1 de la recuperacion: pedir el enlace.
 *
 * La persona escribe su usuario o su correo y, si eso corresponde a una
 * cuenta habilitada con correo cargado, sale un mail con un enlace de un
 * solo uso. Es la pantalla a la que lleva "¿Olvidaste tu contraseña?" del
 * login.
 *
 * LA RESPUESTA ES SIEMPRE LA MISMA (no se dice si la cuenta existe): el
 * panel es un BackOffice y el formulario es publico, asi que distinguir
 * "no existe" de "te mandamos el mail" lo convertiria en un verificador de
 * usuarios y correos del sistema. Por eso los cuatro caminos que no mandan
 * nada -- cuenta inexistente, deshabilitada, sin correo cargado y cupo
 * agotado -- terminan en la misma pantalla que el envio exitoso.
 *
 * LA UNICA EXCEPCION es que falle el microservicio de correo: ahi si se
 * muestra el error. Que el envio se caiga es un problema de infraestructura
 * y callarlo deja a la persona esperando un mail que nunca va a llegar; la
 * pista que da sobre la existencia de la cuenta solo aparece durante una
 * caida del servicio y no vale ese precio.
 */

require_once dirname(__DIR__) . '/lib/publico.php';
require_once dirname(__DIR__) . '/lib/recuperacion.php';

$metodo         = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$identificador  = trim((string) ($_POST['identificador'] ?? ''));
$error          = '';

if ($metodo === 'POST') {
    if ($identificador === '') {
        $error = 'Escribí tu usuario o el correo de tu cuenta.';
    } else {
        $usuario = recuperacionUsuarioPorIdentificador($identificador);

        // Se emite solo si hay a quien y a donde mandarlo. Cualquiera de
        // estas tres condiciones que falle cae igual en la pantalla de
        // confirmacion de abajo.
        if ($usuario !== null
            && recuperacionCuentaActiva($usuario)
            && trim((string) ($usuario['correo'] ?? '')) !== ''
            && recuperacionCupo((int) $usuario['id'], recuperacionOrigen())
        ) {
            if (!recuperacionEmitir($usuario)['ok']) {
                $error = 'No pudimos enviar el correo en este momento. Intentá de nuevo en unos minutos.';
            }
        }

        if ($error === '') {
            publicoLayout('Revisá tu correo', pantallaEnviado($identificador));
        }
    }
}

$cuerpo = '
    <p class="inv-lead">
        Escribí tu usuario o el correo de tu cuenta y te enviamos un enlace para
        elegir una contraseña nueva.
    </p>

    <form method="post" class="login-form" novalidate>
        <div class="form-group">
            <label for="rec-identificador">Usuario o correo</label>
            <input type="text" id="rec-identificador" name="identificador" maxlength="100"
                   value="' . e($identificador) . '"
                   autocomplete="username" autocapitalize="off" autocorrect="off"
                   spellcheck="false" autofocus required>
        </div>

        ' . ($error !== '' ? '<div class="inv-note inv-note-bad">' . e($error) . '</div>' : '') . '

        <div class="inv-acciones">
            <a class="btn btn-alt" href="../login">
                <i class="fa-solid fa-chevron-left"></i> Volver
            </a>
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-paper-plane"></i> Enviar enlace
            </button>
        </div>
    </form>
';

publicoLayout('Recuperar contraseña', $cuerpo);

/* ------------------------------------------------------------------ */
/* Pantallas                                                           */
/* ------------------------------------------------------------------ */

/**
 * Confirmacion neutra. Repite lo que se tipeo para que se note el error de
 * tipeo -- que es la causa mas probable de que el mail no llegue-- sin
 * confirmar que exista una cuenta con ese valor.
 */
function pantallaEnviado(string $identificador): string
{
    return '<div class="inv-note inv-note-ok">'
         . 'Si <strong>' . e($identificador) . '</strong> corresponde a una cuenta de Reactor Panel, '
         . 'te enviamos un correo con el enlace para restablecer la contraseña.'
         . '</div>'
         . '<p class="inv-lead">'
         . 'El enlace vence en ' . RECUPERACION_TTL_MINUTOS . ' minutos y se usa una sola vez. '
         . 'Si no lo ves, revisá la carpeta de correo no deseado.'
         . '</p>'
         . '<div class="inv-acciones">'
         . '<a class="btn btn-alt" href="./"><i class="fa-solid fa-rotate-left"></i> Reintentar</a>'
         . '<a class="btn btn-primary" href="../login"><i class="fa-solid fa-right-to-bracket"></i> Ingresar</a>'
         . '</div>';
}
