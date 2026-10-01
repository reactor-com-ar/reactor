<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/**
 * ABM de `clientes` (db/schema.sql).
 *
 * Un cliente es A QUIEN SE LE FACTURA: la razon social, el domicilio, la
 * condicion frente al IVA y el CUIT que se imprimen en cada comprobante, mas el
 * talonario del que sale su numeracion y el medio con el que paga. Las tres FK
 * que apuntan aca -- `dominios`.`cliente`, `contratos`.`cliente` y
 * `comprobantes`.`cliente` -- son RESTRICT.
 *
 * TODAS las columnas son editables menos `id`, que es el AUTO_INCREMENT.
 *
 * EL CLIENTE QUE FACTURA SALE DEL DOMINIO, NO DEL CONTRATO. Lo resuelve asi
 * `facturarContexto()` en `contratos_facturar_lib.php`, igual que el legacy:
 * primero el dominio del contrato y de ahi su cliente. Por eso este modulo
 * cuenta las tres cosas por separado -- dominios, contratos y comprobantes -- en
 * vez de un solo contador: son tres vinculos distintos y hoy no coinciden (61
 * clientes con dominio, 42 con contrato, 35 con comprobantes).
 *
 * LOS LARGOS SALEN DEL COMPROBANTE, NO DE `clientes`. Seis columnas se COPIAN
 * al comprobante al facturar y alla son mas cortas (`LARGO_CLIENTE` de
 * `contratos_facturar_lib.php`): guardar una razon social de 255 caracteres
 * seria guardar un cliente al que despues no se le puede facturar -- el job de
 * las 09:00 lo bloquea con "en el comprobante entran 250". Asi que el ABM valida
 * contra el MENOR de los dos largos y lo dice en el formulario. Ver LARGO.
 *
 * TRES FILAS NO RESPETAN EL FORMATO Y SE CONSERVAN. `cuit` tiene una con
 * guiones (`20-14078223-9`, cliente 134) y `celular` dos que no son diez digitos
 * (el 153 arrastra un caracter invisible U+202C, el 157 tiene once). Validar a
 * secas las dejaria imposibles de guardar -- nadie podria corregirles ni el
 * correo --, asi que el formato se exige SOLO cuando el valor CAMBIA: el que la
 * fila ya tenia se acepta tal cual. Es el mismo criterio con el que
 * `talonarios.php` acepta el `tipo = 'A'` heredado del talonario 35.
 *
 * Y ES LO CONTRARIO DE LO QUE HACE LA INVITACION, a proposito: alla el celular
 * son diez digitos y nada mas porque las 384 filas de `usuarios` y de
 * `invitaciones` ya lo cumplen (CLAUDE.md). Aca no, y una regla que la tabla no
 * cumple no normaliza datos: bloquea la pantalla.
 *
 * VACIO SE GUARDA COMO CADENA VACIA, NO COMO NULL. Es lo que tienen 63 de las 64
 * filas en las diez columnas de texto -- hay un solo NULL, en `celular` y
 * `correo` de la misma fila --, asi que mandar NULL estrenaria una segunda forma
 * de decir lo mismo. Mismo criterio que `talonarios`.`terminos`. Las dos FK
 * (`talonario`, `medio`) si van a NULL: ahi el centinela `0` del sistema
 * historico no es un id (lo tiene una fila en `medio`).
 *
 * BORRAR. Las tres FK que apuntan aca son RESTRICT y las tres BLOQUEAN: no hay
 * nada que se borre en cascada ni que se desvincule solo. Hoy NINGUN cliente se
 * puede borrar -- los 64 tienen al menos un dominio, un contrato o un
 * comprobante --, y eso es lo correcto: lo que cuelga de un cliente son
 * comprobantes fiscales emitidos y dominios en produccion. El desglose lo sirve
 * `?impacto=1&id=N` y el DELETE repite las validaciones (ABM.md, "Eliminar").
 */

