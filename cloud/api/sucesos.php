<?php

declare(strict_types=1);

// Visor de sucesos — endpoint read-only sobre `sucesos_log`.
// GET api/sucesos.php?id=N                                        -> row
// GET api/sucesos.php?q=...&tipo=...&desde=...&hasta=...&limite=N -> lista
//
// Cualquier metodo distinto de GET responde 405. La escritura del log
// vive en api/lib/sucesos.php (registrarSuceso), no aca.

require __DIR__ . '/bootstrap.php';

const TIPOS_SUCESOS_VISOR = ['info', 'error', 'alerta'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') json_error('metodo_no_soportado', 405);

try {
    $pdo = db();
    $id  = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id > 0) {
        sucesosHandleGetOne($pdo, $id);
    } else {
        sucesosHandleList($pdo, $_GET);
    }
} catch (Throwable $e) {
    json_error('Error al leer sucesos: ' . $e->getMessage(), 500);
}

function sucesosNormalizarFila(array $r): array
{
    $tipo = (string) ($r['tipo'] ?? 'info');
    if (!in_array($tipo, TIPOS_SUCESOS_VISOR, true)) $tipo = 'info';
    return [
        'id'      => (int) ($r['id'] ?? 0),
        'fecha'   => $r['fecha']   !== null ? (string) $r['fecha']   : null,
        'origen'  => $r['origen']  !== null ? (string) $r['origen']  : null,
        'tipo'    => $tipo,
        'detalle' => $r['detalle'] !== null ? (string) $r['detalle'] : null,
    ];
}

function sucesosHandleList(PDO $pdo, array $q): void
{
    $search = trim((string) ($q['q']     ?? ''));
    $tipo   = trim((string) ($q['tipo']  ?? ''));
    $desde  = trim((string) ($q['desde'] ?? ''));
    $hasta  = trim((string) ($q['hasta'] ?? ''));
    $limite = isset($q['limite']) ? (int) $q['limite'] : 200;
    if ($limite < 1 || $limite > 2000) $limite = 200;

    $where  = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(origen LIKE :s1 OR detalle LIKE :s2)';
        $like = '%' . $search . '%';
        $params[':s1'] = $like;
        $params[':s2'] = $like;
    }
    if ($tipo !== '' && in_array($tipo, TIPOS_SUCESOS_VISOR, true)) {
        $where[] = 'tipo = :tipo';
        $params[':tipo'] = $tipo;
    }
    if ($desde !== '') {
        $where[] = 'fecha >= :desde';
        $params[':desde'] = $desde . ' 00:00:00';
    }
    if ($hasta !== '') {
        $where[] = 'fecha <= :hasta';
        $params[':hasta'] = $hasta . ' 23:59:59';
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $total    = (int) $pdo->query('SELECT COUNT(*) FROM sucesos_log')->fetchColumn();

    $sql  = "SELECT id, fecha, origen, tipo, detalle FROM sucesos_log $sqlWhere ORDER BY id DESC LIMIT $limite";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = array_map('sucesosNormalizarFila', $stmt->fetchAll());

    json_ok([
        'stats' => ['total' => $total, 'mostrados' => count($rows)],
        'items' => $rows,
    ]);
}

function sucesosHandleGetOne(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('SELECT id, fecha, origen, tipo, detalle FROM sucesos_log WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) json_error('suceso_no_encontrado', 404);
    json_ok(sucesosNormalizarFila($row));
}
