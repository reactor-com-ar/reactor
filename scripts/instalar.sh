#!/bin/bash
# ============================================================
# instalar.sh - Rebuild idempotente del stack local Docker (Reactor).
#
# Que hace:
#   - Verifica que Docker este disponible.
#   - Detecta puertos libres para la app (host) y MySQL.
#   - Escribe .env con los puertos elegidos.
#   - Recrea el stack desde cero (down -v + up -d --build).
#   - Aplica las migraciones de cloud/sql/migrations/*.sql.
#   - Imprime la URL donde se sirve la app.
#
# Pensado para VSCode > Tasks > "instalar" o manualmente desde
# Git Bash:
#   bash ./scripts/instalar.sh
# ============================================================

set -e

# --- Repo root --------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

# --- Colores ----------------------------------------------------------------
RED='\033[1;31m'
GREEN='\033[1;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo ""
echo -e "${RED}==> Reactor :: instalar (rebuild local)${NC}"
echo "    repo: $REPO_ROOT"
echo ""

# --- Pre-flight: docker -----------------------------------------------------
if ! docker version --format '{{.Server.Version}}' > /dev/null 2>&1; then
    echo -e "${RED}ERROR: Docker no esta corriendo o no es accesible. Inicia Docker Desktop e intenta de nuevo.${NC}"
    exit 1
fi

# --- Helpers ----------------------------------------------------------------
# Devuelve 0 si el puerto esta en uso (algo escucha en 127.0.0.1:port),
# 1 si esta libre. Usa el builtin /dev/tcp/ de bash (disponible en Git Bash).
port_in_use() {
    local port="$1"
    (echo > "/dev/tcp/127.0.0.1/$port") > /dev/null 2>&1
}

# Devuelve el primer puerto libre desde $1 hacia arriba, saltando los de $2
# (lista separada por espacios).
find_free_port() {
    local start="$1"
    local reserved="$2"
    local max=500
    local p
    for ((p = start; p < start + max; p++)); do
        if [[ " $reserved " == *" $p "* ]]; then
            continue
        fi
        if ! port_in_use "$p"; then
            echo "$p"
            return 0
        fi
    done
    echo "No se encontro puerto libre en el rango $start..$((start + max - 1))" >&2
    return 1
}

# --- Elegir puertos ---------------------------------------------------------
# Cloud preferido en 8086 (convencion del proyecto). Si esta ocupado, busca
# el siguiente libre hacia arriba. Para MQTT/dashboard usa los defaults
# estandar de EMQX y si chocan (ej. otro broker corriendo) salta al siguiente.
APP_PORT=$(find_free_port 8086)
DB_PORT=$(find_free_port 3307 "$APP_PORT")
MQTT_PORT=$(find_free_port 1883 "$APP_PORT $DB_PORT")
EMQX_DASHBOARD_PORT=$(find_free_port 18083 "$APP_PORT $DB_PORT $MQTT_PORT")

echo -e "${RED}==> Puertos elegidos:${NC}"
echo "    app       -> $APP_PORT"
echo "    mysql     -> $DB_PORT"
echo "    mqtt      -> $MQTT_PORT"
echo "    dashboard -> $EMQX_DASHBOARD_PORT"
echo ""

# --- Escribir .env ----------------------------------------------------------
cat > .env << EOF
# Generado por scripts/instalar.sh - no editar a mano
REACTOR_APP_PORT=$APP_PORT
REACTOR_DB_PORT=$DB_PORT
REACTOR_MQTT_PORT=$MQTT_PORT
REACTOR_EMQX_DASHBOARD_PORT=$EMQX_DASHBOARD_PORT
EOF

# --- Limpiar contenedores previos -------------------------------------------
# El orden importa:
#   1) `docker compose down -v --remove-orphans` SIEMPRE primero. Borra
#      contenedores, red y -- critico -- el volumen `reactor-mysql-data` del
#      proyecto. Si saltearamos esto el volumen sobrevive y MySQL no vuelve
#      a correr schema.sql en el siguiente up (initdb solo se ejecuta en
#      DB virgen), dejando el schema desactualizado.
#   2) `docker rm -f` como fallback por si quedaron contenedores con esos
#      nombres pero sin label de compose.
echo -e "${RED}==> Limpiando contenedores previos...${NC}"

docker compose -p reactor down -v --remove-orphans > /dev/null 2>&1 || true

