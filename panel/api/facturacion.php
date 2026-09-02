<?php

declare(strict_types=1);

/**
 * Datos de facturacion del dominio de la sesion.
 *
 *   GET api/facturacion.php -> datos fiscales + combo de condicion
 *   PUT api/facturacion.php -> modificacion de esos datos
 *
 * Portado de reactor-panel/comprobantes/facturacion.php, que editaba los
 * mismos seis campos (razon, condicion, cuit, contacto, celular, correo) de
 * la ficha del cliente asociado al dominio de la sesion.
 *
 * ALCANCE: el registro NO se elige por id. Se resuelve como lo hacia el
 * legacy -- `dominios.cliente` del dominio de la sesion (requireDominioId())
 * -- para que nadie pueda leer ni escribir la ficha fiscal de otro dominio
 * mandando un id a mano. El endpoint no acepta ningun identificador de
 * entrada.
 *
 * SIN ALTA NI BAJA: la ficha del cliente la crea el alta del dominio (proceso
 * comercial, fuera del panel). Si el dominio no tiene cliente asociado
 * (`dominios.cliente` NULL o 0 -- el 0 es el centinela historico de "sin
 * asignar") el GET devuelve `cliente: null` y el PUT corta con 409.
 *
 * CONDICION FISCAL: `clientes.condicion` guarda el codigo corto (CF, RM, RI,
 * EX). El texto sale de `combos` con la clave '$xCliente->condicion' -- la
 * misma que usaba comboLlenar() en el legacy -- y CONDICIONES_FALLBACK cubre
 * el caso de que esa fila no este cargada.
 */

require __DIR__ . '/bootstrap.php';

/** Clave de `combos` con los textos de `clientes.condicion`. */
const COMBO_CONDICION = '$xCliente->condicion';

/** Ultimo recurso si `combos` no tiene cargada la clave de arriba. */
const CONDICIONES_FALLBACK = [
    ['valor' => 'CF', 'texto' => 'Consumidor Final'],
    ['valor' => 'RM', 'texto' => 'Responsable Monotributo'],
    ['valor' => 'RI', 'texto' => 'Responsable Inscripto'],
    ['valor' => 'EX', 'texto' => 'Excento'],
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET': handleGet();    break;
        case 'PUT': handleUpdate(); break;
        default:
            json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar la facturacion: ' . $e->getMessage(), 500);
}

/* ------------------------------------------------------------------ */
/* Lectura                                                            */
/* ------------------------------------------------------------------ */

function handleGet(): void
{
    $dominio = requireDominioId();
    $ctx     = sessionContext() ?? [];
    $row     = clienteDelDominio($dominio);

    json_ok([
        'dominio' => [
            'id'     => $dominio,
            'nombre' => (string) ($ctx['dominio_nombre'] ?? ''),
        ],
        'cliente'     => $row !== null ? mapCliente($row) : null,
        'condiciones' => condiciones()['opciones'],
    ]);
}

/* ------------------------------------------------------------------ */
/* Modificacion                                                       */
/* ------------------------------------------------------------------ */

function handleUpdate(): void
{
    $dominio = requireDominioId();
    $row     = clienteDelDominio($dominio);
    if ($row === null) {
        json_error('El dominio no tiene una ficha de cliente asociada. Pedile a un administrador que la cargue.', 409);
    }

    $id     = (int) $row['id'];
    $datos  = validar(readJson());

    $stmt = db()->prepare(
        'UPDATE clientes
            SET razon = :razon, condicion = :condicion, cuit = :cuit,
                contacto = :contacto, celular = :celular, correo = :correo
          WHERE id = :id'
    );
    $stmt->execute([
        ':razon'     => $datos['razon'],
        ':condicion' => $datos['condicion'],
        ':cuit'      => $datos['cuit'],
        ':contacto'  => $datos['contacto'],
        ':celular'   => $datos['celular'],
        ':correo'    => $datos['correo'],
        ':id'        => $id,
    ]);

    // Se relee para devolver exactamente lo que quedo guardado (el CUIT, por
    // ejemplo, se normaliza a digitos): asi el front repinta sin adivinar.
    $fresco = clienteDelDominio($dominio);

    json_ok(['cliente' => $fresco !== null ? mapCliente($fresco) : null]);
}

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/**
 * Ficha de `clientes` apuntada por `dominios.cliente`. null si el dominio no
 * tiene cliente asignado o si el id apunta a un registro que ya no existe.
 */
