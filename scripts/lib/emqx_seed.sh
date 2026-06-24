#!/bin/bash
# ============================================================
# emqx_seed.sh - Upsert idempotente del usuario MQTT en EMQX.
#
# Lee MQTT_USER / MQTT_PASS / EMQX_DASHBOARD_PASS del .env que se le pasa,
# espera a que el dashboard de EMQX este listo (http://localhost:18083),
# obtiene un token JWT de admin y crea/actualiza el usuario via API.
#
# Idempotente: se puede invocar tantas veces como quieras.
#   - POST primero.
#   - Si el usuario ya existe (HTTP 409) cae a PUT y actualiza el password.
#
# Uso:
#   bash scripts/lib/emqx_seed.sh <ruta-al-.env>
#
# Ejemplos:
#   bash scripts/lib/emqx_seed.sh .env.development
#   bash scripts/lib/emqx_seed.sh /opt/app/reactor/.env.production
# ============================================================

set -eo pipefail

ENV_FILE="$1"
if [ -z "$ENV_FILE" ] || [ ! -f "$ENV_FILE" ]; then
    echo "ERROR: pasar como argumento la ruta del .env"
    exit 1
fi

# Source con export automatico
set -a
. "$ENV_FILE"
set +a

# Validar variables requeridas
for v in MQTT_USER MQTT_PASS EMQX_DASHBOARD_PASS; do
    val=$(eval "echo \$$v")
    if [ -z "$val" ]; then
        echo "ERROR: variable $v no esta definida en $ENV_FILE"
        exit 1
    fi
done

# Puerto del dashboard parametrizable: instalar.sh elige uno libre y nos lo
# pasa por env var (default 18083 cuando se invoca desde el server prod).
EMQX_DASHBOARD_PORT="${EMQX_DASHBOARD_PORT:-18083}"
EMQX_URL="http://localhost:${EMQX_DASHBOARD_PORT}"
AUTHN_ID="password_based:built_in_database"

# 1. Esperar dashboard API
echo "  Esperando a EMQX API (${EMQX_URL})..."
deadline=$(( $(date +%s) + 60 ))
ok=false
while [ "$(date +%s)" -lt "$deadline" ]; do
    if curl -sf -o /dev/null "${EMQX_URL}/api/v5/status"; then
        ok=true
        break
    fi
    sleep 1
done
if ! $ok; then
    echo "ERROR: EMQX no respondio en 60s"
    exit 1
fi
echo "    OK"

# Helper: login devuelve token o vacio
emqx_login() {
    local pass="$1"
    curl -sf -X POST "${EMQX_URL}/api/v5/login" \
        -H "Content-Type: application/json" \
        -d "{\"username\":\"admin\",\"password\":\"${pass}\"}" \
      | sed -n 's/.*"token"[ ]*:[ ]*"\([^"]*\)".*/\1/p'
}

# 2. Login admin. Probamos primero con EMQX_DASHBOARD_PASS del .env; si no anda,
# fallback al default de EMQX (admin:public) y en ese caso cambiamos la pass a
# la del .env -- asi en la siguiente corrida el primer intento ya funciona.
echo "  Obteniendo token admin..."
TOKEN=$(emqx_login "${EMQX_DASHBOARD_PASS}" || true)

if [ -z "$TOKEN" ]; then
    echo "    pass del .env no funciono, probando default admin:public..."
    TOKEN=$(emqx_login "public" || true)
    if [ -n "$TOKEN" ]; then
        echo "    OK con default -- cambiando admin pass a la del .env"
        chg_code=$(curl -s -o /dev/null -w "%{http_code}" -X POST \
            "${EMQX_URL}/api/v5/users/admin/change_pwd" \
            -H "Authorization: Bearer $TOKEN" \
            -H "Content-Type: application/json" \
            -d "{\"old_pwd\":\"public\",\"new_pwd\":\"${EMQX_DASHBOARD_PASS}\"}")
        if [ "$chg_code" = "200" ] || [ "$chg_code" = "204" ]; then
            echo "    Admin pass actualizada"
            TOKEN=$(emqx_login "${EMQX_DASHBOARD_PASS}" || true)
        else
            echo "    AVISO: no se pudo cambiar la admin pass (HTTP $chg_code) -- sigo con el token de default"
        fi
    fi
fi

if [ -z "$TOKEN" ]; then
    echo "ERROR: login a EMQX fallo con la pass del .env y con el default 'public'."
    echo "       Verificar EMQX_DASHBOARD_PASS en $ENV_FILE o resetear el volumen reactor-emqx-data."
    echo "       (Dashboard probado en ${EMQX_URL})"
    exit 1
fi
echo "    OK"

# 3. Asegurar que existe el autenticador password_based:built_in_database.
# Si EMQX arranco sin las env vars EMQX_AUTHENTICATION__1__* (caso raro pero
# posible), lo creamos aca. Idempotente: HTTP 409 == ya existe == OK.
echo "  Asegurando autenticador ${AUTHN_ID}..."
authn_code=$(curl -s -o /dev/null -w "%{http_code}" -X POST \
    "${EMQX_URL}/api/v5/authentication" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"mechanism":"password_based","backend":"built_in_database","user_id_type":"username","password_hash_algorithm":{"name":"sha256","salt_position":"suffix"}}')
case "$authn_code" in
    200|201|204)
        echo "    Creado"
        ;;
    409)
        echo "    Ya existia"
        ;;
    *)
        echo "    AVISO: respuesta inesperada (HTTP $authn_code) -- sigo con el upsert"
        ;;
esac

# 4. Upsert del usuario MQTT (POST -> si 409, PUT)
echo "  Upserteando usuario MQTT '$MQTT_USER'..."

code=$(curl -s -o /dev/null -w "%{http_code}" -X POST \
    "${EMQX_URL}/api/v5/authentication/${AUTHN_ID}/users" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d "{\"user_id\":\"$MQTT_USER\",\"password\":\"$MQTT_PASS\",\"is_superuser\":false}")

case "$code" in
    200|201)
        echo "    Usuario creado"
        ;;
    409)
        put_code=$(curl -s -o /dev/null -w "%{http_code}" -X PUT \
            "${EMQX_URL}/api/v5/authentication/${AUTHN_ID}/users/${MQTT_USER}" \
            -H "Authorization: Bearer $TOKEN" \
            -H "Content-Type: application/json" \
            -d "{\"password\":\"$MQTT_PASS\"}")
        if [ "$put_code" = "200" ] || [ "$put_code" = "204" ]; then
            echo "    Usuario actualizado"
        else
            echo "ERROR: PUT al usuario fallo (HTTP $put_code)"
            exit 1
        fi
        ;;
    *)
        echo "ERROR: POST de usuario fallo (HTTP $code)"
        exit 1
        ;;
esac

echo "  OK -- usuario MQTT '$MQTT_USER' listo en EMQX"
