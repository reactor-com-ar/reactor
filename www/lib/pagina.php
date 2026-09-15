<?php

declare(strict_types=1);

/**
 * Estado de la página en curso: título, miniatura para redes, descripción y
 * fecha de última modificación. Reemplaza al objeto `$oPagina` del framework
 * legacy, que era una variable global que cada página pisaba antes de incluir
 * `sistema/cabeza.php`.
 *
 * El mecanismo es el mismo —la página declara sus metas y después incluye la
 * cabeza— pero pasa por funciones en vez de por una global: así no hay forma de
 * que un include intermedio le cambie el título a la página sin que se note.
 *
 *   wwwTitulo('Blog');                  // -> <title>Reactor | Blog</title>
 *   require __DIR__ . '/sistema/cabeza.php';
 *
 * Cada setter devuelve el valor ya resuelto, así que sirve tanto para escribir
 * como para leer.
 */

require_once __DIR__ . '/parametros.php';

/**
 * Escapa para HTML. Misma firma y mismo nombre que en `panel/lib/publico.php` y
 * los `_layout.php` de `app/`.
 */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * Título de la pestaña.
 *
 * Sin argumento devuelve el que haya. Con argumento lo fija y le antepone el
 * nombre de marca: `wwwTitulo('Blog')` da `Reactor | Blog`. Una página que
 * necesite el título crudo, sin la marca adelante, puede pasar `$marca = false`
 * — lo usa la ficha de cada entrada, donde el título ya es largo de por sí.
 */
function wwwTitulo(?string $titulo = null, bool $marca = true): string
{
    static $valor = '';

    if ($titulo !== null) {
        $titulo = trim($titulo);
        $valor  = ($marca && $titulo !== '') ? wwwNombre() . ' | ' . $titulo : $titulo;
    }

    return $valor !== '' ? $valor : wwwNombre() . ' | Internet Of Things';
}

/** Descripción para buscadores y para la tarjeta de Open Graph. */
function wwwDescripcion(?string $descripcion = null): string
{
    static $valor = '';

    if ($descripcion !== null) {
        // Las bajadas de las entradas vienen del back office y pueden traer
        // HTML. En un `<meta content>` eso no se renderiza, se muestra crudo.
        $valor = trim(preg_replace('/\s+/', ' ', strip_tags($descripcion)) ?? '');
        if (mb_strlen($valor) > 300) {
            $valor = mb_substr($valor, 0, 297) . '...';
        }
    }

    return $valor !== '' ? $valor : 'Plataforma de control IOT: dispositivos, aplicación y nube para automatizar y monitorear a distancia.';
}

/**
 * Imagen de vista previa al compartir en redes.
 *
 * Tiene que ser una URL ABSOLUTA: Facebook, WhatsApp y Twitter descargan la
 * imagen desde sus propios servidores, donde una ruta que arranca con `/` no
 * significa nada. Por eso una miniatura relativa se completa con el host.
 */
function wwwMiniatura(?string $miniatura = null): string
{
    static $valor = '';

    if ($miniatura !== null) {
        $miniatura = trim($miniatura);
        if ($miniatura !== '' && !preg_match('#^https?://#i', $miniatura)) {
            $miniatura = wwwBaseUrl() . '/' . ltrim($miniatura, '/');
        }
        $valor = $miniatura;
    }

    return $valor !== '' ? $valor : wwwBaseUrl() . '/img/logos/og-image.png';
}

/**
 * Fecha de última modificación, en formato ISO 8601 UTC.
 *
 * Por defecto es la del archivo .php que se está sirviendo, igual que hacía el
 * legacy. Las páginas de contenido le pasan la fecha de la entrada.
 */
function wwwModificada(?string $fecha = null): string
{
    static $valor = '';

    if ($fecha !== null) {
        $ts = strtotime($fecha);
        if ($ts !== false) {
            $valor = gmdate('Y-m-d\TH:i:s\Z', $ts);
        }
    }

    if ($valor === '') {
        $archivo = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        $ts      = ($archivo !== '' && is_file($archivo)) ? filemtime($archivo) : false;
        $valor   = gmdate('Y-m-d\TH:i:s\Z', $ts === false ? time() : $ts);
    }

    return $valor;
}

/**
 * URL base pública del sitio.
 *
 * En producción es fija (`https://www.reactor.com.ar`) por el mismo motivo que
 * `appBaseUrl()` y `panelBaseUrl()`: la usan URLs que viajan fuera del request
 * —las metas de Open Graph y el canonical— y derivarlas de un `Host` falseado
 * publicaría el sitio bajo otro dominio. En desarrollo sí sale de la request,
 * que ahí es `localhost:8134`, el puerto del vhost de `www`.
 */
function wwwBaseUrl(): string
{
    if (defined('WWW_BASE_URL') && trim((string) WWW_BASE_URL) !== '') {
        return rtrim(trim((string) WWW_BASE_URL), '/');
    }
    if (APP_ENV === 'production') {
        return 'https://www.reactor.com.ar';
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) {
        $host = 'localhost:8134';
    }
    $esHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    return ($esHttps ? 'https' : 'http') . '://' . $host;
}

/**
 * URL canónica de la página en curso: la base pública más el path pedido, sin
 * querystring. La querystring se descarta a propósito — `/blog?cat=110` y
 * `/blog` son la misma página para un buscador, y dejarla haría que cada filtro
 * se indexara como contenido duplicado.
 */
function wwwCanonica(): string
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
    // `/index.php` y `/` son la misma página; la canónica es la corta.
    $path = preg_replace('#/index\.php$#', '/', $path) ?? $path;

    return wwwBaseUrl() . $path;
}

/**
 * Sufijo de cache-busting para el CSS y el JS propios del sitio.
 *
 * Mismo mecanismo que `cloud/version.txt` y `panel/version.txt`: al tocar un
 * asset hay que subir el número o el navegador sigue sirviendo la copia vieja.
 * Los archivos del theme también lo llevan aunque no se editen, porque el
 * legacy los servía con `?rnd=478` fijo y cualquier navegador que haya pasado
 * por el sitio viejo los tiene cacheados con esa URL.
 */
function wwwVersion(): string
{
    static $version = null;

    if ($version === null) {
        $archivo = dirname(__DIR__) . '/version.txt';
        $version = is_file($archivo) ? trim((string) file_get_contents($archivo)) : (string) time();
    }

    return $version;
}