/** Clave de `combos` con los textos de la condicion frente al IVA. */
const COMBO_CONDICION = '$xCliente->condicion';

/** Ultimo recurso si `combos` no tiene cargada la clave. */
const CONDICIONES_FALLBACK = [
    'CF' => 'Consumidor Final',
    'RM' => 'Responsable Monotributo',
    'RI' => 'Responsable Inscripto',
    'EX' => 'Excento',
];

/**
 * Largo maximo de cada columna de texto, en caracteres.
 *
 * Es el MENOR entre lo que declara `clientes` y lo que declara `comprobantes`
 * para las seis columnas que se copian al facturar (`LARGO_CLIENTE` de
 * `contratos_facturar_lib.php`). Donde manda el comprobante:
 *
 *   razon / domicilio  255 en `clientes`, 250 en `comprobantes`  -> 250
 *   correo / celular   255 en `clientes`, 100 en `comprobantes`  -> 100
 *   condicion          255 en `clientes`,   2 en `comprobantes`  ->   2
 *   cuit                13 en `clientes`,  50 en `comprobantes`  ->  13
 *
 * Las otras cuatro (`nombre`, `localidad`, `provincia`, `pais`) no viajan al
 * comprobante, asi que mandan los 255 de su propia columna.
 */
const LARGO = [
    'nombre'    => 255,
    'razon'     => 250,
    'domicilio' => 250,
    'localidad' => 255,
    'provincia' => 255,
    'pais'      => 255,
    'contacto'  => 255,
    'celular'   => 100,
    'correo'    => 100,
    'condicion' => 2,
    'cuit'      => 13,
];

/** Digitos de un CUIT y de un celular argentino, cuando el valor es nuevo. */
const CUIT_DIGITOS    = 11;
const CELULAR_DIGITOS = 10;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['impacto'])) handleImpacto();
            else                         handleList();
            break;
        case 'POST':   handleCreate(); break;
        case 'PUT':    handleUpdate(); break;
        case 'DELETE': handleDelete(); break;
        default:
            json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar clientes: ' . $e->getMessage(), 500);
}

