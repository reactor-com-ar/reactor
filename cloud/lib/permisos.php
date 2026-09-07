<?php

declare(strict_types=1);

/**
 * Los TRES permisos del perfil: `operacion`, `invitacion` y `facturacion`.
 *
 * Son columnas de `perfiles` (migracion
 * `cloud/sql/migrations/20260907_1000_perfiles_permisos_operacion_invitacion_facturacion.sql`)
 * y son BANDERAS `habilitado` aunque no se llamen asi: `tinyint(1) NOT NULL
 * DEFAULT 0`, dos valores y nada mas. Por eso este archivo no define su propio
 * criterio de verdad — se apoya en `esHabilitado()` / `valorHabilitado()` de
 * lib/habilitado.php, que es el unico lugar del repo donde ese criterio esta
 * escrito.
 *
 * QUE ABRE CADA UNO, y en que app:
 *
 *   operacion    app.reactor.com.ar  ->  ver y usar los paneles de operacion
 *                (los controles del dominio). Sin el, la app se abre igual
 *                pero no dibuja ningun panel ni deja apretar un boton.
 *   invitacion   app.reactor.com.ar  ->  el item "Invitar un Usuario".
 *   facturacion  panel.reactor.com.ar -> el agrupador "Cuenta" entero
 *                (Facturas / Recibos / Facturacion).
 *
 * SON PERMISOS DEL PERFIL, NO DE LA CUENTA. La misma persona puede tener
 * facturacion en un dominio y no tenerla en otro: lo que decide es el perfil
 * con el que la sesion esta parada, igual que pasa con `habilitado`.
 *
 * NO SE DERIVAN DE `tipo` NI LO REEMPLAZAN. `perfiles.tipo` (`A`/`O`) sigue
 * siendo lo que lee el sistema legacy y lo que gatea la ENTRADA al panel
 * (lib/acceso.php); estos tres gatean pantallas concretas una vez adentro. Un
 * Administrador puede no tener facturacion y un Operador puede tener
 * operacion — es el caso normal, no el raro. Mirar `tipo` para adivinar un
 * permiso es exactamente el error que ya documenta el CLAUDE.md raiz sobre
 * `tipo` y el difunto `rol`.
 *
 * Cloud y app tienen una copia identica en su propio `lib/`: las tres apps no
 * comparten docroot, igual que pasa con `habilitado.php` y `usuarios_alta.php`.
 */

require_once __DIR__ . '/habilitado.php';

/**
 * Catalogo cerrado: clave de la columna -> como se muestra.
 *
 * Es la unica lista de permisos que existe. Agregar uno es agregar una columna
 * y una entrada aca; no hay tabla de catalogo que dar de alta (ver la cabecera
 * de la migracion por que no la hay).
 */
const PERFIL_PERMISOS = [
    'operacion' => [
        'etiqueta' => 'Operación',
        'donde'    => 'app.reactor.com.ar',
        'detalle'  => 'Ver y usar los paneles de operación.',
    ],
    'invitacion' => [
        'etiqueta' => 'Invitación',
        'donde'    => 'app.reactor.com.ar',
        'detalle'  => 'Invitar usuarios nuevos al dominio.',
    ],
    'facturacion' => [
        'etiqueta' => 'Facturación',
        'donde'    => 'panel.reactor.com.ar',
        'detalle'  => 'Ver y abonar las facturas del servicio.',
    ],
];

/** Las claves sueltas, para recorrer sin arrastrar los textos. */
function perfilPermisosClaves(): array
{
    return array_keys(PERFIL_PERMISOS);
}

/** ¿`$clave` es uno de los tres permisos? Cualquier otra cosa es un error de codigo. */
function esPermisoDePerfil(mixed $clave): bool
{
    return is_string($clave) && array_key_exists($clave, PERFIL_PERMISOS);
}

/**
 * Los tres permisos de una fila de `perfiles`, ya como booleanos.
 *
 * Tolera que la fila no traiga la columna (un SELECT que no la pidio): ahi el
 * permiso es `false`. Es el mismo criterio que `esHabilitado()` sobre un valor
 * ausente — sin dato no hay permiso.
 *
 * @return array{operacion:bool, invitacion:bool, facturacion:bool}
 */
function perfilPermisos(array $fila): array
{
    $permisos = [];
    foreach (perfilPermisosClaves() as $clave) {
        $permisos[$clave] = esHabilitado($fila[$clave] ?? 0);
    }

    return $permisos;
}

/**
 * Los tres en `false`. Es lo que corresponde cuando no hay perfil: una sesion
 * sin perfil no tiene permisos, no los tiene "todos".
 *
 * @return array{operacion:bool, invitacion:bool, facturacion:bool}
 */
function perfilSinPermisos(): array
{
    return array_fill_keys(perfilPermisosClaves(), false);
}
