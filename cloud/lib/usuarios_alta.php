<?php

declare(strict_types=1);

/**
 * Canal unico de alta de usuarios de cloud.
 *
 * Todo INSERT sobre `usuarios` de cloud pasa por aca. Hoy lo usa:
 *
 *   - cloud/api/users.php -> handleCreate()
 *
 * Es el equivalente de panel/lib/usuarios_alta.php para este proyecto. Son
 * canales separados a proposito (cloud y panel tienen docroots distintos y no
 * comparten libs), pero escriben las mismas columnas con las mismas reglas.
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
 *
 * NOTA SOBRE `usuario`
 *
 *   `usuarios.usuario` es la credencial con la que se entra (api/login.php hace
 *   `WHERE usuario = :u`). Llega siempre del llamador y NO se deriva aca: desde
 *   el 15/09/2026 el formulario de cloud la muestra en un campo propio, al lado
 *   de la contrasena. Igual sigue valiendo el correo en la practica —el campo
 *   espeja el email mientras nadie lo edite, y `credencialPedida()` cae al correo
 *   si el request no la trae—, que es la convencion que comparte con el alta por
 *   invitacion del panel. Vacia, el usuario creado no podria loguearse nunca.
 */

require_once dirname(__DIR__) . '/api/legacy_crypto.php';
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

/**
 * Da de alta un usuario y devuelve su id.
 *
 * Recibe la contrasena EN CLARO y la cifra aca adentro con el cifrado legacy
 * de Reactor, que es el que valida el login.
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
