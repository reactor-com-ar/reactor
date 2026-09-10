<?php

declare(strict_types=1);

/**
 * Paso 1 de la recuperacion: pedir el enlace.
 *
 * Es la pantalla a la que lleva el boton "Recuperar contraseña" de
 * `sesion/contrasena.php`. La persona escribe su celular o su correo —lo mismo
 * que escribe para entrar, ver `recuperacionUsuarioPorIdentificador()`— y, si
 * eso corresponde a una cuenta habilitada con correo cargado, sale un mail con
 * un enlace de un solo uso.
 *
 * SE PRECARGA LO QUE YA HABIA TIPEADO EN EL PASO 1 DEL LOGIN. Ese dato vive en
 * la cookie firmada del login pendiente (`appLoginPendiente()['ing']`), asi que
 * no hace falta pasarlo por la URL: quien llega desde la pantalla de contrasena
 * encuentra el campo lleno y le alcanza con tocar "Enviar enlace". Tampoco se
 * pasa por querystring a proposito — seria publicar el celular o el correo de la
 * persona en la barra del navegador y en el historial.
 *
 * LA RESPUESTA ES SIEMPRE LA MISMA (no se dice si la cuenta existe): el
 * formulario es publico, asi que distinguir "no existe" de "te mandamos el mail"
 * lo convertiria en un verificador de los celulares y correos del sistema. Por
 * eso los cuatro caminos que no mandan nada —cuenta inexistente, deshabilitada,
 * sin correo cargado y cupo agotado— terminan en la misma pantalla que el envio
 * exitoso.
 *
 * LA UNICA EXCEPCION es que falle el microservicio de correo: ahi si se muestra
 * el error. Que el envio se caiga es un problema de infraestructura y callarlo
 * deja a la persona esperando un mail que nunca va a llegar; la pista que da
 * sobre la existencia de la cuenta solo aparece durante una caida del servicio y
 * no vale ese precio.
 */

require __DIR__ . '/_layout.php';

$metodo    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pendiente = appLoginPendiente();

// El campo arranca con lo que se tipeo en el login; en el POST manda lo que se
// haya dejado escrito aca.
$identificador = $metodo === 'POST'
    ? trim((string) ($_POST['identificador'] ?? ''))
    : trim((string) ($pendiente['ing'] ?? ''));

// Con un login a medio hacer se vuelve a la pantalla de contrasena, que es de
// donde se llego; si ya vencio, al principio. `sesion/contrasena.php` redirige
// solo a `iniciar` cuando no hay pendiente, asi que el enlace nunca queda roto.
$volver = $pendiente !== null ? '/sesion/contrasena' : '/sesion/iniciar';
$error  = '';

if ($metodo === 'POST') {
    if ($identificador === '') {
        $error = 'Escribí tu celular o el correo de tu cuenta.';
    } else {
        $usuario = recuperacionUsuarioPorIdentificador($identificador);

        // Se emite solo si hay a quien y a donde mandarlo. Cualquiera de estas
        // cuatro condiciones que falle cae igual en la pantalla de confirmacion
        // de abajo.
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
            recuperarLayout('Revisá tu correo', pantallaEnviado($identificador));
        }
    }
}

$cuerpo = '
    <p class="inv-lead">
        Escribí tu celular o el correo de tu cuenta y te enviamos un enlace para
        elegir una contraseña nueva.
    </p>

    <form method="post" class="sesion-form" novalidate>
        <div class="sesion-campo">
            <input type="text" name="identificador" class="sesion-input"
                   placeholder="Tu celular o correo" maxlength="100"
                   value="' . e($identificador) . '"
                   autocomplete="username" autocapitalize="off" autocorrect="off"
                   spellcheck="false" autofocus>
            <i class="fa-solid fa-user sesion-input-icono"></i>
        </div>

        <div class="sesion-fila">
            <a class="sesion-btn sesion-btn-secundario" href="' . e($volver) . '">
                <i class="fa-solid fa-chevron-left"></i> Volver
            </a>
            <button type="submit" class="sesion-btn">
                Enviar enlace <i class="fa-solid fa-paper-plane"></i>
            </button>
        </div>
    </form>
';

recuperarLayout('Recuperar contraseña', $cuerpo, $error);

/* ------------------------------------------------------------------ */
/* Pantallas                                                           */
/* ------------------------------------------------------------------ */

/**
 * Confirmacion neutra. Repite lo que se tipeo para que se note el error de
 * tipeo —que es la causa mas probable de que el mail no llegue— sin confirmar
 * que exista una cuenta con ese valor.
 */
function pantallaEnviado(string $identificador): string
{
    return '<div class="inv-nota inv-nota-ok">'
         . 'Si <strong>' . e($identificador) . '</strong> corresponde a una cuenta de Reactor, '
         . 'te enviamos un correo con el enlace para restablecer la contraseña.'
         . '</div>'
         . '<p class="inv-lead">'
         . 'El enlace vence en ' . RECUPERACION_TTL_MINUTOS . ' minutos y se usa una sola vez. '
         . 'Si no lo ves, revisá la carpeta de correo no deseado.'
         . '</p>'
         . '<div class="inv-acciones">'
         . '<a class="sesion-btn sesion-btn-secundario" href="/recuperar/">'
         . '<i class="fa-solid fa-rotate-left"></i> Reintentar</a>'
         . '<a class="sesion-btn" href="/sesion/iniciar">'
         . '<i class="fa-solid fa-right-to-bracket"></i> Ingresar</a>'
         . '</div>';
}