function handleList(): void
{
    // La fila entera, el nombre del talonario y del medio, y los tres
    // contadores que gobiernan el borrado. Subconsultas correlacionadas y no
    // LEFT JOIN: con JOINs las filas se multiplican entre si y cada COUNT
    // devolveria el producto (mismo criterio que api/talonarios.php). Las tres
    // columnas contadas tienen indice -- lo trae su FK.
    //
    // Busqueda por texto libre: la resuelve la BASE y no el navegador
    // (`ABM.md`, "Como busca el texto libre"). Las columnas son EXACTAMENTE las
    // que anuncia el placeholder del buscador.
    $q = trim((string) ($_GET['q'] ?? ''));
    [$condiciones, $busq] = busquedaWhere($q, [
        'c.nombre', 'c.razon', 'c.contacto', 'c.correo', 'c.cuit',
    ]);

    $stmt = db()->prepare(
        'SELECT c.id,
                c.nombre,
                c.domicilio,
                c.localidad,
                c.provincia,
                c.pais,
                c.contacto,
                c.celular,
                c.correo,
                c.razon,
                c.condicion,
                c.cuit,
                c.talonario,
                t.nombre AS talonario_nombre,
                t.estado AS talonario_estado,
                c.medio,
                m.nombre AS medio_nombre,
                m.estado AS medio_estado,
                (SELECT COUNT(*) FROM contratos    ct WHERE ct.cliente = c.id) AS contratos_count,
                (SELECT COUNT(*) FROM dominios     dm WHERE dm.cliente = c.id) AS dominios_count,
                (SELECT COUNT(*) FROM comprobantes cp WHERE cp.cliente = c.id) AS comprobantes_count
         FROM clientes c
         LEFT JOIN talonarios t ON t.id = c.talonario
         LEFT JOIN medios     m ON m.id = c.medio'
        . ($condiciones ? ' WHERE ' . implode(' AND ', $condiciones) : '') . '
         ORDER BY c.id DESC'
    );
    $stmt->execute($busq);

    $clientes = array_map(static function (array $r): array {
        $condicion = trim((string) ($r['condicion'] ?? ''));

        return [
            'id'                 => (int) $r['id'],
            'nombre'             => trim((string) ($r['nombre']    ?? '')),
            'domicilio'          => trim((string) ($r['domicilio'] ?? '')),
            'localidad'          => trim((string) ($r['localidad'] ?? '')),
            'provincia'          => trim((string) ($r['provincia'] ?? '')),
            'pais'               => trim((string) ($r['pais']      ?? '')),
            'contacto'           => trim((string) ($r['contacto']  ?? '')),
            'celular'            => trim((string) ($r['celular']   ?? '')),
            'correo'             => trim((string) ($r['correo']    ?? '')),
            'razon'              => trim((string) ($r['razon']     ?? '')),
            'condicion'          => $condicion,
            // El codigo corto va TRADUCIDO contra `combos`: un `CF` pelado en
            // la pantalla no dice nada (ABM.md, modal de Consultar).
            'condicion_texto'    => combo(COMBO_CONDICION)[$condicion] ?? '',
            'cuit'               => trim((string) ($r['cuit'] ?? '')),
            // El 0 de la FK es el centinela "sin asignar" del sistema
            // historico, no un id: se normaliza a null como el NULL real.
            'talonario'          => idOrNull($r['talonario']),
            'talonario_nombre'   => trim((string) ($r['talonario_nombre'] ?? '')),
            'talonario_estado'   => (int) ($r['talonario_estado'] ?? 0) === 1 ? 1 : 0,
            'medio'              => idOrNull($r['medio']),
            'medio_nombre'       => trim((string) ($r['medio_nombre'] ?? '')),
            'medio_estado'       => (int) ($r['medio_estado'] ?? 0) === 1 ? 1 : 0,
            'contratos_count'    => (int) $r['contratos_count'],
            'dominios_count'     => (int) $r['dominios_count'],
            'comprobantes_count' => (int) $r['comprobantes_count'],
        ];
    }, $stmt->fetchAll());

    // KPIs del universo (el front pide el listado SIN `q`, ver ABM.md). Los
    // tres vinculos van por separado porque no son el mismo vinculo, y
    // `sin_talonario` es el unico accionable: sin talonario no hay de donde
    // sacar la numeracion y `facturar` lo bloquea.
    $resumen = [
        'total'             => count($clientes),
        'con_dominios'      => 0,
        'con_contratos'     => 0,
        'con_comprobantes'  => 0,
        'sin_talonario'     => 0,
    ];
    foreach ($clientes as $c) {
        if ($c['dominios_count']     > 0) $resumen['con_dominios']++;
        if ($c['contratos_count']    > 0) $resumen['con_contratos']++;
        if ($c['comprobantes_count'] > 0) $resumen['con_comprobantes']++;
        if ($c['talonario'] === null)     $resumen['sin_talonario']++;
    }

    json_ok([
        'resumen'   => $resumen,
        'clientes'  => $clientes,
        'catalogos' => catalogos(),
    ]);
}

