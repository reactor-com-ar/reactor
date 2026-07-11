<?php

declare(strict_types=1);

// Programador de tareas — CRUD del catalogo (skill §7.1).
// api/tareas.php  GET/POST/PUT/DELETE  siempre JSON {ok, ...}.

require __DIR__ . '/bootstrap.php';

$sHelper = __DIR__ . '/lib/sucesos.php';
if (is_file($sHelper)) require_once $sHelper;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    switch ($method) {
        case 'OPTIONS': exit;
        case 'GET':     handleGetTareas();    break;
        case 'POST':    handleCreateTarea();  break;
        case 'PUT':     handleUpdateTarea();  break;
        case 'DELETE':  handleDeleteTarea();  break;
        default: json_error('metodo_no_soportado', 405);
    }
} catch (Throwable $e) {
    // Registrar la falla en sucesos_log para que quede rastro visible en el
    // Visor de sucesos. Silencioso ante fallas del propio helper.
    if (function_exists('registrarSuceso')) {
        try { registrarSuceso(db(), 'cron/tareas.' . strtolower($method), 'error', $e->getMessage()); }
        catch (Throwable $_) {}
    }
    json_error('Error en tareas: ' . $e->getMessage(), 500);
}

function normalizarTarea(array $r): array
{
    return [
        'id'                  => (int) ($r['id'] ?? 0),
        'nombre'              => (string) ($r['nombre'] ?? ''),
        'descripcion'         => $r['descripcion'] !== null ? (string) $r['descripcion'] : null,
        'script'              => (string) ($r['script'] ?? ''),
        'cron_expr'           => (string) ($r['cron_expr'] ?? ''),
        'activo'              => (int) ($r['activo'] ?? 0),
        'overlap'             => (string) ($r['overlap'] ?? 'skip'),
        'timeout_seg'         => (int) ($r['timeout_seg'] ?? 300),
        'retencion_dias'      => (int) ($r['retencion_dias'] ?? 7),
        'ultimo_run'          => $r['ultimo_run']    ?? null,
        'ultimo_estado'       => $r['ultimo_estado'] ?? null,
        'ultimo_error'        => $r['ultimo_error']  ?? null,
        'fecha_creacion'      => $r['fecha_creacion']     ?? null,
        'fecha_modificacion'  => $r['fecha_modificacion'] ?? null,
    ];
}

function handleGetTareas(): void
{
    $pdo = db();
    $id  = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM tareas WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) json_error('tarea_no_encontrada', 404);
        json_ok(normalizarTarea($row));
    }

    $q      = trim((string) ($_GET['q']      ?? ''));
    $activo = trim((string) ($_GET['activo'] ?? ''));
    $limite = isset($_GET['limite']) ? (int) $_GET['limite'] : 100;
    if ($limite < 1 || $limite > 1000) $limite = 100;

    $where  = [];
    $params = [];
    if ($q !== '') {
        $where[] = '(nombre LIKE :q1 OR script LIKE :q2 OR descripcion LIKE :q3 OR cron_expr LIKE :q4)';
        $like    = '%' . $q . '%';
        $params[':q1'] = $like; $params[':q2'] = $like; $params[':q3'] = $like; $params[':q4'] = $like;
    }
    if ($activo === '0' || $activo === '1') {
        $where[] = 'activo = :ac'; $params[':ac'] = (int) $activo;
    }
    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $pdo->prepare("SELECT * FROM tareas $sqlWhere ORDER BY id DESC LIMIT $limite");
    $stmt->execute($params);
    $rows = array_map('normalizarTarea', $stmt->fetchAll());

    $stats = $pdo->query(
        "SELECT
           (SELECT COUNT(*) FROM tareas) AS total,
           (SELECT COUNT(*) FROM tareas WHERE activo = 1) AS activas,
           (SELECT COUNT(*) FROM tareas_ejecuciones WHERE estado = 'error') AS errores,
           (SELECT COUNT(*) FROM tareas_ejecuciones WHERE estado = 'corriendo') AS corriendo"
    )->fetch();

    json_ok(['tareas' => $rows, 'stats' => [
        'total'     => (int) ($stats['total']     ?? 0),
        'activas'   => (int) ($stats['activas']   ?? 0),
        'errores'   => (int) ($stats['errores']   ?? 0),
        'corriendo' => (int) ($stats['corriendo'] ?? 0),
    ]]);
}

function leerPayloadTarea(array $body): array
{
    $nombre        = trim((string) ($body['nombre']         ?? ''));
    $descripcion   = trim((string) ($body['descripcion']    ?? ''));
    $script        = trim((string) ($body['script']         ?? ''));
    $cronExpr      = trim((string) ($body['cron_expr']      ?? ''));
    $activo        = !empty($body['activo']) ? 1 : 0;
    $overlap       = (string) ($body['overlap'] ?? 'skip');
    if (!in_array($overlap, ['skip', 'allow'], true)) $overlap = 'skip';
    $timeout       = (int) ($body['timeout_seg']    ?? 300);
    if ($timeout < 5 || $timeout > 86400) $timeout = 300;
    $retencion     = (int) ($body['retencion_dias'] ?? 7);
    if ($retencion < 1 || $retencion > 3650) $retencion = 7;

    return compact('nombre', 'descripcion', 'script', 'cronExpr', 'activo',
                   'overlap', 'timeout', 'retencion');
}

