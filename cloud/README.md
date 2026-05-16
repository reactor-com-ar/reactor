# cloud · Reactor backoffice

Backoffice SPA (PHP puro + Bootstrap 5) para la plataforma de control de dispositivos IoT **Reactor**.

## Stack

- PHP 8.x (sin framework)
- MySQL 5.7+ / 8.x
- Bootstrap 5.3 + Bootstrap Icons (via CDN)
- Frontend SPA con router por hash (`#/dashboard`, `#/devices`, ...)

## Estructura

```
cloud/
├── api/
│   ├── bootstrap.php   # PDO + helpers JSON (lee DB_* del entorno)
│   └── devices.php     # GET listado + summary
├── assets/
│   ├── css/style.css   # tema rojo "reactor"
│   └── js/app.js       # router SPA + render de vistas
├── sql/
│   └── schema.sql      # DDL + datos de ejemplo
└── index.php           # shell HTML de la SPA
```

La configuracion de la base no vive en codigo: viene del archivo `.env.development`
o `.env.production` de la raiz del repo, inyectado al contenedor por
`docker-compose.yml` (clave `env_file`).

## Puesta en marcha

1. Crear la base de datos y datos de ejemplo:
   ```bash
   mysql -u root -p < cloud/sql/schema.sql
   ```
2. Ajustar credenciales en `cloud/config/database.php` si hace falta.
3. Levantar el server embebido de PHP apuntando a la carpeta `cloud/`:
   ```bash
   php -S 127.0.0.1:8000 -t cloud
   ```
4. Abrir <http://127.0.0.1:8000>.

## Endpoints actuales

| Metodo | Path                  | Descripcion                                    |
| ------ | --------------------- | ---------------------------------------------- |
| GET    | `/api/devices.php`    | Listado de dispositivos + summary por estado   |

## Proximos pasos sugeridos

- Autenticacion + tabla `users`.
- CRUD de dispositivos (POST/PUT/DELETE).
- Ingesta de telemetria y graficos en el dashboard.
- Modulo de alertas y notificaciones.