/** Catalogos de los desplegables del modal de Alta/Edicion y de Filtros. */
function catalogos(): array
{
    // Los dos desplegables listan TODO, no solo lo habilitado: hay clientes
    // colgados de un talonario o de un medio deshabilitado y, con el filtro
    // puesto, abrir uno y guardarlo lo dejaria sin talonario -- o sea sin
    // forma de facturarle. Es el mismo criterio con el que Contratos lista
    // todos los planes. El estado viaja aparte para marcarlo en la etiqueta.
    $talonarios = array_map(static fn(array $r): array => [
        'id'     => (int) $r['id'],
        'nombre' => trim((string) ($r['nombre'] ?? '')),
        'estado' => (int) ($r['estado'] ?? 0) === 1 ? 1 : 0,
    ], db()->query('SELECT id, nombre, estado FROM talonarios ORDER BY nombre ASC, id ASC')->fetchAll());

    $medios = array_map(static fn(array $r): array => [
        'id'     => (int) $r['id'],
        'nombre' => trim((string) ($r['nombre'] ?? '')),
        'estado' => (int) ($r['estado'] ?? 0) === 1 ? 1 : 0,
    ], db()->query('SELECT id, nombre, estado FROM medios ORDER BY nombre ASC, id ASC')->fetchAll());

    // Las provincias no son un catalogo: son los valores cargados. Se sirven
    // para que el filtro ofrezca lo que de verdad hay en la tabla, sin
    // inventar una lista de 24 que la base no conoce.
    $provincias = array_values(array_filter(array_map(
        static fn(array $r): string => trim((string) ($r['provincia'] ?? '')),
        db()->query('SELECT DISTINCT provincia FROM clientes ORDER BY provincia ASC')->fetchAll()
    ), static fn(string $p): bool => $p !== ''));

    return [
        'talonarios'  => $talonarios,
        'medios'      => $medios,
        'condiciones' => comboLista(COMBO_CONDICION),
        'provincias'  => $provincias,
        'largos'      => LARGO,
    ];
}

/**
 * Desglose del borrado (ABM.md, "Eliminar"; DESIGN.md §15.1).
 *
 * Las tres dependencias BLOQUEAN: son FK RESTRICT y ademas cosas que este
 * endpoint no tiene por que resolver solo -- dominios en produccion, contratos
 * vivos y comprobantes fiscales ya emitidos. No hay nada que se elimine en
 * cascada ni que se desvincule.
 */
