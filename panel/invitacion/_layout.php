<?php

declare(strict_types=1);

/**
 * Shell de las paginas publicas de invitacion (index / aceptar / rechazar).
 *
 * El layout en si vive en lib/publico.php desde que `recuperar/` lo necesito
 * igual: son las mismas paginas sin sesion sobre la misma tarjeta roja del
 * login. Aca quedan solo los alias con el nombre historico, para no tocar
 * las tres pantallas que ya los usan, y el require de lib/invitaciones.php,
 * que es lo unico propio de este circuito.
 */

require_once dirname(__DIR__) . '/lib/publico.php';
require_once dirname(__DIR__) . '/lib/invitaciones.php';

/** @see publicoCacheBust() */
function invitacionCacheBust(): string
{
    return publicoCacheBust();
}

/** @see publicoFechaLarga() */
function invitacionFechaLarga(?string $valor): string
{
    return publicoFechaLarga($valor);
}

/** @see publicoDato() */
function invitacionDato(string $label, string $valor): string
{
    return publicoDato($label, $valor);
}

/** @see publicoLayout() */
function invitacionLayout(string $titulo, string $cuerpo): void
{
    publicoLayout($titulo, $cuerpo);
}

/** @see publicoCorte() */
function invitacionCorte(string $titulo, string $mensaje, string $tono = 'bad'): void
{
    publicoCorte($titulo, $mensaje, $tono);
}
