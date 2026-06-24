# motor

Worker Python que escucha al broker MQTT de Reactor y procesa los mensajes
que envian los dispositivos IoT.

## Stack

- Python 3.12 (slim)
- `paho-mqtt` 2.x (cliente MQTT)
- `PyMySQL` (driver MySQL puro python, sin libs nativas)

## Arquitectura

- Container `reactor-motor` levantado por `docker-compose.yml`.
- Bind `./motor:/app` -- cambios en `*.py` se reflejan al reiniciar el container
  (sin rebuild de imagen, salvo que cambies `requirements.txt`).
- Lee credenciales (MQTT/DB) del `.env.development` / `.env.production` via
  `env_file`.
- Conexion MQTT: por DNS interno de docker (`reactor-emqx:1884` en dev,
  `reactor-emqx:16273` en prod).
- Conexion MySQL: idem (`reactor-mysql:3308` en dev; en prod va al RDS via
  `DB_HOST` del `.env.production`).

## Comandos

```bash
# Build + up del container motor
docker compose -p reactor up -d --build motor

# Ver logs en vivo
docker logs -f reactor-motor

# Reiniciar (toma cambios de main.py sin rebuild)
docker compose -p reactor restart motor

# Rebuild (solo si cambiaste requirements.txt o Dockerfile)
docker compose -p reactor up -d --build motor
```

## Estado actual

`main.py` esta en estado **esqueleto**: conecta a MQTT, suscribe a topic `#`
(todos), valida conexion a MySQL y **loguea** cada mensaje recibido por
stdout. **No inserta en la base** -- la logica de persistencia esta marcada
con TODO en `on_message()`, hay que completarla segun el formato real de los
payloads (JSON vs texto plano, mapeo a columnas de `senales`, etc.).

## Configuracion

Variables de entorno:

| Variable             | Default            | Notas |
|----------------------|--------------------|-------|
| `MQTT_HOST`          | (requerida)        | DNS interno docker en dev/prod |
| `MQTT_PORT`          | (requerida)        | 1884 dev, 16273 prod |
| `MQTT_USER`          | (requerida)        | Mismo usuario que EMQX seed |
| `MQTT_PASS`          | (requerida)        | Idem |
| `MQTT_TOPIC_FILTER`  | `#`                | Topic al que se suscribe |
| `MQTT_CLIENT_ID`     | `reactor-motor`    | Identificador del cliente MQTT |
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` | (requeridas) | Conexion MySQL |
