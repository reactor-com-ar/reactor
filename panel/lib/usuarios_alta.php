<?php

declare(strict_types=1);

/**
 * Canal unico de alta de usuarios del panel.
 *
 * Todo INSERT sobre `usuarios` del panel pasa por aca. Hoy lo usan:
 *
 *   - panel/api/usuarios.php      -> handleCreate()  (alta manual del BackOffice)
 *   - panel/invitacion/aceptar.php                   (alta al aceptar una invitacion)
 *
 * El objetivo es que no haya variaciones: los dos caminos escriben las mismas
 * columnas, con el mismo cifrado y con los mismos valores por defecto. Si hace
 * falta una columna nueva en el alta, se agrega aca y la reciben los dos.
 *
 * FORMA FIJA DEL ALTA
 *
 *   Todo usuario nace con estos valores, sin importar lo que mande el llamador:
 *
 *       autenticacion = 'F'
 *       habilitado    = '1'
 *       perfiles      = 0
 *       dominios      = ''
 *       paneles       = ''
 *       panel         = NULL
 *
 *   Las plurales NO son el par de las singulares: `perfil` y `dominio` siguen
 *   recibiendo el id real que manda el llamador. `panel`, en cambio, siempre
 *   queda en NULL.
 *
 *   `roles` arranca en '' salvo que el llamador mande otro valor.
 */

/** Valores de inicializacion de las columnas plurales (ver cabecera). */
const USUARIO_PERFILES_INICIAL = 0;
const USUARIO_DOMINIOS_INICIAL = '';
const USUARIO_PANELES_INICIAL  = '';

/** `panel` nace siempre vacio: el alta no elige panel. */
const USUARIO_PANEL_INICIAL = null;

/** `roles` arranca vacio salvo que el llamador mande otra cosa. */
const USUARIO_ROLES_INICIAL = '';

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
 * '1' es la convencion real de la tabla (2064 filas contra una sola 'S') y esta
 * en la lista que acepta `cloud/api/login.php` (['S','1','Y']), asi que el
 * usuario recien creado puede entrar.
 */
const USUARIO_HABILITADO_INICIAL = '1';

require_once dirname(__DIR__) . '/api/legacy_crypto.php';

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
             perfil, perfiles, dominio, dominios, panel, paneles, roles)
         VALUES
            (:uuid, :nombre, :usuario, :autenticacion, :contrasena, :correo, :celular,
             :habilitado, :registrante, NOW(),
             :perfil, :perfiles, :dominio, :dominios, :panel, :paneles, :roles)'
    );
    $stmt->execute([
        ':uuid'          => bin2hex(random_bytes(8)),
        ':nombre'        => $datos['nombre'],
        ':usuario'       => $datos['usuario'],
        ':contrasena'    => reactor_legacy_encriptar($datos['contrasena']),
        ':correo'        => $datos['correo']  ?? null,
        ':celular'       => $datos['celular'] ?? null,
        ':registrante'   => ($datos['registrante'] ?? 0) ?: null,
        ':roles'         => $datos['roles'] ?? USUARIO_ROLES_INICIAL,

        ':perfil'        => $perfil  ?: null,
        ':dominio'       => $dominio ?: null,

        // Constantes de alta: no dependen de lo que mande el llamador.
        ':panel'         => USUARIO_PANEL_INICIAL,
        ':autenticacion' => USUARIO_AUTENTICACION_INICIAL,
        ':habilitado'    => USUARIO_HABILITADO_INICIAL,
        ':perfiles'      => USUARIO_PERFILES_INICIAL,
        ':dominios'      => USUARIO_DOMINIOS_INICIAL,
        ':paneles'       => USUARIO_PANELES_INICIAL,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Fija el perfil activo de un usuario recien creado.
 *
 * El alta por invitacion no puede pasar el perfil en el INSERT: `perfiles.usuario`
 * exige que el usuario ya exista, asi que el perfil se crea despues.
 *
 * Toca SOLO la columna singular `perfil`. `perfiles` es una constante de alta
 * (queda en 0) y no acompaña a `perfil` — ver la cabecera de este archivo.
 */
function usuarioPerfilActivo(PDO $pdo, int $usuarioId, int $perfilId): void
{
    $pdo->prepare('UPDATE usuarios SET perfil = :p WHERE id = :id')
        ->execute([':p' => $perfilId, ':id' => $usuarioId]);
}
