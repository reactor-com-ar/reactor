<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/**
 * ABM de `tecnicos`: la red de tecnicos instaladores que publica el sitio
 * publico (`www/tecnicos/`, ver www/CLAUDE.md).
 *
 * ES EL LADO DE ESCRITURA DE UNA TABLA QUE HASTA HOY SOLO ESCRIBIA EL LEGACY.
 * `www` inserta solicitudes desde un formulario abierto en internet y nunca
 * las aprueba; aprobarlas y publicarlas pasaba por el back office viejo, que
 * esta fuera de este repo. Este endpoint es lo que reemplaza a ese modulo.
 *
 * DOS COLUMNAS GOBIERNAN LA PUBLICACION Y HACEN FALTA LAS DOS:
 *
 *   aprobacion  '1' registrado, sin revisar   (76 filas en dev)
 *               '2' aprobado por el equipo    (19)
 *   visibilidad '1' se muestra en el sitio    (12)
 *               '0' no se muestra             (83)
 *
 * Un tecnico sale publicado solo con `aprobacion = '2'` Y `visibilidad = '1'`:
 * hay 7 aprobados que no se publican porque la aprobacion lo habilita como
 * tecnico y la visibilidad decide si ademas sale en la vidriera. Filtrar por
 * una sola publicaria gente que pidio no aparecer.
 *
 * NINGUNA DE LAS DOS ES LA BANDERA `habilitado` del repo: son `varchar(1)`, van
 * comparadas contra la CADENA y `aprobacion` ni siquiera es booleana. No se
 * tocan con esHabilitado() / valorHabilitado().
 */

const TECNICO_APROBACION_SIN_REVISAR = '1';
const TECNICO_APROBACION_APROBADO    = '2';
const TECNICO_VISIBILIDAD_OCULTO     = '0';
const TECNICO_VISIBILIDAD_PUBLICADO  = '1';

/** Largo del celular argentino sin el 0, sin el 15 y sin el +54 (`2644123456`). */
const TECNICO_CELULAR_DIGITOS = 10;

/** Columnas de la tabla, en el orden del esquema. */
const TECNICO_COLUMNAS = 'id, uuid, nombre, actividad, celular, correo, domicilio, postal,
                          localidad, localidad_, provincia, provincia_, pais, pais_,
                          registrado, aprobacion, visibilidad';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':    handleList();   break;
        case 'POST':   handleCreate(); break;
        case 'PUT':    handleUpdate(); break;
        case 'DELETE': handleDelete(); break;
        default:
            json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar tecnicos: ' . $e->getMessage(), 500);
}

/**
 * Listado completo + catalogos de ubicacion.
 *
 * Se trae la tabla entera y se filtra en el navegador (ABM.md): son 95 filas y
 * crecen de a una por solicitud del formulario publico. Los catalogos viajan en
 * el mismo GET porque los necesitan los tres selects del modal de Alta/Edicion
 * y el de Filtros; son 6 + 30 + 562 filas de dos columnas.
 */
function handleList(): void
{
    $stmt = db()->query(
        'SELECT ' . TECNICO_COLUMNAS . '
           FROM tecnicos
          ORDER BY id DESC'
    );

    $tecnicos = array_map(static function (array $r): array {
        $r['id']        = (int) $r['id'];
        $r['publicado'] = tecnicoPublicado($r);
        return $r;
    }, $stmt->fetchAll());

    $resumen = [
        'total'       => count($tecnicos),
        'aprobados'   => 0,
        'sin_revisar' => 0,
        'publicados'  => 0,
    ];
    foreach ($tecnicos as $t) {
        if ((string) $t['aprobacion'] === TECNICO_APROBACION_APROBADO)    $resumen['aprobados']++;
        if ((string) $t['aprobacion'] === TECNICO_APROBACION_SIN_REVISAR) $resumen['sin_revisar']++;
        if ($t['publicado'])                                              $resumen['publicados']++;
    }

    json_ok([
        'resumen'   => $resumen,
        'tecnicos'  => $tecnicos,
        'catalogos' => [
            'paises'      => db()->query(
                'SELECT id, nombre FROM paises ORDER BY nombre'
            )->fetchAll(),
            'provincias'  => db()->query(
                'SELECT id, pais, nombre FROM provincias ORDER BY nombre'
            )->fetchAll(),
            'localidades' => db()->query(
                'SELECT id, pais, provincia, nombre FROM localidades ORDER BY nombre'
            )->fetchAll(),
        ],
    ]);
}

