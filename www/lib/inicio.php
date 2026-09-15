<?php

declare(strict_types=1);

/**
 * Arranque de cualquier página del sitio público. Es la primera línea de todas:
 *
 *   require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
 *
 * Ocupa el lugar de `framework/insertar.php` del legacy, que cargaba el
 * framework entero desde `/var/www/reactor-api`. Acá no hay nada de eso: sólo
 * los cuatro includes propios de `www/` y la configuración de errores.
 *
 * POR QUÉ `DOCUMENT_ROOT` Y NO `dirname(__DIR__, n)`. El resto del repo usa
 * rutas relativas al archivo porque cada app tiene dos o tres niveles y
 * siempre los mismos. Acá las páginas viven a profundidades distintas —`/`,
 * `/blog/`, `/productos/dispositivos/CE-D4CO/`— así que un `dirname()` habría
 * que contarlo bien en cada archivo nuevo, y contar mal no rompe nada hasta que
 * alguien mueve la página de carpeta. `DOCUMENT_ROOT` es el mismo string en
 * todas y es lo que ya usaba el sitio viejo, así que no hay nada nuevo que
 * aprender. Sólo lo sirve Apache: estas páginas no corren por CLI.
 */

// ERRORES A LA VISTA SÓLO EN DESARROLLO. El legacy tenía `display_errors = 1`
// fijo en `framework/insertar.php`, así que en producción cualquier aviso de
// PHP salía impreso en el medio del HTML, a la vista de quien estuviera
// navegando y con las rutas del servidor adentro. Acá se registran siempre en
// el log y se muestran sólo cuando el .env dice `development`.
require_once dirname(__DIR__, 2) . '/env.php';

$esProduccion = APP_ENV === 'production';
ini_set('display_errors', $esProduccion ? '0' : '1');
ini_set('display_startup_errors', $esProduccion ? '0' : '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);
unset($esProduccion);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/parametros.php';
require_once __DIR__ . '/pagina.php';
require_once __DIR__ . '/mensaje.php';

/**
 * Redirige y corta. Es el `$oUrl->ir()` del legacy.
 *
 * El 302 es a propósito: casi todos los usos son atajos de marca
 * (`/whatsapp`, `/correo`) cuyo destino puede cambiar. Un 301 se lo queda el
 * navegador para siempre y cambiarlo después no tendría efecto sobre quien ya
 * pasó una vez.
 */
function wwwIr(string $destino, int $codigo = 302): never
{
    header('Location: ' . $destino, true, $codigo);
    exit;
}

/**
 * Valor de la query string o del POST, ya recortado. Es el
 * `$oFormulario->getpost()` del legacy.
 *
 * Devuelve siempre un string: si el parámetro llega como arreglo
 * (`?bus[]=a&bus[]=b`, que cualquiera puede tipear) se descarta, porque lo que
 * espera quien llama es texto y un arreglo reventaría el `trim()`.
 */
function wwwEntrada(string $campo, string $default = ''): string
{
    $valor = $_POST[$campo] ?? $_GET[$campo] ?? null;

    return is_string($valor) ? trim($valor) : $default;
}

/** Igual que `wwwEntrada()` pero para enteros: ids, límites, categorías. */
function wwwEntradaEntera(string $campo, int $default = 0): int
{
    $valor = wwwEntrada($campo);

    return $valor === '' || !ctype_digit($valor) ? $default : (int) $valor;
}
