<?php

declare(strict_types=1);

/**
 * Etiqueta de Google Analytics 4 (gtag.js).
 *
 * Port de `reactor-app/analytics.js` — que a pesar del nombre no es un `.js`
 * sino un fragmento de HTML con etiquetas `<script>`, incluido con
 * `require_once` desde `sistema/cabeza.php` (en el `<head>`) y otra vez desde
 * `sistema/pie.php`. La segunda inclusión no hace nada: `require_once` no
 * repite el mismo archivo en un request, así que la etiqueta sale una sola vez.
 * Acá se incluye una sola vez y listo.
 *
 * CUAL ES EL ID. El legacy tiene tres propiedades distintas y hay que no
 * confundirlas:
 *
 *   G-CJM86BQMB4  reactor-app  <- ESTE. Es la app end-user, la que reemplaza
 *                                 este `app/`. La cookie `_ga_CJM86BQMB4` está
 *                                 viva en los navegadores de los usuarios.
 *   G-6F5J17H188  reactor-www     el sitio público, otra propiedad.
 *   G-NYPNJ3X8K5  Firebase        el `measurementId` de la config de Firebase
 *                                 (`notification.js`), para las notificaciones
 *                                 push. No es esta etiqueta.
 *
 * Se conserva el ID de `reactor-app` a propósito: la app nueva es la misma
 * propiedad para el usuario, así que las visitas siguen sumando a la serie
 * histórica en vez de arrancar de cero en una propiedad nueva.
 *
 * NO SE MIDE EN DESARROLLO. El id sale de `GA_MEASUREMENT_ID`, que está en el
 * .env de producción y vacío en el de desarrollo. Si no hay id, esta función
 * no imprime nada: las pruebas locales no tienen por qué ensuciar las
 * estadísticas reales con sesiones que no son de nadie.
 */

/**
 * Imprime la etiqueta de gtag.js, o nada si no hay propiedad configurada.
 *
 * Se llama dentro del `<head>`, como en `sistema/cabeza.php`.
 */
function appAnalytics(): void
{
    $id = trim((string) getenv('GA_MEASUREMENT_ID'));
    if ($id === '') {
        return;
    }

    // Un id de medición es `G-XXXXXXXXXX` (GA4) o `UA-XXXXX-Y` (el formato
    // viejo): letras, números y guiones, nada más. Si trae otra cosa, está mal
    // configurado y no se imprime — antes que emitir una etiqueta rota, o algo
    // peor si el valor llegara a venir de un lugar menos confiable que el .env.
    if (!preg_match('/^[A-Za-z0-9-]{4,32}$/', $id)) {
        return;
    }

    // Aun con el formato validado, el id se escapa en los dos contextos:
    // `rawurlencode` para la URL y `json_encode` para el literal JS.
    //
    // Las banderas del `json_encode` NO son opcionales: `JSON_HEX_TAG` es la
    // que convierte `<` y `>` en `<` / `>`. Sin ella —y esta función
    // arrancó con `JSON_UNESCAPED_SLASHES`, que es peor todavía— un valor con
    // `</script>` adentro cierra la etiqueta y lo que siga se ejecuta como
    // HTML. Lo agarró la prueba de escapado.
    $idUrl = rawurlencode($id);
    $idJs  = json_encode($id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= $idUrl ?>"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());

      gtag('config', <?= $idJs ?>);
    </script>
    <?php
}