/**
 * Un tecnico esta publicado solo si tiene las DOS columnas puestas.
 *
 * La condicion vive en esta funcion y no repetida en cada consulta: el listado,
 * el resumen y el mensaje de la baja preguntan por la misma via (ABM.md).
 */
function tecnicoPublicado(array $fila): bool
{
    return (string) ($fila['aprobacion']  ?? '') === TECNICO_APROBACION_APROBADO
        && (string) ($fila['visibilidad'] ?? '') === TECNICO_VISIBILIDAD_PUBLICADO;
}

function handleCreate(): void
{
    $data = validateTecnicoPayload(readJson(), null);

    $stmt = db()->prepare(
        'INSERT INTO tecnicos
            (uuid, nombre, actividad, celular, correo, domicilio, postal,
             localidad, localidad_, provincia, provincia_, pais, pais_,
             registrado, aprobacion, visibilidad)
         VALUES
            (:uuid, :nombre, :actividad, :celular, :correo, :domicilio, :postal,
             :localidad, :localidad_txt, :provincia, :provincia_txt, :pais, :pais_txt,
             NOW(), :aprobacion, :visibilidad)'
    );
    $stmt->execute([
        ':uuid'          => tecnicoUuidLibre(),
        ':nombre'        => $data['nombre'],
        ':actividad'     => $data['actividad'],
        ':celular'       => $data['celular'],
        ':correo'        => $data['correo'],
        ':domicilio'     => $data['domicilio'],
        ':postal'        => $data['postal'],
        ':localidad'     => $data['localidad'],
        ':localidad_txt' => $data['localidad_'],
        ':provincia'     => $data['provincia'],
        ':provincia_txt' => $data['provincia_'],
        ':pais'          => $data['pais'],
        ':pais_txt'      => $data['pais_'],
        ':aprobacion'    => $data['aprobacion'],
        ':visibilidad'   => $data['visibilidad'],
    ]);

    json_ok(['id' => (int) db()->lastInsertId()], 201);
}

function handleUpdate(): void
{
    $in = readJson();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $actual = tecnicoPorId($id);
    if ($actual === null) json_error('Tecnico no encontrado', 404);

    $data = validateTecnicoPayload($in, $actual);

    // `uuid` y `registrado` no se editan: son el identificador publico de la
    // ficha (la URL que el tecnico ya le paso a sus clientes) y la fecha del
    // alta. ABM.md los deja fuera del formulario y los muestra igual en
    // Consultar: la restriccion es de escritura, no de lectura.
    $stmt = db()->prepare(
        'UPDATE tecnicos
            SET nombre      = :nombre,
                actividad   = :actividad,
                celular     = :celular,
                correo      = :correo,
                domicilio   = :domicilio,
                postal      = :postal,
                localidad   = :localidad,
                localidad_  = :localidad_txt,
                provincia   = :provincia,
                provincia_  = :provincia_txt,
                pais        = :pais,
                pais_       = :pais_txt,
                aprobacion  = :aprobacion,
                visibilidad = :visibilidad
          WHERE id = :id'
    );
    $stmt->execute([
        ':nombre'        => $data['nombre'],
        ':actividad'     => $data['actividad'],
        ':celular'       => $data['celular'],
        ':correo'        => $data['correo'],
        ':domicilio'     => $data['domicilio'],
        ':postal'        => $data['postal'],
        ':localidad'     => $data['localidad'],
        ':localidad_txt' => $data['localidad_'],
        ':provincia'     => $data['provincia'],
        ':provincia_txt' => $data['provincia_'],
        ':pais'          => $data['pais'],
        ':pais_txt'      => $data['pais_'],
        ':aprobacion'    => $data['aprobacion'],
        ':visibilidad'   => $data['visibilidad'],
        ':id'            => $id,
    ]);

    json_ok(['id' => $id]);
}

