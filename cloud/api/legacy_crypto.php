<?php

declare(strict_types=1);

// Replica del cifrado historico de Reactor: XOR sumativa contra una clave
// rotada en 1 char + base64. La clave por defecto es '0123456789' y es la
// que se uso para todos los registros existentes de la columna
// `usuarios.contrasena`. La copia exacta del original PHP esta documentada
// en CLAUDE.md / pedido del usuario; no cambiar la formula sin migrar la
// base, porque romperia el login de todos los usuarios actuales.
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
