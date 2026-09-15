<?php

declare(strict_types=1);

/**
 * La fila de botones "Compartir" al pie de una entrada.
 *
 * En el legacy estaba duplicado en `blog/compartir.php` y `ayuda/compartir.php`,
 * idénticos salvo por el ancho de la columna. Acá es uno solo y el ancho llega
 * como variable:
 *
 *   $compartirColumna = 'col-md-6';   // blog: comparte la fila con las tags
 *   require $_SERVER['DOCUMENT_ROOT'] . '/sistema/compartir.php';
 *
 * La URL sale de `wwwCanonica()` y no de `'https://' . $_SERVER['HTTP_HOST']`
 * como hacía el legacy: el `Host` lo manda el cliente, así que cualquiera podía
 * conseguir que la página generara botones de compartir apuntando a otro
 * dominio. En producción la canónica es fija.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';

$compartirColumna = $compartirColumna ?? 'col-md-12';
$url              = wwwCanonica();
$urlCodificada    = rawurlencode($url);
$asunto           = rawurlencode('Mirá esto');
$cuerpo           = rawurlencode('Mirá esta página: ' . $url);
?>
<div class="<?= e($compartirColumna) ?> col-xs-12">
    <h6 class="text-md-end text-start mb-3">Compartir</h6>
    <ul class="share-post">
        <li><a href="https://api.whatsapp.com/send?text=<?= e($urlCodificada) ?>" target="_blank" rel="noopener" aria-label="Compartir por WhatsApp"><i class="fab fa-whatsapp"></i></a></li>
        <li><a href="mailto:?subject=<?= e($asunto) ?>&amp;body=<?= e($cuerpo) ?>" aria-label="Compartir por correo"><i class="far fa-envelope"></i></a></li>
        <li><a href="https://www.facebook.com/sharer/sharer.php?u=<?= e($urlCodificada) ?>" target="_blank" rel="noopener" aria-label="Compartir en Facebook"><i class="fab fa-facebook-f"></i></a></li>
        <li><a href="https://twitter.com/intent/tweet?url=<?= e($urlCodificada) ?>" target="_blank" rel="noopener" aria-label="Compartir en X"><i class="fab fa-twitter"></i></a></li>
        <li><a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= e($urlCodificada) ?>" target="_blank" rel="noopener" aria-label="Compartir en LinkedIn"><i class="fab fa-linkedin-in"></i></a></li>
    </ul>
</div>
