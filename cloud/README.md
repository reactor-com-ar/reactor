# cloud · Reactor backoffice

Backoffice SPA (PHP puro + Bootstrap 5) para la plataforma de control de dispositivos IoT **Reactor**.

## Stack

- PHP 8.x (sin framework)
- MySQL 5.7+ / 8.x
- Bootstrap 5.3 + Bootstrap Icons (via CDN)
- Frontend SPA con router por hash (`#/dashboard`, `#/dispositivos`, ...)

## Estructura

```
cloud/
├── api/
│   ├── bootstrap.php   # PDO + helpers JSON (lee DB_* del entorno)
│   └── dispositivos.php # GET listado + summary
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

| Metodo | Path                  | Descripcion                                                 |
| ------ | --------------------- | ----------------------------------------------------------- |
| GET    | `/api/dispositivos.php` | Listado de `dispositivos` + resumen + dominio + `config_json` (parseado) |
| PUT    | `/api/dispositivos.php` | Actualiza `config_json` de un dispositivo. Body JSON: `{id, config_json}` (`config_json` puede ser cualquier estructura JSON o `null`; hasta 64 KB serializado) |
| GET    | `/api/dominios.php`    | Listado de `dominios` con `dispositivos_count`              |
| POST   | `/api/dominios.php`    | Crea dominio. Body JSON: `{nombre, descripcion?}`           |
| PUT    | `/api/dominios.php`    | Actualiza dominio. Body JSON: `{id, nombre, descripcion?}`  |
| DELETE | `/api/dominios.php?id=N` | Elimina dominio (falla si tiene dispositivos)            |
| GET    | `/api/users.php`      | Listado de `usuarios` + resumen por rol                     |
| POST   | `/api/users.php`      | Crea usuario. Body JSON: `{email, nombre, rol, activo?, password}` |
| PUT    | `/api/users.php`      | Actualiza usuario. Body JSON: `{id, email, nombre, rol, activo?, password?}` |
| DELETE | `/api/users.php?id=N` | Elimina usuario (falla si es el unico admin activo)         |
| GET    | `/api/profiles.php`    | Listado de `perfiles` (usuario+dominio+rol) + resumen      |
| POST   | `/api/profiles.php`    | Crea perfil. Body JSON: `{usuario_id, dominio_id, rol}`    |
| PUT    | `/api/profiles.php`    | Actualiza rol del perfil. Body JSON: `{id, rol}`           |
| DELETE | `/api/profiles.php?id=N` | Elimina perfil                                          |
| GET    | `/api/signals.php`     | Listado de `senales` enviadas por los dispositivos + resumen (total, últimas 24 h, hoy, dispositivos activos). Query opcional: `dispositivo` (FK a `dispositivos.id`), `limit` (default 100, max 2000) |
| GET    | `/api/transceptores.php` | Listado de `transceptores` + resumen (total, con credenciales, con señales). `contrasena` nunca se expone; se devuelve solo el booleano `tiene_contrasena` |
| POST   | `/api/transceptores.php` | Crea transceptor. Body JSON: `{nombre, host, puerto, usuario?, contrasena?, entrada?}` |
| PUT    | `/api/transceptores.php` | Actualiza transceptor. Body JSON: `{id, nombre, host, puerto, usuario?, contrasena?, entrada?}`. Si `contrasena` viene vacía/ausente se mantiene la actual |
| DELETE | `/api/transceptores.php?id=N` | Elimina transceptor (falla si hay `senales` que lo referencian) |

## Tablas

Todos los nombres de tablas y campos viven principalmente en espanol
(los timestamps estandar `created_at` / `updated_at` / `last_seen_at`
y los valores de ENUM `online`/`offline`/`error` se mantienen en
ingles por convencion).

| Tabla          | Campos principales                                                                   |
| -------------- | ------------------------------------------------------------------------------------- |
| `dominios`     | `id`, `nombre`, `descripcion`                                                         |
| `dispositivos` | `id`, `uid`, `dominio_id` (FK), `nombre`, `tipo`, `ubicacion`, `estado`, `config_json` (JSON libre editable desde la UI), `last_seen_at` |
| `usuarios`     | `id`, `email`, `nombre`, `password_hash`, `rol`, `activo`, `last_login_at`            |
| `perfiles`     | `id`, `usuario_id` (FK), `dominio_id` (FK), `rol` (`admin`/`operador`) — UNIQUE `(usuario_id, dominio_id)` |
| `senales`      | `id`, `serie`, `fecha`, `sentido` (`I`/`O`), `transceptor` (FK), `dispositivo` (FK a `dispositivos.id`), `canal`, `topic`, `mensaje`, `estado` — historial inmutable de mensajes generados por los dispositivos (ver `db/schema.sql`) |
| `transceptores`| `id`, `nombre`, `host`, `puerto`, `usuario`, `contrasena`, `entrada` — gateways (MQTT / SMS / etc.) que reciben y entregan señales hacia los dispositivos. `senales.transceptor` es FK lógica a esta tabla |

## Proximos pasos sugeridos

- CRUD de dispositivos (POST/PUT/DELETE).
- Ingesta de telemetria y graficos en el dashboard.
- Modulo de alertas y notificaciones.
