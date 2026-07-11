<?php
/**
 * JWT HS256 minimalista — sin Composer, sin librerías externas.
 *
 * Solo lo que usa panel:
 *  - jwt_sign(array $payload, ?int $ttl): string
 *  - jwt_verify(string $token): ?array  (devuelve payload o null si inválido/expirado)
 *
 * Firma con APP_KEY_CLOUD (definido por env.php en raíz del repo). El panel
 * comparte APP_KEY con cloud a proposito: el shell se apoya en la misma tabla
 * `usuarios` y el mismo flow de auth, y usar la misma key permite que un
 * usuario logueado en cloud sea reconocido tambien por panel (cookie mediante).
 */

function _jwt_b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function _jwt_b64url_decode(string $s): string
{
    $pad = strlen($s) % 4;
    if ($pad) {
        $s .= str_repeat('=', 4 - $pad);
    }
    return base64_decode(strtr($s, '-_', '+/'), true) ?: '';
}

function jwt_sign(array $payload, ?int $ttlSeconds = 60 * 60 * 12): string
{
    $now = time();
    $payload = array_merge([
        'iat' => $now,
        'exp' => $ttlSeconds === null ? null : $now + $ttlSeconds,
    ], $payload);
    if ($payload['exp'] === null) {
        unset($payload['exp']);
    }

    $header  = ['alg' => 'HS256', 'typ' => 'JWT'];
    $segHead = _jwt_b64url_encode(json_encode($header,  JSON_UNESCAPED_SLASHES));
    $segLoad = _jwt_b64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $signing = $segHead . '.' . $segLoad;
    $sig     = hash_hmac('sha256', $signing, APP_KEY_CLOUD, true);

    return $signing . '.' . _jwt_b64url_encode($sig);
}

function jwt_verify(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$segHead, $segLoad, $segSig] = $parts;

    $expected = hash_hmac('sha256', $segHead . '.' . $segLoad, APP_KEY_CLOUD, true);
    $sig      = _jwt_b64url_decode($segSig);
    if (!hash_equals($expected, $sig)) {
        return null;
    }

    $payload = json_decode(_jwt_b64url_decode($segLoad), true);
    if (!is_array($payload)) {
        return null;
    }
    if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
        return null;
    }
    return $payload;
}