/**
 * Baja directa: `tecnicos` es una isla del esquema —ninguna FK apunta a ella—,
 * asi que no hay nada que arrastrar y alcanza el confirmDialog estandar del
 * front (ABM.md). Lo unico que se lleva es la ficha publica: la URL
 * `/tecnicos/consultar?uid=<uuid>` deja de resolver, y eso lo avisa el front.
 */
function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('DELETE FROM tecnicos WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) json_error('Tecnico no encontrado', 404);

    json_ok(['id' => $id]);
}

function tecnicoPorId(int $id): ?array
{
    $stmt = db()->prepare('SELECT ' . TECNICO_COLUMNAS . ' FROM tecnicos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();

    return $fila === false ? null : $fila;
}

/**
 * Valida el payload del alta y de la edicion.
 *
 * `$actual` es la fila tal como esta en la base (null en el alta). Sirve para
 * la regla de VALOR HEREDADO: lo que ya estaba guardado se acepta aunque hoy no
 * pase la validacion, y el criterio nuevo se exige solo para valores nuevos. Es
 * el mismo patron con el que Talonarios acepta un `tipo` fuera de catalogo
 * (ABM.md): si no, editarle el nombre a una fila vieja quedaria bloqueado por
 * un celular que alguien cargo en 2019.
 */
function validateTecnicoPayload(array $in, ?array $actual): array
{
    $nombre     = trim((string) ($in['nombre']     ?? ''));
    $actividad  = trim((string) ($in['actividad']  ?? ''));
    $celular    = trim((string) ($in['celular']    ?? ''));
    $correo     = trim((string) ($in['correo']     ?? ''));
    $domicilio  = trim((string) ($in['domicilio']  ?? ''));
    $postal     = trim((string) ($in['postal']     ?? ''));
    $localidadT = trim((string) ($in['localidad_'] ?? ''));
    $provinciaT = trim((string) ($in['provincia_'] ?? ''));
    $paisT      = trim((string) ($in['pais_']      ?? ''));

    if ($nombre === '')            json_error('El nombre es obligatorio', 422);
    if (mb_strlen($nombre)    > 255) json_error('El nombre no puede superar 255 caracteres', 422);
    if (mb_strlen($actividad) > 255) json_error('La actividad no puede superar 255 caracteres', 422);
    if (mb_strlen($domicilio) > 255) json_error('El domicilio no puede superar 255 caracteres', 422);
    if (mb_strlen($postal)    > 10)  json_error('El codigo postal no puede superar 10 caracteres', 422);
    if (mb_strlen($localidadT) > 255) json_error('La localidad no puede superar 255 caracteres', 422);
    if (mb_strlen($provinciaT) > 255) json_error('La provincia no puede superar 255 caracteres', 422);
    if (mb_strlen($paisT)      > 255) json_error('El pais no puede superar 255 caracteres', 422);

    // El correo NO es obligatorio, a diferencia del formulario publico: acá se
    // editan las 95 filas que ya existen y una no tiene correo cargado.
    // Exigirlo dejaria esa fila sin poder corregirse por un dato que el alta
    // vieja nunca pidio. Lo que si se exige es que lo que llegue sea un correo.
    if ($correo !== '') {
        if (mb_strlen($correo) > 255) json_error('El correo no puede superar 255 caracteres', 422);
        if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            json_error('Ese correo no parece valido', 422);
        }
    }

    // Celular: EXACTAMENTE 10 digitos y nada mas (CLAUDE.md). Se valida con
    // ctype_digit() y no con una expresion regular porque `/^[0-9]+$/` da por
    // buena una cadena terminada en salto de linea. Lo que ya estaba guardado
    // pasa igual: 6 de las 95 filas traen el numero en formato internacional
    // (`5491162888526`), que es un numero valido cargado con otro criterio, no
    // un error que haya que corregir a la fuerza para poder tocar el nombre.
    $celularHeredado = (string) ($actual['celular'] ?? '');
    if ($celular !== '' && $celular !== $celularHeredado
        && (!ctype_digit($celular) || strlen($celular) !== TECNICO_CELULAR_DIGITOS)) {
        json_error(
            'El celular tiene que ser de ' . TECNICO_CELULAR_DIGITOS
            . ' digitos, sin 0, sin 15 y sin +54. Ejemplo: 2644123456.',
            422
        );
    }

    $aprobacion  = tecnicoEstadoValidado(
        (string) ($in['aprobacion'] ?? TECNICO_APROBACION_SIN_REVISAR),
        [TECNICO_APROBACION_SIN_REVISAR, TECNICO_APROBACION_APROBADO],
        (string) ($actual['aprobacion'] ?? ''),
        'La aprobacion solo puede ser 1 (sin revisar) o 2 (aprobado)'
    );
    $visibilidad = tecnicoEstadoValidado(
        (string) ($in['visibilidad'] ?? TECNICO_VISIBILIDAD_OCULTO),
        [TECNICO_VISIBILIDAD_OCULTO, TECNICO_VISIBILIDAD_PUBLICADO],
        (string) ($actual['visibilidad'] ?? ''),
        'La visibilidad solo puede ser 0 (oculto) o 1 (publicado)'
    );

    // Las tres columnas de catalogo (`pais`, `provincia`, `localidad`) guardan
    // el id en un varchar(10) y estan casi siempre vacias: hoy `localidad` lo
    // esta en las 95 filas. Se validan contra la tabla del catalogo y ademas
    // contra su padre, para que no quede una provincia de un pais que no es.
    $pais      = tecnicoCatalogoValidado($in, $actual, 'pais',      'paises',      null,        null,   'El pais elegido no existe');
    $provincia = tecnicoCatalogoValidado($in, $actual, 'provincia', 'provincias',  'pais',      $pais,  'La provincia elegida no existe o no es de ese pais');
    $localidad = tecnicoCatalogoValidado($in, $actual, 'localidad', 'localidades', 'provincia', $provincia, 'La localidad elegida no existe o no es de esa provincia');

    return [
        'nombre'      => $nombre,
        'actividad'   => $actividad,
        'celular'     => $celular,
        'correo'      => $correo,
        'domicilio'   => $domicilio     === '' ? null : $domicilio,
        'postal'      => $postal        === '' ? null : $postal,
        'localidad'   => $localidad,
        'localidad_'  => $localidadT    === '' ? null : $localidadT,
        'provincia'   => $provincia,
        'provincia_'  => $provinciaT    === '' ? null : $provinciaT,
        'pais'        => $pais,
        'pais_'       => $paisT         === '' ? null : $paisT,
        'aprobacion'  => $aprobacion,
        'visibilidad' => $visibilidad,
    ];
}

