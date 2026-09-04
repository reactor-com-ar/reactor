<?php

declare(strict_types=1);

/**
 * JWT HS256 minimalista — sin Composer, sin librerías externas.
 *
 * A diferencia del de cloud (`cloud/lib/jwt.php`), acá la clave se pasa por
 * parámetro: la app firma sus propios tokens con APP_KEY_APP pero además tiene
 * que VERIFICAR los tokens legacy, que están firmados con otra clave
 * (ver `legacy_sesion.php`). Una sola implementación para los dos casos.
 *
 * El formato es el estándar (`base64url(header).base64url(payload).base64url(hmac)`),
 * o sea el mismo que emite firebase/php-jwt en el legacy: los tokens que hoy
 * tienen los celulares validan acá sin tocar nada.
 */

function jwt_b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function jwt_b64url_decode(string $s): string
{
    $pad = strlen($s) % 4;
    if ($pad) {
        $s .= str_repeat('=', 4 - $pad);
    }
    return base64_decode(strtr($s, '-_', '+/'), true) ?: '';
}

/**
 * Firma un payload. Con `$ttlSegundos = null` el token no lleva `exp`.
 */
function jwt_sign(array $payload, string $clave, ?int $ttlSegundos = null): string
{
    $now     = time();
    $payload = array_merge(['iat' => $now], $payload);
    if ($ttlSegundos !== null) {
        $payload['exp'] = $now + $ttlSegundos;
    }

    $segHead = jwt_b64url_encode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
    $segLoad = jwt_b64url_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    $firmado = $segHead . '.' . $segLoad;

    return $firmado . '.' . jwt_b64url_encode(hash_hmac('sha256', $firmado, $clave, true));
}

/**
 * Devuelve el payload o null si la firma no valida.
 *
 * `$exigirVencimiento = false` acepta tokens vencidos (solo valida la firma).
 * Lo necesita la adopción del token legacy: ver `legacy_sesion.php`.
 */
function jwt_verify(string $token, string $clave, bool $exigirVencimiento = true): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$segHead, $segLoad, $segSig] = $parts;

    $esperado = hash_hmac('sha256', $segHead . '.' . $segLoad, $clave, true);
    if (!hash_equals($esperado, jwt_b64url_decode($segSig))) {
        return null;
    }

    $payload = json_decode(jwt_b64url_decode($segLoad), true);
    if (!is_array($payload)) {
        return null;
    }
    if ($exigirVencimiento && isset($payload['exp']) && time() >= (int) $payload['exp']) {
        return null;
    }

    return $payload;
}
