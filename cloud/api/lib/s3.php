<?php

declare(strict_types=1);

// Helper de S3 para el Explorador S3 (skill creador_explorador_s3).
// Implementa SigV4 mínimo para las 4 operaciones que usamos:
//   - ListObjectsV2
//   - PutObject (upload + create_folder)
//   - DeleteObject
//   - HeadObject
//
// Reusa las 4 variables canónicas AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY,
// AWS_REGION, AWS_S3_BUCKET desde `env.php`. Si alguna falta, aborta con
// mensaje claro.

foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_REGION', 'AWS_S3_BUCKET'] as $k) {
    if (!defined($k) || (string) constant($k) === '') {
        throw new RuntimeException('Falta configurar la constante ' . $k . ' en el .env.');
    }
}

/** Firma canónica de la request y devuelve el header Authorization. */
function s3SignRequest(string $method, string $host, string $path, string $query,
                       array $headers, string $payloadHash, string $region): array
{
    $ts        = gmdate('Ymd\THis\Z');
    $date      = substr($ts, 0, 8);
    $headers['host']                  = $host;
    $headers['x-amz-content-sha256']  = $payloadHash;
    $headers['x-amz-date']            = $ts;

    ksort($headers);
    $canonicalHeaders = '';
    $signedHeadersArr = [];
    foreach ($headers as $k => $v) {
        $lk = strtolower($k);
        $canonicalHeaders .= $lk . ':' . trim((string) $v) . "\n";
        $signedHeadersArr[] = $lk;
    }
    $signedHeaders = implode(';', $signedHeadersArr);

    // Canonical query string: keys ordenados, cada key/val encodeado.
    $qparts = [];
    if ($query !== '') {
        parse_str($query, $qarr);
        ksort($qarr);
        foreach ($qarr as $k => $v) {
            $qparts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
    }
    $canonicalQuery = implode('&', $qparts);

    $canonicalRequest = $method . "\n" .
                        $path . "\n" .
                        $canonicalQuery . "\n" .
                        $canonicalHeaders . "\n" .
                        $signedHeaders . "\n" .
                        $payloadHash;

    $credentialScope = $date . '/' . $region . '/s3/aws4_request';
    $stringToSign    = "AWS4-HMAC-SHA256\n" . $ts . "\n" . $credentialScope . "\n" .
                        hash('sha256', $canonicalRequest);

    $kDate    = hash_hmac('sha256', $date,          'AWS4' . AWS_SECRET_ACCESS_KEY, true);
    $kRegion  = hash_hmac('sha256', $region,        $kDate,   true);
    $kService = hash_hmac('sha256', 's3',           $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature= hash_hmac('sha256', $stringToSign,  $kSigning);

    $auth = 'AWS4-HMAC-SHA256 Credential=' . AWS_ACCESS_KEY_ID . '/' . $credentialScope .
            ', SignedHeaders=' . $signedHeaders .
            ', Signature=' . $signature;

    $headers['authorization'] = $auth;
    return $headers;
}

/** Ejecuta una request contra S3 y devuelve [status, body, response_headers]. */
function s3Request(string $method, string $key = '', string $query = '',
                   string $body = '', array $extraHeaders = []): array
{
    $bucket = AWS_S3_BUCKET;
    $region = AWS_REGION;
    // Path-style para máxima compatibilidad con nombres de bucket que contienen
    // puntos (ej: media-dev.proyecto.com), que rompen wildcard SSL en virtual-hosted.
    $host   = 's3.' . $region . '.amazonaws.com';
    // El path debe estar rawurlencode-eado por segmento (no encodear `/`).
    $segments = array_map('rawurlencode', explode('/', ltrim($key, '/')));
    $path   = '/' . $bucket . '/' . implode('/', $segments);
    if ($key === '') $path = '/' . $bucket;

    $payloadHash = hash('sha256', $body);
    $headers     = s3SignRequest($method, $host, $path, $query,
                                 $extraHeaders, $payloadHash, $region);

    $url = 'https://' . $host . $path . ($query !== '' ? '?' . $query : '');
    $ch  = curl_init($url);

    $curlHeaders = [];
    foreach ($headers as $k => $v) $curlHeaders[] = $k . ': ' . $v;

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $curlHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = (string) curl_exec($ch);
    if ($resp === '' && curl_errno($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('S3 curl error: ' . $err);
    }
    $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hSize   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $respHead = substr($resp, 0, $hSize);
    $respBody = substr($resp, $hSize);
    return [$status, $respBody, $respHead];
}

/** ListObjectsV2 con delimiter="/" para emular carpetas. */
function s3ListObjects(string $prefix, ?string $token = null, int $maxKeys = 200): array
{
    $qs = 'list-type=2&delimiter=%2F&max-keys=' . $maxKeys;
    if ($prefix !== '')       $qs .= '&prefix=' . rawurlencode($prefix);
    if ($token !== null && $token !== '') $qs .= '&continuation-token=' . rawurlencode($token);
    [$status, $body] = s3Request('GET', '', $qs);
    if ($status !== 200) throw new RuntimeException('S3 list HTTP ' . $status . ': ' . substr($body, 0, 300));

    $xml = simplexml_load_string($body);
    $folders = [];
    foreach ($xml->CommonPrefixes ?? [] as $cp) $folders[] = (string) $cp->Prefix;
    $objects = [];
    foreach ($xml->Contents ?? [] as $obj) {
        $objects[] = [
            'key'            => (string) $obj->Key,
            'size'           => (int) $obj->Size,
            'last_modified'  => (string) $obj->LastModified,
        ];
    }
    return [
        'folders'    => $folders,
        'objects'    => $objects,
        'truncated'  => ((string) ($xml->IsTruncated ?? 'false')) === 'true',
        'next_token' => (string) ($xml->NextContinuationToken ?? ''),
    ];
}

/** URL pública HTTPS de un objeto (bucket path-style). */
function s3PublicUrl(string $key): string
{
    $segments = array_map('rawurlencode', explode('/', ltrim($key, '/')));
    return 'https://s3.' . AWS_REGION . '.amazonaws.com/' . AWS_S3_BUCKET .
           '/' . implode('/', $segments);
}

/** Sube un objeto (PutObject). */
function s3PutObject(string $key, string $content, string $contentType): void
{
    [$status, $body] = s3Request('PUT', $key, '', $content, [
        'content-type'   => $contentType,
        'content-length' => (string) strlen($content),
    ]);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('S3 put HTTP ' . $status . ': ' . substr($body, 0, 300));
    }
}

/** Borra un objeto. */
function s3DeleteObject(string $key): void
{
    [$status, $body] = s3Request('DELETE', $key);
    if ($status !== 204 && $status !== 200) {
        throw new RuntimeException('S3 delete HTTP ' . $status . ': ' . substr($body, 0, 300));
    }
}

/** Lista TODOS los objetos bajo un prefijo (sin delimiter), paginado. */
function s3ListAllUnderPrefix(string $prefix): array
{
    $keys  = [];
    $token = null;
    do {
        $qs = 'list-type=2&max-keys=1000&prefix=' . rawurlencode($prefix);
        if ($token) $qs .= '&continuation-token=' . rawurlencode($token);
        [$status, $body] = s3Request('GET', '', $qs);
        if ($status !== 200) throw new RuntimeException('S3 list-all HTTP ' . $status);
        $xml = simplexml_load_string($body);
        foreach ($xml->Contents ?? [] as $obj) $keys[] = (string) $obj->Key;
        $token = ((string) ($xml->IsTruncated ?? 'false')) === 'true'
               ? (string) $xml->NextContinuationToken
               : null;
    } while ($token);
    return $keys;
}

/** Sanea el nombre de archivo/carpeta (skill: [^\w\.\- ] → _). */
function s3SanearNombre(string $nombre): string
{
    $nombre = trim($nombre);
    $nombre = preg_replace('/[^\w\.\- ]/u', '_', $nombre);
    if ($nombre === '' || $nombre === '.' || $nombre === '..') return '';
    return $nombre;
}
