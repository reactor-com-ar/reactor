<?php

declare(strict_types=1);

/**
 * Resumen de la cuenta para el Dashboard.
 *
 * GET api/dashboard.php -> cuantos usuarios, dispositivos y chips tiene
 *                          asociados el dominio de la sesion.
 *
 * ALCANCE: todo se acota al dominio de la sesion (requireDominioId()).
 *
 * Los conteos se calculan con COUNT(*) sobre cada tabla, NO se leen de las
 * columnas cacheadas `dominios.usuarios` / `.dispositivos` / `.chips`: esos
 * contadores los mantiene el sistema legacy y estan desfasados (p. ej. el
 * dominio 2 declara 18 usuarios y tiene 5). Las tres tablas tienen indice
 * por `dominio` (fk_*_dominio), asi que los COUNT son baratos.
 */

require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_error('Metodo no permitido', 405);
}

try {
    $dominio = requireDominioId();
    $ctx     = sessionContext() ?? [];

    $stmt = db()->prepare(
        'SELECT (SELECT COUNT(*) FROM usuarios     WHERE dominio = :dom1) AS usuarios,
                (SELECT COUNT(*) FROM dispositivos WHERE dominio = :dom2) AS dispositivos,
                (SELECT COUNT(*) FROM chips        WHERE dominio = :dom3) AS chips'
    );
    $stmt->execute([':dom1' => $dominio, ':dom2' => $dominio, ':dom3' => $dominio]);
    $r = $stmt->fetch() ?: ['usuarios' => 0, 'dispositivos' => 0, 'chips' => 0];

    json_ok([
        'dominio' => [
            'id'     => $dominio,
            'nombre' => (string) ($ctx['dominio_nombre'] ?? ''),
        ],
        'totales' => [
            'usuarios'     => (int) $r['usuarios'],
            'dispositivos' => (int) $r['dispositivos'],
            'chips'        => (int) $r['chips'],
        ],
    ]);
} catch (Throwable $e) {
    json_error('Error al obtener el resumen: ' . $e->getMessage(), 500);
}
