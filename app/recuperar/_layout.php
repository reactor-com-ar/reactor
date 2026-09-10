<?php

declare(strict_types=1);

/**
 * Shell de las dos paginas publicas de recuperacion (pedir el enlace /
 * restablecer).
 *
 * SON PANTALLAS SIN SESION, como `invitacion/` y `sesion/`: justamente, quien
 * las abre es alguien que no puede entrar. La credencial de la segunda es el
 * token del enlace y nada mas.
 *
 * La tarjeta es la MISMA del login (`sesion/_layout.php` -> `sesionPantalla()`),
 * por el mismo motivo por el que la reusa `invitacion/_layout.php`: quien llega
 * desde un correo tiene que reconocer al toque donde esta parado, y la pantalla
 * de la que viene —el ingreso— es exactamente esta.
 *
 * `sesionPantalla()` usa rutas ABSOLUTAS para los assets (`/assets/...`), asi que
 * sirve desde cualquier profundidad.
 *
 * Los helpers repiten a proposito los de `invitacion/_layout.php` en vez de
 * incluirlos: ese archivo carga ademas `lib/invitaciones.php` y sella
 * cabeceras propias de ese circuito. Son las mismas cuatro lineas que el panel
 * tiene una sola vez en `lib/publico.php`, que ahi puede porque sus dos
 * familias de paginas publicas comparten shell.
 */

require_once dirname(__DIR__) . '/lib/recuperacion.php';
require_once dirname(__DIR__) . '/sesion/_layout.php';

// Sin indexar: son URLs de un solo uso, no contenido publico.
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Fila etiqueta / valor de las fichas de la tarjeta. */
function recuperarDato(string $label, string $valor): string
{
    return '<div class="inv-dato">'
         . '<span class="inv-dato-label">' . e($label) . '</span>'
         . '<span class="inv-dato-valor">' . e($valor) . '</span>'
         . '</div>';
}

/**
 * Renderiza la pagina completa y termina el request.
 *
 * @param string $titulo Encabezado de la tarjeta (y del `<title>`).
 * @param string $cuerpo HTML ya escapado del interior de la tarjeta.
 * @param string $error  Mensaje de validacion, arriba del cuerpo. Se pinta con
 *                       la misma caja que usa el login para "contraseña
 *                       incorrecta".
 */
function recuperarLayout(string $titulo, string $cuerpo, string $error = ''): never
{
    sesionPantalla($titulo, $cuerpo, $error, $titulo);
    exit;
}

/**
 * Pantalla de corte: un aviso y el boton para pedir otro enlace. La usan las dos
 * paginas cuando el token no sirve (inexistente, vencido o ya usado), para que
 * el visitante siempre vea el mismo formato.
 */
function recuperarCorte(string $titulo, string $mensaje, string $tono = 'bad'): never
{
    recuperarLayout(
        $titulo,
        '<div class="inv-nota inv-nota-' . e($tono) . '">' . e($mensaje) . '</div>'
        . '<div class="inv-acciones inv-acciones-sola">'
        . '<a class="sesion-btn" href="/recuperar/">'
        . '<i class="fa-solid fa-rotate-right"></i> Pedir un enlace nuevo'
        . '</a></div>'
    );
}