function handleImpacto(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('SELECT id, nombre, razon FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $cli = $stmt->fetch();
    if (!$cli) json_error('Cliente no encontrado', 404);

    $deps = dependencias($id);

    $bloqueos = [];
    if ($deps['dominios'] > 0) {
        $bloqueos[] = ['label' => 'Dominios de este cliente', 'cantidad' => $deps['dominios']];
    }
    if ($deps['contratos'] > 0) {
        $bloqueos[] = ['label' => 'Contratos a nombre de este cliente', 'cantidad' => $deps['contratos']];
    }
    if ($deps['comprobantes'] > 0) {
        $bloqueos[] = ['label' => 'Comprobantes emitidos a este cliente', 'cantidad' => $deps['comprobantes']];
    }

    json_ok([
        'cliente' => [
            'id'     => (int) $cli['id'],
            'nombre' => trim((string) ($cli['nombre'] ?? '')),
            'razon'  => trim((string) ($cli['razon']  ?? '')),
        ],
        'bloqueos' => $bloqueos,
        // Las dos claves viajan vacias porque el modal compartido las lee: de
        // un cliente no cuelga nada que se borre ni que quede sin referencia.
        'elimina'    => [],
        'desvincula' => [],
    ]);
}

function handleCreate(): void
{
    $data = validarCliente(readJson(), null);

    $stmt = db()->prepare(
        'INSERT INTO clientes
             (nombre, domicilio, localidad, provincia, pais, contacto, celular,
              correo, razon, condicion, cuit, talonario, medio)
         VALUES
             (:nombre, :domicilio, :localidad, :provincia, :pais, :contacto, :celular,
              :correo, :razon, :condicion, :cuit, :talonario, :medio)'
    );
    $stmt->execute(bindCliente($data));

    json_ok(['id' => (int) db()->lastInsertId(), 'nombre' => $data['nombre']], 201);
}

function handleUpdate(): void
{
    $in = readJson();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    // La fila actual se lee para el criterio del valor HEREDADO: el CUIT con
    // guiones y los dos celulares que no son diez digitos se aceptan mientras
    // no cambien (ver la cabecera).
    $actual = db()->prepare('SELECT celular, cuit, condicion FROM clientes WHERE id = :id');
    $actual->execute([':id' => $id]);
    $previo = $actual->fetch();
    if (!$previo) json_error('Cliente no encontrado', 404);

    $data = validarCliente($in, $previo);

    $stmt = db()->prepare(
        'UPDATE clientes
            SET nombre    = :nombre,
                domicilio = :domicilio,
                localidad = :localidad,
                provincia = :provincia,
                pais      = :pais,
                contacto  = :contacto,
                celular   = :celular,
                correo    = :correo,
                razon     = :razon,
                condicion = :condicion,
                cuit      = :cuit,
                talonario = :talonario,
                medio     = :medio
          WHERE id = :id'
    );
    $stmt->execute(bindCliente($data) + [':id' => $id]);

    json_ok(['id' => $id, 'nombre' => $data['nombre']]);
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $existe = db()->prepare('SELECT 1 FROM clientes WHERE id = :id');
    $existe->execute([':id' => $id]);
    if (!$existe->fetchColumn()) json_error('Cliente no encontrado', 404);

    // El desglose de ?impacto=1 es informativo: el DELETE repite las
    // validaciones por su cuenta (ABM.md, "Eliminar").
    $deps = dependencias($id);
    if ($deps['dominios'] > 0 || $deps['contratos'] > 0 || $deps['comprobantes'] > 0) {
        $partes = [];
        if ($deps['dominios'] > 0)     $partes[] = "{$deps['dominios']} dominio(s)";
        if ($deps['contratos'] > 0)    $partes[] = "{$deps['contratos']} contrato(s)";
        if ($deps['comprobantes'] > 0) $partes[] = "{$deps['comprobantes']} comprobante(s)";
        json_error(
            'No se puede eliminar: el cliente tiene ' . implode(', ', $partes) .
            '. Reasignalos a otro cliente antes de borrarlo.',
            409
        );
    }

    $stmt = db()->prepare('DELETE FROM clientes WHERE id = :id');
    $stmt->execute([':id' => $id]);

    json_ok(['id' => $id]);
}

/* ---------------------------------------------------------------- helpers */

/** Cantidad de filas que dependen del cliente, por tabla. */
function dependencias(int $id): array
{
    $stmt = db()->prepare(
        'SELECT (SELECT COUNT(*) FROM dominios     WHERE cliente = :a) AS dominios,
                (SELECT COUNT(*) FROM contratos    WHERE cliente = :b) AS contratos,
                (SELECT COUNT(*) FROM comprobantes WHERE cliente = :c) AS comprobantes'
    );
    $stmt->execute([':a' => $id, ':b' => $id, ':c' => $id]);
    $r = $stmt->fetch() ?: [];

    return [
        'dominios'     => (int) ($r['dominios']     ?? 0),
        'contratos'    => (int) ($r['contratos']    ?? 0),
        'comprobantes' => (int) ($r['comprobantes'] ?? 0),
    ];
}

/**
 * Valida y normaliza el payload.
 *
 * `$previo` es null en el alta y la fila actual en la edicion: de ahi salen los
 * valores HEREDADOS que se aceptan aunque no respeten el formato (ver la
 * cabecera).
 */
function validarCliente(array $in, ?array $previo): array
{
    $out = [];

    // `nombre` es lo unico obligatorio: es con lo que el cliente se identifica
    // en toda pantalla que lo nombre, y las 64 filas lo tienen.
    $out['nombre'] = textoObligatorio($in['nombre'] ?? null, 'nombre', 'El nombre');

    foreach (['domicilio', 'localidad', 'provincia', 'pais', 'contacto', 'razon'] as $campo) {
        $out[$campo] = texto($in[$campo] ?? null, $campo, rotulo($campo));
    }

    // Celular y CUIT: formato exigido SOLO cuando el valor cambia. Tres filas
    // de la base no lo cumplen y sin esta excepcion quedarian imposibles de
    // guardar (ver la cabecera).
    $out['celular'] = digitos($in['celular'] ?? null, 'El celular', CELULAR_DIGITOS, $previo['celular'] ?? null);
    $out['cuit']    = digitos($in['cuit']    ?? null, 'El CUIT',    CUIT_DIGITOS,    $previo['cuit']    ?? null);

    $correo = texto($in['correo'] ?? null, 'correo', 'El correo');
    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        json_error('El correo no es valido', 422);
    }
    $out['correo'] = $correo;

    // `condicion` es obligatoria: la copia el comprobante y es lo que decide
    // que documento se le emite al cliente. Las 64 filas tienen una.
    $out['condicion'] = condicionEntrada($in['condicion'] ?? null, $previo['condicion'] ?? null);

    // Las dos FK: el 0 centinela se normaliza a null y se verifica que la fila
    // exista -- las dos apuntan a tablas reales y un id inventado devolveria un
    // 500 del motor en vez de decir cual campo esta mal.
    $out['talonario'] = fkOpcional($in['talonario'] ?? null, 'talonarios', 'El talonario');
    $out['medio']     = fkOpcional($in['medio']     ?? null, 'medios',     'El medio de pago');

    return $out;
}

