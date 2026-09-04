<?php

declare(strict_types=1);

/**
 * Cifrado histórico de Reactor: XOR sumativa contra una clave rotada en 1 char
 * + base64, clave global '0123456789'. Es el formato en el que está guardada
 * `usuarios.contrasena` para los ~2000 usuarios existentes, así que el login
 * compara cifrando lo tipeado (no se puede migrar a bcrypt sin reset masivo).
 *
 * Copia literal de `cloud/api/legacy_crypto.php`. Está duplicado a propósito:
 * cloud y app tienen docroots distintos y no comparten libs. Si se cambia la
 * fórmula hay que cambiar las dos.
 */

function reactor_legacy_encriptar(string $cadena, string $clave = ''): string
{
    if ($clave === '') $clave = '0123456789';
    $resultado  = '';
    $cadenaLen  = strlen($cadena);
    $claveLen   = strlen($clave);
    if ($claveLen === 0) return '';
    for ($i = 0; $i < $cadenaLen; $i++) {
        $offset = ($i % $claveLen) - 1;
        if ($offset < 0) $offset += $claveLen;
        $resultado .= chr((ord($cadena[$i]) + ord($clave[$offset])) % 256);
    }
    return base64_encode($resultado);
}

function reactor_legacy_desencriptar(string $cadena, string $clave = ''): string
{
    if ($clave === '') $clave = '0123456789';
    $bin = base64_decode($cadena, true);
    if ($bin === false) return '';
    $resultado = '';
    $binLen    = strlen($bin);
    $claveLen  = strlen($clave);
    if ($claveLen === 0) return '';
    for ($i = 0; $i < $binLen; $i++) {
        $offset = ($i % $claveLen) - 1;
        if ($offset < 0) $offset += $claveLen;
        $resultado .= chr((ord($bin[$i]) - ord($clave[$offset]) + 256) % 256);
    }
    return $resultado;
}
