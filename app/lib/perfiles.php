<?php

declare(strict_types=1);

/**
 * Alta de perfiles de la app: la fila de `perfiles` que le da a una persona
 * acceso a un dominio.
 *
 * Hoy lo usa un solo camino —`app/invitacion/aceptar.php`— y es a proposito que
 * la logica no viva ahi: es la MISMA decision que toman los otros dos caminos
 * que crean perfiles en el repo (el alta de cloud y `panel/invitacion/aceptar.php`)
 * y cada uno la resuelve distinto por buenas razones. Tenerla en un archivo
 * propio deja escrito en un lugar cual es la de la app.
 *
 * EL PERFIL DE LA APP ES DE OPERADOR, y esa es la diferencia de fondo con el
 * del panel:
 *
 *   | | panel | app |
 *   |---|---|---|
 *   | `tipo` | `A` (Administrador) | **`O` (Operador)** |
 *   | `operacion` | 1 | 1 |
 *   | `invitacion` | 1 | 1 |
 *   | `facturacion` | 0 | 0 |
 *
 * El panel escribe `A` porque al panel SOLO entra un administrador
 * (`perfiles.tipo = 'A'`, ver panel/lib/acceso.php): una invitacion emitida
 * desde alla significa alta administrativa. La de la app significa otra cosa —
 * "sumate a operar los equipos de este dominio"— y ahi el administrador no hace
 * falta. `tipo` ademas la lee el sistema legacy, que si reparte permisos con
 * ella, asi que poner `A` desde aca le abriria al invitado el back office viejo
 * ENTERO sin que nadie lo haya decidido.
 *
 * LOS TRES PERMISOS (`operacion` / `invitacion` / `facturacion`, migracion
 * 20260907_1000) son banderas `habilitado`: se escriben con las constantes de
 * lib/habilitado.php y nunca con un literal ni con un booleano de PHP (PDO manda
 * el `false` como cadena vacia). Los dos primeros nacen en 1 porque un perfil
 * sin ellos no podria ni usar la app ni invitar a nadie —el mismo criterio con
 * el que la migracion sembro las filas que ya existian—; `facturacion` nace en 0
 * porque este alta corre SIN sesion (la credencial es el uuid del enlace) y es
 * el unico permiso que solo puede otorgar quien ya lo tiene: con 1, invitarse a
 * si mismo seria la puerta trasera para fabricarselo.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/habilitado.php';

/**
 * `perfiles`.`tipo` es `ENUM('A','O') NOT NULL DEFAULT 'O'`: dos letras y nada
 * mas. Se escribe con estas constantes, nunca con la letra suelta. Son las
 * mismas que declara panel/lib/acceso.php — duplicadas porque las tres apps no
 * comparten docroot.
 */
const PERFIL_TIPO_ADMINISTRADOR = 'A';
const PERFIL_TIPO_OPERADOR      = 'O';

/**
 * Perfil del usuario en el dominio, creandolo si no tenia ninguno.
 *
 * SI YA TIENE UN PERFIL HABILITADO EN ESE DOMINIO, NO SE TOCA NADA y se
 * devuelve el que ya estaba — cualquiera sea su `tipo`. Es lo que corresponde:
 * lo que la invitacion promete es acceso al dominio, y esa persona ya lo tiene.
 * Crearle un segundo perfil le duplicaria la fila en el listado de Usuarios del
 * panel sin darle nada nuevo, y reescribirle el que tiene le podria CAMBIAR el
 * acceso en otros lados (un Administrador pasaria a Operador y perderia el
 * panel, y el legacy lee la misma columna).
 *
 * Es la unica diferencia real con `perfilAsegurado()` del panel, que si busca
 * un perfil de Administrador y agrega uno cuando el que hay es de Operador:
 * alla el `tipo` decide si la persona entra, aca no decide nada.
 *
 * `panel` queda en NULL y no en 0 — con las FK declaradas, el 0 del sistema
 * viejo ya no es un valor valido (ver db/schema.sql).
 *
 * `$registranteId` es el EMISOR de la invitacion, y va a `perfiles`.`registrante`
 * (migracion 20260907_1100): quien otorgo este acceso. Se escribe SOLO cuando el
 * perfil se crea. En el tercer caso —la persona ya tenia un perfil habilitado en
 * el dominio— no se toca nada, tampoco esta columna: ese acceso lo otorgo otro y
 * la invitacion no lo esta creando.
 */
