<?php

declare(strict_types=1);

/**
 * La red de instaladores: el listado público y el alta desde el formulario de
 * registro.
 *
 * DOS COLUMNAS GOBIERNAN LA PUBLICACIÓN y hacen falta las dos. De las 92 filas
 * de la tabla, sólo 12 se muestran:
 *
 *   aprobacion  '1' recién registrado, todavía sin revisar  (73 filas)
 *               '2' aprobado por el equipo                  (19 filas)
 *   visibilidad '1' se muestra en el sitio                  (12 filas)
 *               '0' no se muestra
 *
 * O sea que hay 7 aprobados que NO se publican: la aprobación habilita al
 * instalador (cédula, dominios) y la visibilidad decide si además sale en la
 * vidriera. Filtrar por una sola de las dos publicaría gente que pidió no
 * aparecer.
 *
 * NINGUNA DE LAS DOS ES LA BANDERA `habilitado`. Son `varchar(1)` con valores
 * `'1'` / `'2'` / `'0'`, así que van comparadas contra la CADENA: la regla del
 * CLAUDE.md sobre `habilitado` (tinyint, 0/1, entero en el SQL) no aplica acá.
 * `aprobacion` ni siquiera es booleana.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/prospectos.php';

/** Avatar genérico: la tabla no guarda foto de nadie. */
const INSTALADORES_AVATAR = 'https://media.reactor.com.ar/instaladores/anonimo.png';

/** Los instaladores que salen publicados, ordenados por nombre. */
function instaladoresListar(): array
{
    $sql = db()->prepare(
        'SELECT id, uuid, nombre, actividad, celular, correo,
                localidad_, provincia_, pais_
           FROM instaladores
          WHERE aprobacion = :apr AND visibilidad = :vis
          ORDER BY nombre'
    );
    $sql->execute([':apr' => '2', ':vis' => '1']);

    return $sql->fetchAll();
}

/** Un instalador publicado por su uuid, o null. */
function instaladorPorUuid(string $uuid): ?array
{
    $uuid = trim($uuid);
    if ($uuid === '') {
        return null;
    }

    $sql = db()->prepare(
        'SELECT id, uuid, nombre, actividad, celular, correo,
                localidad_, provincia_, pais_, registrado
           FROM instaladores
          WHERE uuid = :uuid AND aprobacion = :apr AND visibilidad = :vis
          LIMIT 1'
    );
    $sql->execute([':uuid' => $uuid, ':apr' => '2', ':vis' => '1']);

    $fila = $sql->fetch();

    return $fila === false ? null : $fila;
}

/**
 * Número listo para un enlace de WhatsApp (`wa.me/<numero>`).
 *
 * wa.me EXIGE el número internacional completo y sin signos. En la tabla hay de
 * todo: 85 filas con los 10 dígitos argentinos sin característica de país
 * (`2644711573`) y unas pocas ya en internacional (`+5491162888526`). El legacy
 * las metía crudas en la URL, así que los enlaces de las 85 no abrían nada —
 * `wa.me/2644711573` no resuelve a ningún teléfono.
 *
 * Acá se completa: 10 dígitos son un celular argentino y les corresponde el
 * prefijo `549`. Lo que ya venga más largo se deja como está, sólo sin signos.
 * Devuelve '' si no queda un número usable, para que quien dibuja omita el
 * enlace en vez de publicar uno roto.
 */
function instaladorWhatsapp(?string $celular): string
{
    $digitos = preg_replace('/\D+/', '', (string) $celular) ?? '';

    if (strlen($digitos) === 10) {
        return '549' . $digitos;
    }

    // Menos de 10 dígitos no es un celular; más, ya trae el país adelante.
    return strlen($digitos) >= 11 ? $digitos : '';
}

/**
 * Ubicación legible de un instalador, con las partes que estén cargadas.
 *
 * Las columnas con guion bajo al final (`localidad_`, `provincia_`, `pais_`)
 * son el TEXTO; las de nombre limpio guardan el id del catálogo. En esta tabla
 * casi todas están vacías, así que la función suele devolver ''.
 */
function instaladorUbicacion(array $instalador): string
{
    $partes = [];
    foreach (['localidad_', 'provincia_', 'pais_'] as $campo) {
        $valor = trim((string) ($instalador[$campo] ?? ''));
        if ($valor !== '') {
            $partes[] = $valor;
        }
    }

    return implode(', ', $partes);
}

/**
 * Valida el formulario de registro.
 *
 * @return array<string,string> Campo -> mensaje. Vacío si está todo bien.
 */