/**
 * Valida una de las dos columnas de estado contra su lista de valores, dejando
 * pasar el valor heredado. Ninguna fila de dev tiene hoy un valor fuera de
 * lista, pero el esquema es un `varchar(1)` sin ENUM ni CHECK: si el legacy
 * escribiera un tercer codigo, guardar desde acá lo pisaria en silencio.
 */
function tecnicoEstadoValidado(string $valor, array $validos, string $heredado, string $error): string
{
    $aceptado = in_array($valor, $validos, true)
        || ($heredado !== '' && $valor === $heredado);

    if (!$aceptado) json_error($error, 422);

    return $valor;
}

/**
 * Valida un id de catalogo: tiene que ser numerico, existir en su tabla y —si
 * corresponde— colgar del padre elegido. '' / ausente significa "sin asignar" y
 * se guarda como NULL.
 *
 * EL VALOR HEREDADO PASA, PERO SOLO MIENTRAS NADIE TOQUE EL PADRE. La fila 610
 * trae `provincia = '70'` cargada por el legacy y no hay por que revalidarla
 * para editarle el nombre; pero si el operador cambia el pais a Chile, esa
 * provincia dejo de colgar de lo elegido y guardarla armaria un par que no
 * existe. La exencion es "lo que ya estaba, tal como estaba", no "esta columna
 * no se valida".
 *
 * `$columnaPadre` nombra a la vez la columna del catalogo y el campo de
 * `tecnicos` que guarda ese padre (`pais` en `provincias`, `provincia` en
 * `localidades`): son el mismo nombre en las dos tablas.
 */