function appPerfilAsegurado(PDO $pdo, int $usuarioId, int $dominioId, string $dominioNombre, int $registranteId = 0): int
{
    $busca = $pdo->prepare(
        'SELECT id FROM perfiles
          WHERE usuario = :u AND dominio = :d AND habilitado = :hab
          ORDER BY id LIMIT 1'
    );
    $busca->execute([':u' => $usuarioId, ':d' => $dominioId, ':hab' => HABILITADO]);
    $id = (int) ($busca->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }

    $alta = $pdo->prepare(
        'INSERT INTO perfiles (uuid, nombre, usuario, dominio, tipo,
                               operacion, invitacion, facturacion,
                               registrante, habilitado)
         VALUES (:uuid, :nombre, :usuario, :dominio, :tipo,
                 :operacion, :invitacion, :facturacion,
                 :registrante, :habilitado)'
    );
    $alta->execute([
        ':uuid'        => bin2hex(random_bytes(8)),
        ':nombre'      => mb_substr('Operador en ' . $dominioNombre, 0, 255),
        ':usuario'     => $usuarioId,
        ':dominio'     => $dominioId,
        // Ver la cabecera: la app da de alta OPERADORES.
        ':tipo'        => PERFIL_TIPO_OPERADOR,
        ':operacion'   => HABILITADO,
        ':invitacion'  => HABILITADO,
        ':facturacion' => DESHABILITADO,
        // QUIEN OTORGO ESTE ACCESO: el emisor de la invitacion. `?: null` y no
        // el entero pelado porque la columna es una FK y el `0` del sistema
        // historico ya no es un valor valido — mismo criterio con el que
        // `usuarioAlta()` escribe `usuarios`.`registrante`. Una invitacion sin
        // emisor deja el perfil con NULL, que se lee como "no se sabe"
        // (ver 20260907_1100).
        ':registrante' => $registranteId ?: null,
        // El perfil nace habilitado. La columna es tinyint(1) NOT NULL y su
        // unico otro valor es 0 (lib/habilitado.php).
        ':habilitado'  => HABILITADO,
    ]);

    $perfilId = (int) $pdo->lastInsertId();
    appPerfilPanelesDelDominio($pdo, $perfilId, $dominioId);

    return $perfilId;
}

/**
 * Le da al perfil acceso a TODOS los paneles habilitados de su dominio.
 *
 * NO ES UN EXTRA, ES OBLIGATORIO. `perfiles_paneles` no tiene fallback: un
 * perfil sin filas no ve NINGUN panel (`appPanelesPermitidos()` en
 * lib/contexto.php), asi que sin esto la persona aceptaria la invitacion,
 * recibiria sus credenciales y entraria a una app vacia — y encima con el
 * permiso `operacion` en 1, o sea sin ninguna pista de por que no ve nada.
 *
 * Es la misma regla con la que la migracion `20260906_1700` sembro los 2225
 * perfiles que ya existian, el mismo default que pre-tilda el alta de cloud y lo
 * mismo que hace `panel/invitacion/aceptar.php`.
 *
 * El `INSERT ... SELECT` con `dominio = :d` en las dos puntas es lo que impide
 * que se cuele un panel de otro dominio; el `IGNORE` se apoya en el UNIQUE
 * `(perfil, panel)` para que reintentar no duplique filas.
 */
function appPerfilPanelesDelDominio(PDO $pdo, int $perfilId, int $dominioId): void
{
    $pdo->prepare(
        'INSERT IGNORE INTO perfiles_paneles (perfil, panel, asignado)
         SELECT :perfil, pa.id, NOW()
           FROM paneles pa
          WHERE pa.dominio = :d AND pa.habilitado = 1'
    )->execute([':perfil' => $perfilId, ':d' => $dominioId]);
}