existing=$(docker ps -a --format '{{.Names}}')
for name in reactor reactor-apache reactor-mysql reactor-emqx; do
    if echo "$existing" | grep -qx "$name"; then
        echo "    removiendo $name (huerfano sin label compose)"
        docker rm -f "$name" > /dev/null
    fi
done

# --- Build & up -------------------------------------------------------------
echo ""
echo -e "${RED}==> docker compose up -d --build${NC}"
if ! docker compose -p reactor up -d --build; then
    echo ""
    echo -e "${RED}ERROR: docker compose fallo.${NC}"
    echo -e "${YELLOW}--- docker ps -a (reactor) ---${NC}"
    docker ps -a --filter "name=reactor" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
    echo ""
    echo -e "${YELLOW}--- docker logs reactor-apache (ultimas 50 lineas) ---${NC}"
    docker logs --tail 50 reactor-apache 2>&1 || true
    echo ""
    echo -e "${YELLOW}--- docker logs reactor-mysql (ultimas 50 lineas) ---${NC}"
    docker logs --tail 50 reactor-mysql 2>&1 || true
    exit 1
fi

# --- Esperar que la app responda --------------------------------------------
echo ""
echo -e "${RED}==> Esperando a que la app responda en http://localhost:$APP_PORT ...${NC}"
deadline=$(( $(date +%s) + 60 ))
ok=false
while [ "$(date +%s)" -lt "$deadline" ]; do
    code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 2 "http://localhost:$APP_PORT/" 2>/dev/null || echo "000")
    if [ "$code" = "200" ]; then
        ok=true
        break
    fi
    sleep 1
done

# --- Aplicar migraciones ----------------------------------------------------
# `docker-entrypoint-initdb.d` SOLO procesa archivos en su raiz y SOLO la
# primera vez (DB virgen), asi que las migraciones de cloud/sql/migrations/
# nunca se ejecutarian solas. Las corremos siempre, en orden alfabetico.
# Todas estan escritas como idempotentes (chequean information_schema antes
# de ALTER), asi que reaplicarlas es no-op.
migrations_dir="$REPO_ROOT/cloud/sql/migrations"
if [ -d "$migrations_dir" ]; then
    echo ""
    echo -e "${RED}==> Aplicando migraciones de cloud/sql/migrations/ ...${NC}"
    for m in "$migrations_dir"/*.sql; do
        [ -f "$m" ] || continue
        name=$(basename "$m")
        echo "    $name"
        if ! docker cp "$m" "reactor-mysql:/tmp/migration.sql" > /dev/null; then
            echo -e "${RED}ERROR copiando $name al contenedor reactor-mysql${NC}"
            exit 1
        fi
        if ! docker exec reactor-mysql sh -c 'mysql -uroot -proot reactor_dev < /tmp/migration.sql'; then
            echo -e "${RED}ERROR aplicando $name${NC}"
            exit 1
        fi
    done
    docker exec reactor-mysql rm -f /tmp/migration.sql > /dev/null || true
fi

# --- Sembrar usuario MQTT en EMQX (idempotente, via API) --------------------
# EMQX expone su dashboard API en el puerto que elegimos arriba (puede no ser
# 18083 si otro broker ya lo tenia). El seeder hace POST -> si 409, PUT, asi
# que se puede reaplicar siempre.
echo ""
echo -e "${RED}==> Sembrando usuario MQTT en EMQX${NC}"
if EMQX_DASHBOARD_PORT="$EMQX_DASHBOARD_PORT" bash "$REPO_ROOT/scripts/lib/emqx_seed.sh" "$REPO_ROOT/.env.development"; then
    :
else
    echo -e "${YELLOW}    AVISO: el seeder de EMQX fallo -- revisa logs con: docker logs reactor-emqx${NC}"
fi

# --- Resumen ----------------------------------------------------------------
echo ""
if $ok; then
    echo -e "${GREEN}==> Listo.${NC}"
else
    echo -e "${YELLOW}==> Stack arriba, pero la app aun no responde. Revisa logs con: docker logs reactor-apache${NC}"
fi

echo ""
echo -e "${GREEN}  Cloud   : http://localhost:$APP_PORT${NC}"
echo "  MySQL   : localhost:$DB_PORT  (user: root / pass: root / db: reactor_dev)"
echo "  EMQX    : MQTT localhost:$MQTT_PORT / Dashboard http://localhost:$EMQX_DASHBOARD_PORT (admin / rf412F576xWUgvLs)"
echo ""
echo "  Logs    : docker logs -f reactor-apache"
echo "  Down    : docker compose -p reactor down"
echo ""
