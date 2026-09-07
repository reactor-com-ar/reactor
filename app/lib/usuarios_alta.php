<?php

declare(strict_types=1);

/**
 * Canal unico de alta de usuarios de la app.
 *
 * COPIA de panel/lib/usuarios_alta.php, con el unico cambio de la ruta del
 * cifrado legacy (aca vive en lib/, alla en api/). Las tres apps no comparten
 * docroot, igual que pasa con `habilitado.php`, `permisos.php` y `databox.php`.
 * LA FORMA DEL ALTA TIENE QUE SER LA MISMA EN LAS DOS: un usuario creado por la
 * invitacion de la app y otro creado por la del panel son la misma cuenta en la
 * misma tabla, y si cada camino escribiera columnas distintas la diferencia se
 * veria recien cuando alguien no pueda entrar.
 *
 * Todo INSERT sobre `usuarios` de la app pasa por aca. Hoy lo usa uno solo:
 *
 *   - app/invitacion/aceptar.php  (alta al aceptar una invitacion)
 *
 * El objetivo es que no haya variaciones: todo camino escribe las mismas
 * columnas, con el mismo cifrado y con los mismos valores por defecto. Si hace
 * falta una columna nueva en el alta, se agrega aca y la reciben todos.
 *
 * FORMA FIJA DEL ALTA
 *
 *   Todo usuario nace con estos valores, sin importar lo que mande el llamador:
 *
 *       autenticacion = 'F'
 *       habilitado    = 1
 *   (las cinco columnas de alcance historico —`perfiles`, `dominios`,
 *    `paneles`, `panel` y `roles`— se eliminaron el 06/09/2026; ver
 *    `20260906_1900_usuarios_sin_legacy.sql`)
 *
 *   Las plurales NO son el par de las singulares: `perfil` y `dominio` siguen
 *   recibiendo el id real que manda el llamador. `panel`, en cambio, siempre
 *   queda en NULL.
 *
 *   `roles` arranca en '' salvo que el llamador mande otro valor.
 */

require_once __DIR__ . '/habilitado.php';

/** Valores de inicializacion de las columnas plurales (ver cabecera). */






/**
 * Modo de autenticacion con el que nace todo usuario.
 *
 * Es el valor que tiene la base: 'F' en 2053 de los 2082 usuarios con dato, 'T'
 * en los 29 restantes. Ningun login del monorepo consulta esta columna --
 * `api/login.php` valida `usuario` + `contrasena` + `habilitado` y nada mas --
 * asi que es descriptiva, no un permiso.
 */
const USUARIO_AUTENTICACION_INICIAL = 'F';

/**
 * Estado con el que nace todo usuario: habilitado.
 *
 * Se escribe el ENTERO 1, que es el unico valor de `usuarios.habilitado` que
 * significa habilitado -- la columna es tinyint(1) NOT NULL y su otro valor
 * posible es 0 (ver lib/habilitado.php).
 */
const USUARIO_HABILITADO_INICIAL = HABILITADO;

// Unica diferencia con la copia del panel: alla el cifrado legacy vive en
// `api/legacy_crypto.php` y aca en `lib/legacy_crypto.php`.
require_once __DIR__ . '/legacy_crypto.php';

/**
 * Da de alta un usuario y devuelve su id.
 *
 * Recibe la contrasena EN CLARO y la cifra aca adentro: es la unica forma de
 * garantizar que los dos caminos usen el mismo cifrado legacy.
 *
 * `autenticacion`, `habilitado`, `perfiles`, `dominios`, `paneles` y `panel` NO
 * se reciben: son constantes de alta (ver cabecera).
 *
 * @param array{
 *     nombre:string, usuario:string, contrasena:string,
 *     correo?:?string, celular?:?string,
 *     dominio?:?int, perfil?:?int,
 *     roles?:?string, registrante?:?int
 * } $datos
 */
function usuarioAlta(PDO $pdo, array $datos): int
{
    $dominio = isset($datos['dominio']) ? (int) $datos['dominio'] : 0;
    $perfil  = isset($datos['perfil'])  ? (int) $datos['perfil']  : 0;

    $stmt = $pdo->prepare(
        'INSERT INTO usuarios
            (uuid, nombre, usuario, autenticacion, contrasena, correo, celular,
             habilitado, registrante, registrado,
             perfil, dominio)
         VALUES
            (:uuid, :nombre, :usuario, :autenticacion, :contrasena, :correo, :celular,
             :habilitado, :registrante, NOW(),
             :perfil, :dominio)'
    );
    $stmt->execute([
        ':uuid'          => bin2hex(random_bytes(8)),
        ':nombre'        => $datos['nombre'],
        ':usuario'       => $datos['usuario'],
        ':contrasena'    => reactor_legacy_encriptar($datos['contrasena']),
        ':correo'        => $datos['correo']  ?? null,
        ':celular'       => $datos['celular'] ?? null,
        ':registrante'   => ($datos['registrante'] ?? 0) ?: null,

        ':perfil'        => $perfil  ?: null,
        ':dominio'       => $dominio ?: null,

        // Constantes de alta: no dependen de lo que mande el llamador.
        ':autenticacion' => USUARIO_AUTENTICACION_INICIAL,
        ':habilitado'    => USUARIO_HABILITADO_INICIAL,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Fija el perfil activo de un usuario recien creado.
 *
 * El alta por invitacion no puede pasar el perfil en el INSERT: `perfiles.usuario`
 * exige que el usuario ya exista, asi que el perfil se crea despues.
 *
 * Toca la columna `perfil`, que es el perfil ACTIVO de la cuenta. La plural
 * `perfiles` ya no existe: se elimino el 06/09/2026 junto con el resto del
 * alcance historico (`20260906_1900_usuarios_sin_legacy.sql`).
 */
function usuarioPerfilActivo(PDO $pdo, int $usuarioId, int $perfilId): void
{
    $pdo->prepare('UPDATE usuarios SET perfil = :p WHERE id = :id')
        ->execute([':p' => $perfilId, ':id' => $usuarioId]);
}
