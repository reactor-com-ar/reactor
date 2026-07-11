<?php

declare(strict_types=1);

// Migrador DB — preview de una migracion.
// GET api/migraciones_get.php?nombre=YYYYMMDD_HHMM_xxx.sql
//   -> { nombre, contenido, tamano, hash }

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/migraciones.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') json_error('metodo_no_soportado', 405);

try {
    $nombre = trim((string) ($_GET['nombre'] ?? ''));
    if (!nombreMigracionValido($nombre)) {
        json_error('nombre_invalido', 400);
    }

    $ruta = migracionesDir() . '/' . $nombre;
    if (!is_file($ruta)) {
        json_error('migracion_no_encontrada', 404);
    }

    $contenido = (string) file_get_contents($ruta);
    json_ok([
        'nombre'    => $nombre,
        'contenido' => $contenido,
        'tamano'    => strlen($contenido),
        'hash'      => hash('sha256', $contenido),
    ]);
} catch (Throwable $e) {
    json_error('Error al leer migracion: ' . $e->getMessage(), 500);
}
