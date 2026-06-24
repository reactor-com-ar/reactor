#!/bin/sh
# Mapea EMQX_DASHBOARD_PASS (nombre amigable en el .env) al nombre que EMQX
# espera (EMQX_DASHBOARD__DEFAULT_PASSWORD). Solo afecta el primer boot:
# despues el admin queda persistido en /opt/emqx/data y este var es ignorado.
#
# El usuario MQTT (MQTT_USER / MQTT_PASS) NO se crea desde aca - se siembra
# despues via scripts/lib/emqx_seed.sh (HTTP API, idempotente).
set -e
export EMQX_DASHBOARD__DEFAULT_PASSWORD="${EMQX_DASHBOARD_PASS}"
exec /usr/bin/docker-entrypoint.sh "$@"
