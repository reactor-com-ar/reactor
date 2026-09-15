<?php

declare(strict_types=1);

/**
 * Etiquetas de Google (gtag.js) del sitio público.
 *
 * Port de `reactor-www/analytics.js` — que a pesar del nombre no es un `.js`
 * sino un fragmento de HTML con etiquetas `<script>`, incluido con
 * `require_once` desde `sistema/cabeza.php`.
 *
 * SON DOS PROPIEDADES, NO UNA. El legacy emite dos `gtag('config', ...)` en la
 * misma etiqueta y las dos hacen falta:
 *
 *   G-6F5J17H188   Google Analytics 4, propiedad `reactor-www`
 *   AW-17108947322 Google Ads, para las conversiones de las campañas
 *
 * CUIDADO CON EL ID DE LA APP. `GA_MEASUREMENT_ID` ya existe en el .env del
 * repo y vale `G-CJM86BQMB4`: ésa es la propiedad de `app/`, la app end-user
 * (ver `app/lib/analytics.php`). Son propiedades DISTINTAS y mezclarlas
 * arruinaría las dos series. Por eso acá las constantes son propias:
 * `WWW_GA_MEASUREMENT_ID` y `WWW_GOOGLE_ADS_ID`.
 *
 * NO SE MIDE EN DESARROLLO. Los dos ids están en el .env de producción y
 * vacíos en el de desarrollo; sin id no se imprime nada, así que las pruebas
 * locales no ensucian las estadísticas ni disparan conversiones falsas.
 */

require_once dirname(__DIR__, 2) . '/env.php';

/**
 * Imprime las etiquetas de gtag.js, o nada si no hay ninguna propiedad
 * configurada. Se llama dentro del `<head>`, desde `sistema/cabeza.php`.
 */
function wwwAnalytics(): void
{
    $ids = [];
    foreach (['WWW_GA_MEASUREMENT_ID', 'WWW_GOOGLE_ADS_ID'] as $constante) {
        if (!defined($constante)) {
            continue;
        }
        $id = trim((string) constant($constante));
        // Un id de medición es `G-XXXXXXXXXX` (GA4), `AW-XXXXXXXXX` (Ads) o
        // `UA-XXXXX-Y` (el formato viejo): letras, números y guiones, nada más.
        // Si trae otra cosa está mal configurado y se descarta, antes que emitir
        // una etiqueta rota.
        if ($id !== '' && preg_match('/^[A-Za-z0-9-]{4,32}$/', $id)) {
            $ids[] = $id;
        }
    }

    if ($ids === []) {
        return;
    }

    // gtag.js se carga una sola vez, con el primer id; los demás se configuran
    // sobre la misma librería. Cargarla dos veces duplicaría los page_view.
    $idUrl = rawurlencode($ids[0]);
    ?>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= $idUrl ?>"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
<?php foreach ($ids as $id): ?>
      gtag('config', <?= json_encode($id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
<?php endforeach; ?>
    </script>
    <?php
}
