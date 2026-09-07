<?php

declare(strict_types=1);

/**
 * Shell de las paginas publicas de invitacion (index / aceptar / rechazar).
 *
 * SON LAS UNICAS PANTALLAS DE LA APP QUE SE SIRVEN SIN SESION junto con
 * `sesion/` y `acceso.php`: el destinatario todavia no tiene cuenta, asi que la
 * credencial es el uuid del enlace y nada mas.
 *
 * La tarjeta es la MISMA del login (`sesion/_layout.php` -> `sesionPantalla()`),
 * por el mismo motivo por el que el panel reusa la suya en `lib/publico.php`:
 * quien llega desde un correo tiene que reconocer al toque donde esta parado, y
 * la pantalla que va a ver despues —el ingreso— es exactamente esta.
 *
 * `sesionPantalla()` usa rutas ABSOLUTAS para los assets (`/assets/...`), asi que
 * sirve desde cualquier profundidad. No hace falta el prefijo `../` que si
 * necesita el shell del panel.
 */

require_once dirname(__DIR__) . '/lib/invitaciones.php';
require_once dirname(__DIR__) . '/sesion/_layout.php';

// Sin indexar: son URLs de un solo uso, no contenido publico.
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * 'YYYY-MM-DD HH:MM:SS' -> 'DD/MM/YYYY HH:MM'. Los centinelas del sistema
 * viejo (1500-01-01 y 0000-00-00) salen como raya.
 */
function invitacionFechaLarga(?string $valor): string
{
    $s = trim((string) $valor);
    if ($s === '' || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/', $s, $m)) {
        return '—';
    }
    if ((int) $m[1] < 1900) {
        return '—';
    }
    return isset($m[4])
        ? sprintf('%s/%s/%s %s:%s', $m[3], $m[2], $m[1], $m[4], $m[5])
        : sprintf('%s/%s/%s', $m[3], $m[2], $m[1]);
}

/** Fila etiqueta / valor de las fichas de la tarjeta. */
function invitacionDato(string $label, string $valor): string
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
 *                       la misma caja que usa el login para "usuario o
 *                       contraseña incorrectos".
 */
function invitacionLayout(string $titulo, string $cuerpo, string $error = ''): never
{
    sesionPantalla($titulo, $cuerpo, $error, $titulo);
    exit;
}

/**
 * Pantalla de corte: un aviso y nada mas. La usan las tres paginas cuando el
 * enlace no sirve (uuid inexistente o invitacion ya resuelta), para que el
 * visitante siempre vea el mismo formato.
 */
function invitacionCorte(string $titulo, string $mensaje, string $tono = 'bad'): never
{
    invitacionLayout(
        $titulo,
        '<div class="inv-nota inv-nota-' . e($tono) . '">' . e($mensaje) . '</div>'
    );
}
