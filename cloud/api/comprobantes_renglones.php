<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/comprobantes_lib.php';

/**
 * Renglones de un comprobante (`comprobantesrenglones`).
 *
 * Endpoint aparte de `comprobantes.php` porque son otra entidad con su propio
 * ciclo: el modal de la ficha los agrega, edita y borra de a uno sin volver a
 * guardar la cabecera. Sigue el patron de los endpoints hermanos del repo
 * (`tareas_ejecuciones.php`, `migraciones_apply.php`).
 *
 * DOS INVARIANTES, Y LAS DOS SE CHEQUEAN CONTRA LA BASE:
 *
 *   1. Solo se tocan los renglones de un comprobante en `Preparacion`. Un
 *      comprobante Pendiente o Cancelado ya se emitio con esos renglones
 *      impresos; agregarle uno cambiaria el total de un documento entregado.
 *      La UI esconde los botones, pero esconder el boton no es el control.
 *   2. TODA escritura termina en `comprobanteTotalizar()`, que reescribe
 *      subtotal / iva / total desde los renglones. Es lo que hace el legacy en
 *      sus tres ramas (`renglon.php`) y es la unica forma de que el total no
 *      se despegue de lo que suma la grilla. La respuesta devuelve los totales
 *      nuevos para que la ficha los repinte sin pedir el detalle de nuevo.
 *
 * `monto` NO se deriva de cantidad x unitario. El legacy lo manda como un
 * campo mas y lo usa asi: los renglones de promocion se cargan con monto
 * NEGATIVO y unitario positivo (ver `cContrato::facturar()`), y los renglones
 * de encabezado van en 0 con detalle y sin importe. Derivarlo rompería los
 * dos casos. El front lo pre-calcula como comodidad y se puede pisar.
 */

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
    json_error('Error al procesar renglones: ' . $e->getMessage(), 500);
}

function handleList(): void
{
    $id = (int) ($_GET['comprobante'] ?? 0);
    if ($id <= 0) json_error('Id de comprobante invalido', 422);

    json_ok(['renglones' => comprobanteRenglones($id)]);
}

function handleCreate(): void
{
    $in  = readJson();
    $cpb = (int) ($in['comprobante'] ?? 0);
    if ($cpb <= 0) json_error('Id de comprobante invalido', 422);

    exigirPreparacion($cpb);
    $data = validarRenglon($in);

    // `orden` se autonumera al final si no lo mandan: los renglones se
    // imprimen por `orden, id`, y dejarlo en 0 para todos haria que el orden
    // dependa del id -- que es lo mismo hasta que alguien edita uno.
    $orden = trim((string) ($in['orden'] ?? ''));
    if ($orden === '') {
        $max = db()->prepare('SELECT COALESCE(MAX(orden), -1) FROM comprobantesrenglones WHERE comprobante = :id');
        $max->execute([':id' => $cpb]);
        $data['orden'] = ((int) $max->fetchColumn()) + 1;
    } else {
        $data['orden'] = ordenValido($orden);
    }

    $stmt = db()->prepare(
        "INSERT INTO comprobantesrenglones
             (comprobante, orden, cantidad, articulo, detalle, iva, unitario, monto, estado)
         VALUES
             (:cpb, :orden, :cantidad, :articulo, :detalle, :iva, :unitario, :monto, '1')"
    );
    $stmt->execute([
        ':cpb'      => $cpb,
        ':orden'    => $data['orden'],
        ':cantidad' => $data['cantidad'],
        ':articulo' => $data['articulo'],
        ':detalle'  => $data['detalle'],
        ':iva'      => $data['iva'],
        ':unitario' => $data['unitario'],
        ':monto'    => $data['monto'],
    ]);

    $id = (int) db()->lastInsertId();

    json_ok(['id' => $id, 'totales' => comprobanteTotalizar($cpb)], 201);
}