/** Rotulo de un campo para los mensajes de error. */
function rotulo(string $campo): string
{
    return [
        'nombre'    => 'El nombre',
        'domicilio' => 'El domicilio',
        'localidad' => 'La localidad',
        'provincia' => 'La provincia',
        'pais'      => 'El pais',
        'contacto'  => 'El contacto',
        'razon'     => 'La razon social',
        'correo'    => 'El correo',
    ][$campo] ?? 'El campo';
}

/**
 * Texto opcional. Vacio se guarda como CADENA VACIA, no como NULL (ver la
 * cabecera). Lo que se pasa del largo se RECHAZA, no se recorta: un domicilio
 * cortado a la mitad es un dato mal emitido, no un dato prolijo -- el mismo
 * criterio con el que `facturarContexto()` bloquea la emision.
 */
function texto(mixed $valor, string $campo, string $rotulo): string
{
    $v   = trim((string) ($valor ?? ''));
    $max = LARGO[$campo];
    if (mb_strlen($v) > $max) {
        json_error("{$rotulo} no puede superar {$max} caracteres", 422);
    }

    return $v;
}

function textoObligatorio(mixed $valor, string $campo, string $rotulo): string
{
    $v = texto($valor, $campo, $rotulo);
    if ($v === '') json_error("{$rotulo} es obligatorio", 422);

    return $v;
}

/**
 * Campo de solo digitos con largo exacto. Vacio => cadena vacia.
 *
 * `$heredado` es el valor que la fila ya tenia: se acepta tal cual, aunque no
 * respete el formato. Sin esa excepcion las tres filas que no lo cumplen
 * quedarian imposibles de guardar (ver la cabecera). No necesita tope de largo
 * aparte: lo nuevo tiene que medir exactamente `$largo` -- menos que cualquiera
 * de las dos columnas -- y lo heredado salio de la base, asi que ya entra.
 *
 * LO QUE LLEGA MAL SE RECHAZA, NO SE LIMPIA EN SILENCIO. Al CUIT `20-14078223-9`
 * se le podrian sacar los guiones y guardar `20140782239`, que ademas es un CUIT
 * valido -- pero eso es REESCRIBIR un dato cargado desde una pantalla que nadie
 * abrio para eso. El sobrante queda a la vista y esta funcion lo corta diciendo
 * cuantos digitos van. Mismo criterio que el celular de las invitaciones
 * (CLAUDE.md).
 *
 * Se valida con `ctype_digit()` y no con una expresion regular: `/^[0-9]+$/` da
 * por buena una cadena terminada en salto de linea.
 */
function digitos(mixed $valor, string $rotulo, int $largo, ?string $heredado): string
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '') return '';

    if ($heredado !== null && $v === trim($heredado)) return $v;

    if (!ctype_digit($v)) {
        json_error("{$rotulo} tiene que ser solo numeros, sin espacios ni guiones", 422);
    }
    if (mb_strlen($v) !== $largo) {
        json_error("{$rotulo} tiene que tener {$largo} digitos", 422);
    }

    return $v;
}

