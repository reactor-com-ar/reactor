<?php

declare(strict_types=1);

/**
 * La bandera `habilitado`: DOS valores posibles y ninguno mas.
 *
 *   1 = habilitado      0 = deshabilitado
 *
 * Vale para TODA columna llamada `habilitado` de la base (`usuarios`,
 * `perfiles`, `dominios`, `dispositivos`, `canales`, `botones`, `controles`,
 * `paneles`, `planes`, `roles`, ...). Desde la migracion
 * `20260905_2200_habilitado_tinyint_0_1.sql` todas son
 * `tinyint(1) NOT NULL DEFAULT 0`, asi que el motor ya no admite NULL,
 * ni cadena vacia, ni las 'S'/'N' que arrastraban dos filas de `usuarios`.
 *
 * POR QUE UN ARCHIVO PARA DOS CONSTANTES. Antes cada modulo se defendia por su
 * cuenta y con un criterio distinto: `in_array($h, ['S','1','Y'])` en el login,
 * `(string) $h === '1'` en los controles, `$h <> 1` en dispositivos y
 * `COALESCE($h, '') <> '1'` en el listado de perfiles. Cuatro lecturas del mismo
 * dato que no coincidian en los bordes -- y la fila que caia en un borde se veia
 * habilitada en una pantalla y deshabilitada en otra. Con un solo lugar donde
 * esta escrito el criterio, agregar un modulo no vuelve a abrir esa brecha.
 *
 * ESCRIBIR SIEMPRE EL ENTERO, nunca el booleano de PHP: PDO bindea `false` como
 * cadena vacia. Contra la columna vieja (varchar) eso dejaba un tercer valor
 * silencioso en la base -- verificado en desarrollo. Hoy el tinyint lo
 * rechazaria, pero `valorHabilitado()` evita depender de eso.
 *
 * Cloud y app tienen una copia identica en su propio `lib/`: no comparten
 * docroot con el panel, igual que pasa con `usuarios_alta.php`.
 */

/** Unico valor que significa "habilitado". */
const HABILITADO = 1;

/** Unico valor que significa "deshabilitado". */
const DESHABILITADO = 0;

/**
 * ¿El valor leido de la base esta habilitado?
 *
 * Se castea a int en vez de comparar strings: asi da lo mismo que la columna
 * llegue como 1 (MySQL nativo) o como '1' (PDO con emulacion de prepares).
 */
function esHabilitado(mixed $valor): bool
{
    return (int) $valor === HABILITADO;
}

/**
 * Traduce cualquier entrada (booleano del front, '1', 0, null) al entero que se
 * escribe en la base. Es el unico valor que deberia llegar a un bind de PDO.
 *
 * Los strings se resuelven con la MISMA lista que usa la migracion para
 * normalizar el contenido historico, y no con la veracidad de PHP: ahi
 * `'N'` es truthy y una baja escrita "a la vieja usanza" habilitaria la fila.
 */
function valorHabilitado(mixed $valor): int
{
    if (is_string($valor)) {
        $texto = strtoupper(trim($valor));
        return in_array($texto, ['1', 'S', 'SI', 'Y', 'T', 'TRUE', 'ON'], true)
            ? HABILITADO
            : DESHABILITADO;
    }

    return $valor ? HABILITADO : DESHABILITADO;
}