function handleUpdate(): void
{
    $in = readJson();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $prev = db()->prepare('SELECT comprobante FROM comprobantesrenglones WHERE id = :id');
    $prev->execute([':id' => $id]);
    $cpb = (int) ($prev->fetchColumn() ?: 0);
    if ($cpb <= 0) json_error('Renglon no encontrado', 404);

    exigirPreparacion($cpb);
    $data = validarRenglon($in);

    $orden = trim((string) ($in['orden'] ?? ''));
    $data['orden'] = $orden === '' ? 0 : ordenValido($orden);

    // `comprobante` NO se toca: mover un renglon de un comprobante a otro
    // cambiaria el total de los dos y no es lo que este modal ofrece.
    db()->prepare(
        'UPDATE comprobantesrenglones
            SET orden = :orden, cantidad = :cantidad, articulo = :articulo,
                detalle = :detalle, iva = :iva, unitario = :unitario, monto = :monto
          WHERE id = :id'
    )->execute([
        ':orden'    => $data['orden'],
        ':cantidad' => $data['cantidad'],
        ':articulo' => $data['articulo'],
        ':detalle'  => $data['detalle'],
        ':iva'      => $data['iva'],
        ':unitario' => $data['unitario'],
        ':monto'    => $data['monto'],
        ':id'       => $id,
    ]);

    json_ok(['id' => $id, 'totales' => comprobanteTotalizar($cpb)]);
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $prev = db()->prepare('SELECT comprobante FROM comprobantesrenglones WHERE id = :id');
    $prev->execute([':id' => $id]);
    $cpb = (int) ($prev->fetchColumn() ?: 0);
    if ($cpb <= 0) json_error('Renglon no encontrado', 404);

    exigirPreparacion($cpb);

    db()->prepare('DELETE FROM comprobantesrenglones WHERE id = :id')->execute([':id' => $id]);

    json_ok(['id' => $id, 'totales' => comprobanteTotalizar($cpb)]);
}

/* ---------------------------------------------------------------- helpers */

/** Corta con 409 si el comprobante no esta en Preparacion. */
function exigirPreparacion(int $cpb): void
{
    $stmt = db()->prepare('SELECT estado FROM comprobantes WHERE id = :id');
    $stmt->execute([':id' => $cpb]);
    $fila = $stmt->fetch();
    if (!$fila) json_error('Comprobante no encontrado', 404);

    $estado = trim((string) $fila['estado']);
    if ($estado !== ESTADO_PREPARACION) {
        json_error(
            'Solo se pueden tocar los renglones de un comprobante en Preparacion. Este esta en "' .
            (combo(COMBO_ESTADO)[$estado] ?? 'sin estado') . '".',
            409
        );
    }
}

function validarRenglon(array $in): array
{
    $detalle = textoLimitado($in['detalle'] ?? '', 500, 'El detalle');
    if ($detalle === '') json_error('El detalle es obligatorio', 422);

    // `iva` es la alicuota (0 / 10.50 / 21.00), no el importe. Se valida
    // contra el combo cuando existe: una alicuota que no sea una de esas tres
    // no se desagrega en `comprobanteTotalizar()` y quedaria como monto sin
    // IVA discriminado sin que nadie lo note.
    $iva      = decimalNoNegativo($in['iva'] ?? null, 'El IVA');
    $catalogo = combo(COMBO_RENGLON_IVA);
    if ($catalogo !== []) {
        $valores = array_map(static fn($v) => (float) $v, array_keys($catalogo));
        if (!in_array($iva, $valores, true)) {
            json_error(
                'La alicuota de IVA tiene que ser una de: ' . implode(', ', array_keys($catalogo)),
                422
            );
        }
    }

    return [
        'cantidad' => decimalConSigno($in['cantidad'] ?? null, 'La cantidad'),
        'articulo' => fkOpcional($in['articulo'] ?? null, 'articulos', 'El articulo'),
        'detalle'  => $detalle,
        'iva'      => $iva,
        'unitario' => decimalConSigno($in['unitario'] ?? null, 'El unitario'),
        // Negativo a proposito: asi se cargan los renglones de descuento.
        'monto'    => decimalConSigno($in['monto'] ?? null, 'El monto'),
    ];
}

/** `orden` es smallint: 0 a 32767, pero se acota a lo razonable. */
function ordenValido(string $v): int
{
    if (!ctype_digit($v)) json_error('El orden tiene que ser un numero entero de 0 o mas', 422);

    $n = (int) $v;
    if ($n > 9999) json_error('El orden no puede superar 9999', 422);

    return $n;
}
