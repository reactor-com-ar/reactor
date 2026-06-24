"""
Worker MQTT de Reactor.

Suscribe a topics del broker EMQX y procesa los mensajes que reciben los
dispositivos IoT. Por defecto loguea cada mensaje por stdout y NO inserta
en MySQL -- la logica de persistencia se completa segun el formato real
de los mensajes (extender on_message).

Variables de entorno requeridas (todas vienen del .env de la raiz del repo
via docker-compose env_file):

    MQTT_HOST, MQTT_PORT, MQTT_USER, MQTT_PASS
    DB_HOST,   DB_PORT,   DB_NAME,   DB_USER, DB_PASS

Variables opcionales:

    MQTT_TOPIC_FILTER  (default: "#" -- todos los topics)
    MQTT_CLIENT_ID     (default: "reactor-motor")
"""

from __future__ import annotations

import json
import os
import signal
import sys
import time
from typing import Any

import paho.mqtt.client as mqtt
import pymysql


def env(name: str, default: str | None = None) -> str:
    val = os.environ.get(name, default)
    if val is None or val == "":
        sys.stderr.write(f"ERROR: variable de entorno {name!r} no definida\n")
        sys.exit(1)
    return val


MQTT_HOST = env("MQTT_HOST")
MQTT_PORT = int(env("MQTT_PORT"))
MQTT_USER = env("MQTT_USER")
MQTT_PASS = env("MQTT_PASS")
MQTT_TOPIC = os.environ.get("MQTT_TOPIC_FILTER", "#")
MQTT_CLIENT_ID = os.environ.get("MQTT_CLIENT_ID", "reactor-motor")

DB_HOST = env("DB_HOST")
DB_PORT = int(env("DB_PORT"))
DB_NAME = env("DB_NAME")
DB_USER = env("DB_USER")
DB_PASS = env("DB_PASS")


def get_db() -> pymysql.connections.Connection:
    return pymysql.connect(
        host=DB_HOST,
        port=DB_PORT,
        user=DB_USER,
        password=DB_PASS,
        database=DB_NAME,
        charset="utf8mb4",
        autocommit=True,
        cursorclass=pymysql.cursors.DictCursor,
    )


def on_connect(client: mqtt.Client, userdata: Any, flags, reason_code, properties=None) -> None:
    if reason_code == 0:
        print(f"[mqtt] conectado a {MQTT_HOST}:{MQTT_PORT} como {MQTT_USER!r}")
        client.subscribe(MQTT_TOPIC, qos=1)
        print(f"[mqtt] suscripto a topic {MQTT_TOPIC!r}")
    else:
        print(f"[mqtt] ERROR al conectar -- reason_code={reason_code}")


def on_disconnect(client: mqtt.Client, userdata: Any, disconnect_flags, reason_code, properties=None) -> None:
    print(f"[mqtt] desconectado -- reason_code={reason_code}")


def on_message(client: mqtt.Client, userdata: Any, msg: mqtt.MQTTMessage) -> None:
    payload_bytes = msg.payload
    try:
        payload_text = payload_bytes.decode("utf-8")
    except UnicodeDecodeError:
        payload_text = payload_bytes.hex()

    print(f"[mqtt] {msg.topic} -> {payload_text}")

    # TODO: parsear `payload_text` segun el formato real (JSON / texto plano)
    #       y persistir en la tabla `senales` (ver db/schema.sql).
    # Ejemplo:
    #   with get_db().cursor() as cur:
    #       cur.execute(
    #           "INSERT INTO senales (topic, mensaje, fecha) VALUES (%s, %s, NOW())",
    #           (msg.topic, payload_text),
    #       )


def main() -> None:
    # Sanity check: probar la conexion a MySQL al arrancar para fallar rapido
    # si las credenciales o el host estan mal.
    try:
        conn = get_db()
        with conn.cursor() as cur:
            cur.execute("SELECT 1 AS ok")
            cur.fetchone()
        conn.close()
        print(f"[db] conexion OK a {DB_USER}@{DB_HOST}:{DB_PORT}/{DB_NAME}")
    except Exception as e:
        print(f"[db] ERROR conectando a MySQL: {e}")
        sys.exit(1)

    client = mqtt.Client(
        callback_api_version=mqtt.CallbackAPIVersion.VERSION2,
        client_id=MQTT_CLIENT_ID,
        clean_session=True,
    )
    client.username_pw_set(MQTT_USER, MQTT_PASS)
    client.on_connect = on_connect
    client.on_disconnect = on_disconnect
    client.on_message = on_message

    # Reconexion automatica con backoff exponencial.
    client.reconnect_delay_set(min_delay=1, max_delay=60)

    # Graceful shutdown en SIGTERM/SIGINT.
    def shutdown(signum, frame):
        print(f"[motor] senal {signum} recibida -- cerrando...")
        client.disconnect()
        client.loop_stop()
        sys.exit(0)

    signal.signal(signal.SIGTERM, shutdown)
    signal.signal(signal.SIGINT, shutdown)

    print(f"[motor] conectando a MQTT {MQTT_HOST}:{MQTT_PORT}...")
    while True:
        try:
            client.connect(MQTT_HOST, MQTT_PORT, keepalive=60)
            break
        except Exception as e:
            print(f"[motor] connect fallo: {e} -- reintento en 5s")
            time.sleep(5)

    client.loop_forever()


if __name__ == "__main__":
    main()