function validarPayloadTarea(array $d): ?string
{
    if ($d['nombre'] === '' || strlen($d['nombre']) > 120)  return 'El nombre es obligatorio (1..120).';
    if ($d['script'] === '' || strlen($d['script']) > 255)  return 'El script es obligatorio.';
    if (!preg_match('/^[A-Za-z0-9_\/.\-]+\.php$/', $d['script'])) return 'Script invalido.';
    if ($d['cronExpr'] === '') return 'La expresion cron es obligatoria.';
    $partes = preg_split('/\s+/', $d['cronExpr']);
    if (count($partes) !== 5) return 'La expresion cron debe tener 5 campos.';
    foreach ($partes as $p) {
        if (!preg_match('/^[\d*,\/\-]+$/', $p)) return 'Expresion cron con caracteres invalidos.';
    }
    if ($d['descripcion'] !== '' && strlen($d['descripcion']) > 255) return 'Descripcion muy larga.';
    return null;
}

function handleCreateTarea(): void
{
    $body = readJsonBody();
    $d    = leerPayloadTarea($body);
    if ($err = validarPayloadTarea($d)) json_error($err, 400);

    try {
        $stmt = db()->prepare(
            'INSERT INTO tareas (nombre, descripcion, script, cron_expr, activo, overlap, timeout_seg, retencion_dias)
             VALUES (:n, :d, :s, :c, :a, :o, :t, :r)'
        );
        $stmt->execute([
            ':n' => $d['nombre'],
            ':d' => $d['descripcion'] === '' ? null : $d['descripcion'],
            ':s' => $d['script'],
            ':c' => $d['cronExpr'],
            ':a' => $d['activo'],
            ':o' => $d['overlap'],
            ':t' => $d['timeout'],
            ':r' => $d['retencion'],
        ]);
        json_ok(['id' => (int) db()->lastInsertId()], 201);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') json_error('nombre_duplicado', 409);
        throw $e;
    }
}

function handleUpdateTarea(): void
{
    $body = readJsonBody();
    $id   = (int) ($body['id'] ?? 0);
    if ($id <= 0) json_error('missing_id', 400);
    $d    = leerPayloadTarea($body);
    if ($err = validarPayloadTarea($d)) json_error($err, 400);

    try {
        $stmt = db()->prepare(
            'UPDATE tareas SET nombre=:n, descripcion=:d, script=:s, cron_expr=:c,
                               activo=:a, overlap=:o, timeout_seg=:t, retencion_dias=:r
             WHERE id=:id'
        );
        $stmt->execute([
            ':n'  => $d['nombre'],
            ':d'  => $d['descripcion'] === '' ? null : $d['descripcion'],
            ':s'  => $d['script'],
            ':c'  => $d['cronExpr'],
            ':a'  => $d['activo'],
            ':o'  => $d['overlap'],
            ':t'  => $d['timeout'],
            ':r'  => $d['retencion'],
            ':id' => $id,
        ]);
        json_ok(['id' => $id]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') json_error('nombre_duplicado', 409);
        throw $e;
    }
}

function handleDeleteTarea(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('missing_id', 400);

    $pdo = db();
    // 1) Chequear ejecuciones corriendo.
    $chk = $pdo->prepare("SELECT COUNT(*) FROM tareas_ejecuciones WHERE tarea_id=:tid AND estado='corriendo'");
    $chk->execute([':tid' => $id]);
    if ((int) $chk->fetchColumn() > 0) {
        json_error('ejecucion_en_curso: hay una ejecucion corriendo — detenela primero.', 409);
    }
    // 2) Borrar archivos .log en disco.
    $logs = $pdo->prepare('SELECT log_path FROM tareas_ejecuciones WHERE tarea_id=:tid AND log_path IS NOT NULL');
    $logs->execute([':tid' => $id]);
    $archivosBorrados = 0;
    foreach ($logs->fetchAll() as $row) {
        $lp = (string) $row['log_path'];
        if ($lp !== '' && is_file($lp) && @unlink($lp)) $archivosBorrados++;
    }
    // 3+4) Cascada de filas.
    $pdo->prepare('DELETE FROM tareas_ejecuciones WHERE tarea_id=:tid')->execute([':tid' => $id]);
    $del = $pdo->prepare('DELETE FROM tareas WHERE id=:id');
    $del->execute([':id' => $id]);
    json_ok(['borrados' => $del->rowCount(), 'archivos_borrados' => $archivosBorrados]);
}

function readJsonBody(): array
{
    $raw = (string) file_get_contents('php://input');
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('body_invalido', 400);
    return $data;
}