function instaladorValidar(array $datos): array
{
    $errores = [];

    $nombre = trim((string) ($datos['nombre'] ?? ''));
    if ($nombre === '') {
        $errores['nombre'] = 'Ingresá tu nombre.';
    } elseif (mb_strlen($nombre) > 255) {
        $errores['nombre'] = 'El nombre es demasiado largo.';
    }

    $correo = trim((string) ($datos['correo'] ?? ''));
    if ($correo === '') {
        $errores['correo'] = 'Ingresá tu correo.';
    } elseif (filter_var($correo, FILTER_VALIDATE_EMAIL) === false || mb_strlen($correo) > 255) {
        $errores['correo'] = 'Ese correo no parece válido.';
    }

    // El celular es opcional, pero si lo cargan tiene que ser el formato del
    // resto del repo: EXACTAMENTE 10 DÍGITOS Y NADA MÁS — sin el 0 de la
    // característica, sin el 15 y sin el +54 (`2644123456`). Es la misma regla
    // que documenta el CLAUDE.md para `usuarios`.`celular`, y acá también es lo
    // que tiene la enorme mayoría de los datos: 85 de las 92 filas.
    //
    // Se valida con ctype_digit() y no con una expresión regular porque
    // `/^[0-9]+$/` da por buena una cadena terminada en salto de línea.
    $celular = trim((string) ($datos['celular'] ?? ''));
    if ($celular !== '' && (!ctype_digit($celular) || strlen($celular) !== CELULAR_DIGITOS)) {
        $errores['celular'] = 'El celular tiene que ser de ' . CELULAR_DIGITOS . ' dígitos, sin 0, sin 15 y sin +54. Ejemplo: 2644123456.';
    }

    if (mb_strlen(trim((string) ($datos['actividad'] ?? ''))) > 255) {
        $errores['actividad'] = 'La actividad es demasiado larga.';
    }

    return $errores;
}

/**
 * Largo del celular. Declarada acá y no incluida de otra app porque las cuatro
 * no comparten docroot, igual que las dos copias que ya viven en los
 * `invitacion/aceptar.php` de `panel/` y `app/`.
 */
const CELULAR_DIGITOS = 10;

/**
 * Da de alta la solicitud.
 *
 * NACE SIN APROBAR Y SIN PUBLICAR (`aprobacion = '1'`, `visibilidad = '0'`).
 * Es un formulario abierto en internet: si el alta naciera visible, cualquiera
 * se publicaría solo en la vidriera de instaladores certificados de Reactor.
 * Los dos estados los sube a mano el equipo desde el back office.
 *
 * El alta local es la que manda; el CRM es un agregado. Si el microservicio
 * falla, la solicitud YA quedó guardada y la persona no tiene que completar el
 * formulario de nuevo — se registra el error en el log y listo.
 *
 * @return array{ok:bool,error:?string}
 */
function instaladorAlta(array $datos): array
{
    $nombre    = trim((string) ($datos['nombre'] ?? ''));
    $correo    = trim((string) ($datos['correo'] ?? ''));
    $celular   = trim((string) ($datos['celular'] ?? ''));
    $actividad = trim((string) ($datos['actividad'] ?? ''));

    try {
        $sql = db()->prepare(
            'INSERT INTO instaladores
                (uuid, nombre, actividad, celular, correo, registrado, aprobacion, visibilidad)
             VALUES
                (:uuid, :nombre, :actividad, :celular, :correo, NOW(), :apr, :vis)'
        );
        $sql->execute([
            ':uuid'      => instaladorUuid(),
            ':nombre'    => $nombre,
            ':actividad' => $actividad,
            ':celular'   => $celular,
            ':correo'    => $correo,
            ':apr'       => '1',
            ':vis'       => '0',
        ]);
    } catch (Throwable $e) {
        error_log('[instaladores] no se pudo registrar la solicitud: ' . $e->getMessage());

        return ['ok' => false, 'error' => 'No pudimos registrar tu solicitud. Intentalo de nuevo en un rato.'];
    }

    $crm = prospectoRegistrar([
        'nombre'  => $nombre,
        'correo'  => $correo,
        'celular' => $celular,
        'asunto'  => 'Registro de Instalador',
        'mensaje' => 'Solicitud de registro de instalador.' . PHP_EOL
            . 'Nombre: ' . $nombre . PHP_EOL
            . 'Actividad: ' . ($actividad !== '' ? $actividad : '(no informada)'),
    ]);
    if (!$crm['ok']) {
        error_log('[instaladores] la solicitud quedo guardada pero no entro al CRM: ' . (string) $crm['error']);
    }

    return ['ok' => true, 'error' => null];
}

/**
 * Identificador público de un instalador: 16 caracteres en mayúsculas, el
 * mismo formato que traen las filas históricas (`B30LXBXZEZB9PAFI`).
 *
 * Sale de `random_bytes()` y no de `rand()`: es lo que va en la URL de la ficha
 * pública, así que tiene que ser imposible de enumerar.
 */
function instaladorUuid(): string
{
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $uuid     = '';
    foreach (unpack('C*', random_bytes(16)) ?: [] as $byte) {
        $uuid .= $alfabeto[$byte % strlen($alfabeto)];
    }

    return $uuid;
}
