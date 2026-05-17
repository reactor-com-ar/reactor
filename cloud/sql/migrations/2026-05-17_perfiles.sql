-- Migracion idempotente: introduce la tabla `perfiles`, que asocia un
-- usuario con un dominio y define su rol dentro de ese dominio
-- (administrador u operador). Es N:M usuario-dominio con un dato
-- extra (`rol`), por eso es tabla propia y no una FK simple.
--
-- El campo `usuarios.rol` sigue existiendo y representa el rol global
-- del usuario en la app (admin / operador / lectura); `perfiles.rol`
-- es el rol *por dominio*. Los dos coexisten.

USE reactor_dev;

CREATE TABLE IF NOT EXISTS perfiles (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT UNSIGNED NOT NULL,
    dominio_id  INT UNSIGNED NOT NULL,
    rol         ENUM('admin','operador') NOT NULL DEFAULT 'operador',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuario_dominio (usuario_id, dominio_id),
    INDEX idx_usuario_id (usuario_id),
    INDEX idx_dominio_id (dominio_id),
    INDEX idx_rol (rol),
    CONSTRAINT fk_perfiles_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_perfiles_dominio
        FOREIGN KEY (dominio_id) REFERENCES dominios(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Seed demo: admin tiene perfil de admin en todos los dominios;
-- operador tiene perfil de operador en planta baja y acceso/perimetro.
INSERT IGNORE INTO perfiles (usuario_id, dominio_id, rol)
SELECT u.id, d.id, 'admin'
FROM usuarios u CROSS JOIN dominios d
WHERE u.email = 'admin@reactor.com.ar';

INSERT IGNORE INTO perfiles (usuario_id, dominio_id, rol)
SELECT u.id, d.id, 'operador'
FROM usuarios u CROSS JOIN dominios d
WHERE u.email = 'operador@reactor.com.ar'
  AND d.nombre IN ('Planta baja', 'Acceso y perimetro');