/**
 * Condicion frente al IVA: obligatoria y dentro del catalogo.
 *
 * El codigo que la fila YA TENIA se acepta aunque no este en `combos`, igual
 * que el `tipo` heredado de `talonarios.php`: la validacion existe para que no
 * ENTREN codigos nuevos fuera del catalogo, no para bloquear una fila vieja que
 * ya tiene comprobantes emitidos.
 */
function condicionEntrada(mixed $valor, ?string $heredado): string
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '') json_error('La condicion frente al IVA es obligatoria', 422);

    if (mb_strlen($v) > LARGO['condicion']) {
        json_error('La condicion tiene que ser un codigo de hasta ' . LARGO['condicion'] . ' letras', 422);
    }

    if ($heredado !== null && $v === trim($heredado)) return $v;

    $catalogo = combo(COMBO_CONDICION);
    if ($catalogo !== [] && !isset($catalogo[$v])) {
        json_error("La condicion \"{$v}\" no esta en el catalogo", 422);
    }

    return $v;
}

/** Parametros del INSERT / UPDATE, en el orden del esquema. */
function bindCliente(array $d): array
{
    return [
        ':nombre'    => $d['nombre'],
        ':domicilio' => $d['domicilio'],
        ':localidad' => $d['localidad'],
        ':provincia' => $d['provincia'],
        ':pais'      => $d['pais'],
        ':contacto'  => $d['contacto'],
        ':celular'   => $d['celular'],
        ':correo'    => $d['correo'],
        ':razon'     => $d['razon'],
        ':condicion' => $d['condicion'],
        ':cuit'      => $d['cuit'],
        ':talonario' => $d['talonario'],
        ':medio'     => $d['medio'],
    ];
}

/**
 * Id de una FK opcional. Ademas de normalizar el 0 centinela a null, verifica
 * que la fila exista.
 */
function fkOpcional(mixed $valor, string $tabla, string $rotulo): ?int
{
    $id = idOrNull($valor);
    if ($id === null) return null;

    $stmt = db()->prepare("SELECT 1 FROM {$tabla} WHERE id = :id");
    $stmt->execute([':id' => $id]);
    if (!$stmt->fetchColumn()) json_error("{$rotulo} #{$id} no existe", 422);

    return $id;
}

/** Tabla plana valor -> texto de un combo del sistema historico. */
function combo(string $clave): array
{
    static $cache = [];
    if (isset($cache[$clave])) return $cache[$clave];

    $stmt = db()->prepare('SELECT valor, texto FROM combos WHERE combo = :c ORDER BY orden ASC, id ASC');
    $stmt->execute([':c' => $clave]);

    $textos = [];
    foreach ($stmt->fetchAll() as $r) {
        $valor = trim((string) ($r['valor'] ?? ''));
        if ($valor === '') continue;
        $textos[$valor] = (string) ($r['texto'] ?? '');
    }

    // Sin catalogo el <select> de condicion quedaria vacio y el campo es
    // obligatorio: o sea, no se podria dar de alta ningun cliente.
    if ($textos === [] && $clave === COMBO_CONDICION) $textos = CONDICIONES_FALLBACK;

    return $cache[$clave] = $textos;
}

/** El mismo combo, como lista ordenada para poblar un <select>. */
function comboLista(string $clave): array
{
    $items = [];
    foreach (combo($clave) as $valor => $texto) {
        $items[] = ['valor' => (string) $valor, 'texto' => $texto];
    }

    return $items;
}

/**
 * Id de una FK del sistema historico, donde el 0 significa "sin asignar" igual
 * que el NULL (ver el criterio del `0` centinela en db/schema.sql).
 */
function idOrNull(mixed $v): ?int
{
    $id = (int) $v;

    return $id > 0 ? $id : null;
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Body JSON invalido', 400);

    return $data;
}