function clienteDelDominio(int $dominio): ?array
{
    $stmt = db()->prepare(
        'SELECT c.id, c.nombre, c.razon, c.condicion, c.cuit,
                c.contacto, c.celular, c.correo,
                c.domicilio, c.localidad, c.provincia, c.pais
         FROM dominios d
         JOIN clientes c ON c.id = d.cliente
         WHERE d.id = :dom
         LIMIT 1'
    );
    $stmt->execute([':dom' => $dominio]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Opciones del combo de condicion fiscal + tabla plana codigo -> texto.
 * Se lee una sola vez por request.
 */
function condiciones(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $stmt = db()->prepare(
        'SELECT valor, texto FROM combos WHERE combo = :c ORDER BY orden ASC, texto ASC'
    );
    $stmt->execute([':c' => COMBO_CONDICION]);

    $opciones = [];
    foreach ($stmt->fetchAll() as $r) {
        $valor = trim((string) ($r['valor'] ?? ''));
        if ($valor === '') {
            continue;
        }
        $opciones[] = ['valor' => $valor, 'texto' => (string) ($r['texto'] ?? '')];
    }
    if ($opciones === []) {
        $opciones = CONDICIONES_FALLBACK;
    }

    $textos = [];
    foreach ($opciones as $o) {
        $textos[$o['valor']] = $o['texto'];
    }

    return $cache = ['opciones' => $opciones, 'textos' => $textos];
}

/** Normaliza una fila de `clientes` para el front. */
function mapCliente(array $r): array
{
    $texto     = static fn(string $k): string => trim((string) ($r[$k] ?? ''));
    $condicion = $texto('condicion');

    return [
        'id'              => (int) $r['id'],
        'nombre'          => $texto('nombre'),
        'razon'           => $texto('razon'),
        'condicion'       => $condicion,
        'condicion_texto' => condiciones()['textos'][$condicion] ?? '',
        'cuit'            => $texto('cuit'),
        'contacto'        => $texto('contacto'),
        'celular'         => $texto('celular'),
        'correo'          => $texto('correo'),
        'domicilio'       => $texto('domicilio'),
        'localidad'       => $texto('localidad'),
        'provincia'       => $texto('provincia'),
        'pais'            => $texto('pais'),
    ];
}

/** Valida y normaliza el payload de edicion. Corta con 422 si algo falla. */
function validar(array $in): array
{
    $razon     = trim((string) ($in['razon']     ?? ''));
    $condicion = trim((string) ($in['condicion'] ?? ''));
    $cuit      = trim((string) ($in['cuit']      ?? ''));
    $contacto  = trim((string) ($in['contacto']  ?? ''));
    $celular   = trim((string) ($in['celular']   ?? ''));
    $correo    = trim((string) ($in['correo']    ?? ''));

    if ($razon === '')             json_error('La razon social es obligatoria', 422);
    if (mb_strlen($razon) > 255)   json_error('La razon social no puede superar 255 caracteres', 422);

    if ($condicion !== '' && !array_key_exists($condicion, condiciones()['textos'])) {
        json_error('La condicion fiscal no es valida', 422);
    }

    // El CUIT se guarda solo con digitos (formato mayoritario de la tabla):
    // se aceptan guiones, puntos y espacios en la entrada y se descartan.
    if ($cuit !== '') {
        $cuit = preg_replace('/[\s.\-]/', '', $cuit) ?? '';
        if (!preg_match('/^\d{11}$/', $cuit)) {
            json_error('El CUIT debe tener 11 digitos', 422);
        }
    }

    if (mb_strlen($contacto) > 255) json_error('El contacto no puede superar 255 caracteres', 422);
    if (mb_strlen($celular)  > 255) json_error('El celular no puede superar 255 caracteres', 422);
    if ($celular !== '' && !preg_match('/^[+0-9\s().-]+$/', $celular)) {
        json_error('El celular solo admite numeros, espacios y los signos + ( ) - .', 422);
    }
    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        json_error('El correo no es valido', 422);
    }
    if (mb_strlen($correo) > 255)   json_error('El correo no puede superar 255 caracteres', 422);

    return [
        'razon'     => $razon,
        'condicion' => $condicion === '' ? null : $condicion,
        'cuit'      => $cuit      === '' ? null : $cuit,
        'contacto'  => $contacto  === '' ? null : $contacto,
        'celular'   => $celular   === '' ? null : $celular,
        'correo'    => $correo    === '' ? null : $correo,
    ];
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error('Body JSON invalido', 400);
    }
    return $data;
}