function tecnicoCatalogoValidado(
    array $in,
    ?array $actual,
    string $campo,
    string $tabla,
    ?string $columnaPadre,
    ?string $padreElegido,
    string $error
): ?string {
    $valor = trim((string) ($in[$campo] ?? ''));
    if ($valor === '') return null;

    $heredado = $valor === trim((string) ($actual[$campo] ?? ''));
    // El padre sigue siendo el que la fila tenia, asi que el par completo es el
    // que ya estaba guardado y no hay nada nuevo que validar.
    $parHeredado = $heredado
        && ($columnaPadre === null
            || (string) ($padreElegido ?? '') === trim((string) ($actual[$columnaPadre] ?? '')));

    if ($parHeredado) return $valor;
    if ($heredado && !ctype_digit($valor)) return $valor;

    if (!ctype_digit($valor) || strlen($valor) > 10) json_error($error, 422);

    // Los nombres de tabla y columna son literales de este archivo, nunca del
    // request: el request solo aporta el valor, que va bindeado.
    $columna = $columnaPadre ?? 'id';
    $stmt    = db()->prepare("SELECT $columna FROM $tabla WHERE id = :id");
    $stmt->execute([':id' => (int) $valor]);
    $fila = $stmt->fetch();

    // Un id que ya no esta en su catalogo se conserva si es el que la fila
    // traia: borrarlo seria perder el dato por una fila de catalogo que alguien
    // elimino despues.
    if ($fila === false) {
        if ($heredado) return $valor;
        json_error($error, 422);
    }

    if ($columnaPadre !== null && $padreElegido !== null
        && (string) ($fila[$columnaPadre] ?? '') !== $padreElegido) {
        json_error($error, 422);
    }

    return $valor;
}

/**
 * Identificador publico del tecnico: 16 caracteres en mayusculas, el mismo
 * formato y el mismo alfabeto que genera el alta del sitio publico
 * (`tecnicoUuid()` en www/lib/tecnicos.php) y que traen las 95 filas.
 *
 * Sale de random_bytes() y no de rand(): es lo que va en la URL de la ficha, asi
 * que tiene que ser imposible de enumerar. La tabla NO tiene UNIQUE en `uuid` y
 * `tecnicoPorUuid()` resuelve con LIMIT 1, asi que un duplicado le robaria la
 * ficha al otro: se consulta antes de usarlo.
 */
function tecnicoUuidLibre(): string
{
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $stmt     = db()->prepare('SELECT 1 FROM tecnicos WHERE uuid = :uuid');
    $uuid     = '';

    for ($intento = 0; $intento < 5 && $uuid === ''; $intento++) {
        $candidato = '';
        foreach (unpack('C*', random_bytes(16)) ?: [] as $byte) {
            $candidato .= $alfabeto[$byte % strlen($alfabeto)];
        }
        $stmt->execute([':uuid' => $candidato]);
        if (!$stmt->fetchColumn()) $uuid = $candidato;
    }

    if ($uuid === '') json_error('No se pudo generar el identificador del tecnico', 500);

    return $uuid;
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Body JSON invalido', 400);

    return $data;
}
