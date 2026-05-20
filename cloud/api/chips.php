<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

const CHIP_ESTADOS = ['activo', 'inactivo', 'suspendido'];

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
    json_error('Error al procesar chips: ' . $e->getMessage(), 500);
}

function handleList(): void
{
    // Esquema real (db/schema.sql -> tabla `chips`): los nombres son distintos.
    // Mapeos:
    //   numero      -> telefono
    //   iccid       -> serie
    //   operador    -> compania     (varchar(2), código del operador)
    //   apn         -> NULL         (no existe en el esquema)
    //   notas       -> comentario
    //   created_at  -> registrado
    //   updated_at  -> recargado
    //   dominio_id  -> dominio
    // El `estado` en el esquema es smallint; se traduce a las etiquetas que
    // espera el front: 1='activo', 2='suspendido', resto='inactivo'.
    $stmt = db()->query(
        "SELECT c.id,
                c.dominio                AS dominio_id,
                c.telefono               AS numero,
                c.serie                  AS iccid,
                c.compania               AS operador,
                NULL                     AS apn,
                c.plan,
                CASE c.estado
                    WHEN 1 THEN 'activo'
                    WHEN 2 THEN 'suspendido'
                    ELSE        'inactivo'
                END                      AS estado,
                c.comentario             AS notas,
                c.registrado             AS created_at,
                c.recargado              AS updated_at,
                COALESCE(dom.nombre,'—') AS dominio_nombre
         FROM chips c
         LEFT JOIN dominios dom ON dom.id = c.dominio
         ORDER BY (c.estado = 1) DESC, c.compania ASC, c.telefono ASC"
    );

    $chips = array_map(static function (array $r): array {
        $r['dominio_id'] = (int) $r['dominio_id'];
        return $r;
    }, $stmt->fetchAll());

    $resumen = [
        'total'      => count($chips),
        'activo'     => 0,
        'inactivo'   => 0,
        'suspendido' => 0,
    ];
    foreach ($chips as $c) {
        $resumen[$c['estado']] = ($resumen[$c['estado']] ?? 0) + 1;
    }

    json_ok([
        'resumen' => $resumen,
        'chips'   => $chips,
    ]);
}

function handleCreate(): void
{
    $data = validateChipPayload(readJson(), false);

    try {
        $stmt = db()->prepare(
            'INSERT INTO chips (dominio_id, numero, iccid, operador, apn, plan, estado, notas)
             VALUES (:dom, :num, :icc, :op, :apn, :plan, :est, :notas)'
        );
        $stmt->execute([
            ':dom'   => $data['dominio_id'],
            ':num'   => $data['numero'],
            ':icc'   => $data['iccid'],
            ':op'    => $data['operador'],
            ':apn'   => $data['apn'],
            ':plan'  => $data['plan'],
            ':est'   => $data['estado'],
            ':notas' => $data['notas'],
        ]);
    } catch (PDOException $e) {
        handleDuplicateKey($e);
        throw $e;
    }

    json_ok(['id' => (int) db()->lastInsertId()], 201);
}

function handleUpdate(): void
{
    $in = readJson();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $data = validateChipPayload($in, true);

    try {
        $stmt = db()->prepare(
            'UPDATE chips
                SET dominio_id = :dom, numero = :num, iccid = :icc, operador = :op,
                    apn = :apn, plan = :plan, estado = :est, notas = :notas
              WHERE id = :id'
        );
        $stmt->execute([
            ':dom'   => $data['dominio_id'],
            ':num'   => $data['numero'],
            ':icc'   => $data['iccid'],
            ':op'    => $data['operador'],
            ':apn'   => $data['apn'],
            ':plan'  => $data['plan'],
            ':est'   => $data['estado'],
            ':notas' => $data['notas'],
            ':id'    => $id,
        ]);
    } catch (PDOException $e) {
        handleDuplicateKey($e);
        throw $e;
    }

    if ($stmt->rowCount() === 0) {
        $exists = db()->prepare('SELECT 1 FROM chips WHERE id = :id');
        $exists->execute([':id' => $id]);
        if (!$exists->fetchColumn()) json_error('Chip no encontrado', 404);
    }

    json_ok(['id' => $id]);
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('DELETE FROM chips WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) json_error('Chip no encontrado', 404);

    json_ok(['id' => $id]);
}

function validateChipPayload(array $in, bool $isUpdate): array
{
    $dominio_id = (int)   ($in['dominio_id'] ?? 0);
    $numero     = trim((string) ($in['numero']   ?? ''));
    $iccid      = trim((string) ($in['iccid']    ?? ''));
    $operador   = trim((string) ($in['operador'] ?? ''));
    $apn        = trim((string) ($in['apn']      ?? ''));
    $plan       = trim((string) ($in['plan']     ?? ''));
    $estado     = trim((string) ($in['estado']   ?? 'activo'));
    $notas      = trim((string) ($in['notas']    ?? ''));

    if ($dominio_id <= 0)            json_error('Elegi un dominio', 422);
    if ($numero === '')              json_error('El numero de linea es obligatorio', 422);
    if (mb_strlen($numero) > 30)     json_error('El numero no puede superar 30 caracteres', 422);
    if ($iccid === '')               json_error('El ICCID es obligatorio', 422);
    if (!preg_match('/^[0-9]{18,22}$/', $iccid))
        json_error('El ICCID debe tener entre 18 y 22 digitos numericos', 422);
    if ($operador === '')            json_error('El operador es obligatorio', 422);
    if (mb_strlen($operador) > 60)   json_error('El operador no puede superar 60 caracteres', 422);
    if (mb_strlen($apn)  > 120)      json_error('El APN no puede superar 120 caracteres', 422);
    if (mb_strlen($plan) > 120)      json_error('El plan no puede superar 120 caracteres', 422);
    if (!in_array($estado, CHIP_ESTADOS, true))
        json_error('Estado invalido', 422);
    if (mb_strlen($notas) > 500)     json_error('Las notas no pueden superar 500 caracteres', 422);

    $exists = db()->prepare('SELECT 1 FROM dominios WHERE id = :id');
    $exists->execute([':id' => $dominio_id]);
    if (!$exists->fetchColumn()) json_error('El dominio no existe', 422);

    return [
        'dominio_id' => $dominio_id,
        'numero'     => $numero,
        'iccid'      => $iccid,
        'operador'   => $operador,
        'apn'        => $apn  === '' ? null : $apn,
        'plan'       => $plan === '' ? null : $plan,
        'estado'     => $estado,
        'notas'      => $notas === '' ? null : $notas,
    ];
}

function handleDuplicateKey(PDOException $e): void
{
    if ((int) $e->errorInfo[1] !== 1062) return;
    $msg = (string) $e->getMessage();
    if (str_contains($msg, 'uq_chips_iccid'))  json_error('Ya existe un chip con ese ICCID', 409);
    if (str_contains($msg, 'uq_chips_numero')) json_error('Ya existe un chip con ese numero', 409);
    json_error('Ya existe un chip con esos datos', 409);
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Body JSON invalido', 400);

    return $data;
}
