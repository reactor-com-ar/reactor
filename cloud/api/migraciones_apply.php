<?php

declare(strict_types=1);

// Migrador DB — aplicar una migracion.
// POST api/migraciones_apply.php  body { nombre: "XXX.sql" }
//   -> { nombre, hash, aplicada, duracion_ms }
//
// Registra cada falla en `sucesos_log` via registrarSuceso() si el
// helper esta disponible. Las DDL en MySQL hacen auto-commit por
// sentencia; si el archivo falla a mitad, lo aplicado queda y la fila
// del ledger NO se inserta (se sigue considerando pendiente).

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/migraciones.php';

// Log opcional del visor de sucesos. Si el helper esta presente, cada
// falla de PDO::exec queda registrada.
$sucesosHelper = __DIR__ . '/lib/sucesos.php';
if (is_file($sucesosHelper)) require_once $sucesosHelper;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') json_error('metodo_no_soportado', 405);

try {
    $raw  = (string) file_get_contents('php://input');
    $body = $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($body)) json_error('body_invalido', 400);

    $nombre = trim((string) ($body['nombre'] ?? ''));
    if (!nombreMigracionValido($nombre)) {
        json_error('nombre_invalido', 400);
    }

    $ruta = migracionesDir() . '/' . $nombre;
    if (!is_file($ruta)) {
        json_error('migracion_no_encontrada', 404);
    }

    $sql = (string) file_get_contents($ruta);
    if (trim($sql) === '') {
        json_error('La migracion esta vacia.', 400);
    }

    $pdo = db();
    asegurarTablaMigraciones($pdo);

    // Bloquear re-apply. La unicidad se garantiza aca (no en el DDL).
    $stmt = $pdo->prepare('SELECT id, aplicada FROM migraciones WHERE nombre = :n LIMIT 1');
    $stmt->execute([':n' => $nombre]);
    $previa = $stmt->fetch();
    if ($previa) {
        json_error('La migracion ya fue aplicada el ' . ($previa['aplicada'] ?? '?') . '.', 409);
    }

    $hash = hash('sha256', $sql);
    $t0   = microtime(true);

    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        if (function_exists('registrarSuceso')) {
            registrarSuceso(
                $pdo,
                'Migrador DB',
                'error',
                'Fallo la migracion «' . $nombre . '»: ' . $e->getMessage()
            );
        }
        json_error('Error al ejecutar la migracion: ' . $e->getMessage(), 500);
    }

    $duracionMs = (int) round((microtime(true) - $t0) * 1000);
    $aplicada   = date('Y-m-d H:i:s');

    $ins = $pdo->prepare(
        'INSERT INTO migraciones (nombre, hash, aplicada) VALUES (:n, :h, :a)'
    );
    $ins->execute([':n' => $nombre, ':h' => $hash, ':a' => $aplicada]);

    json_ok([
        'nombre'      => $nombre,
        'hash'        => $hash,
        'aplicada'    => $aplicada,
        'duracion_ms' => $duracionMs,
    ]);
} catch (Throwable $e) {
    json_error('Error al aplicar migracion: ' . $e->getMessage(), 500);
}
