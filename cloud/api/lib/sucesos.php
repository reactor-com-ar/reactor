<?php

declare(strict_types=1);

// Helper de escritura del log de actividad (`sucesos_log`). Cualquier
// modulo del panel cloud puede llamar a registrarSuceso() para dejar
// constancia de un evento; el Visor de sucesos lo muestra despues.
//
// Diseñado para NO propagar errores: si la tabla no existe todavia o el
// INSERT falla, el helper swallowea. Un fallo en el log nunca debe
// romper el flujo de negocio de quien lo llama.

const TIPOS_SUCESO = ['info', 'error', 'alerta'];

/**
 * Registra una fila en `sucesos_log`. `tipo` se whitelistea contra
 * TIPOS_SUCESO; cualquier otro valor se normaliza a `info`. `origen` es
 * opcional (VARCHAR(50)); se recorta si excede.
 */
function registrarSuceso(PDO $pdo, string $origen, string $tipo, string $detalle): void
{
    try {
        $tipo = in_array($tipo, TIPOS_SUCESO, true) ? $tipo : 'info';
        $origen = $origen !== '' ? substr($origen, 0, 50) : null;

        $stmt = $pdo->prepare(
            'INSERT INTO sucesos_log (fecha, origen, tipo, detalle)
             VALUES (NOW(), :origen, :tipo, :detalle)'
        );
        $stmt->execute([
            ':origen'  => $origen,
            ':tipo'    => $tipo,
            ':detalle' => $detalle,
        ]);
    } catch (Throwable $_) {
        // Swallow: el logging no puede romper el flujo del caller.
        // El error queda en el error_log de PHP.
        error_log('registrarSuceso fallo: ' . $_->getMessage());
    }
}
