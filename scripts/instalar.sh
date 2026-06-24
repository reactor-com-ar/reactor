#!/bin/bash
# ============================================================
# instalar.sh - Rebuild idempotente del stack local Docker (Reactor).
#
# Que hace:
#   - Verifica que Docker este disponible.
#   - Verifica que el MySQL externo (herramientas-mysql) responda.
#   - Recrea el stack desde cero (down -v + up -d --build).
#   - Aplica schema.sql + migraciones contra el MySQL externo.
#   - Imprime la URL donde se sirve la app.
#
# La BD MySQL ya no esta en este compose: corre en el contenedor
# del repo `herramientas-mysql`. Las creds se leen de .env.development.
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

# --- Cargar creds de BD desde .env.development ------------------------------
# El MySQL ya no esta en este compose: corre en el contenedor de
# herramientas-mysql. Leemos DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS del
# .env para pinguear y aplicar migraciones via cliente mysql efimero.
ENV_FILE_PATH="$REPO_ROOT/.env.development"
if [ ! -f "$ENV_FILE_PATH" ]; then
    echo -e "${RED}ERROR: no existe $ENV_FILE_PATH${NC}"
    exit 1
fi
set -a
# shellcheck disable=SC1090
. "$ENV_FILE_PATH"
set +a
: "${DB_HOST:?DB_HOST no definido en $ENV_FILE_PATH}"
: "${DB_PORT:?DB_PORT no definido en $ENV_FILE_PATH}"
: "${DB_NAME:?DB_NAME no definido en $ENV_FILE_PATH}"
: "${DB_USER:?DB_USER no definido en $ENV_FILE_PATH}"
: "${DB_PASS:?DB_PASS no definido en $ENV_FILE_PATH}"

# --- Puertos (todos hardcodeados en docker-compose.yml) ---------------------
# Dev:  app=8086, mqtt=1884, dashboard=18084.
# Prod: app=8086, mqtt=16273, dashboard=18083.
# Apache (8086) es igual en dev y prod. EMQX usa puertos distintos en dev
# (libres, no chocan con vigicom-emqx) y prod (16273 publico). Si alguno
# esta ocupado, el up falla -- liberar el otro proceso, NO remapear.
# MySQL lo provee el stack `herramientas-mysql` en $DB_HOST:$DB_PORT.
APP_PORT=8086
MQTT_PORT=1884
EMQX_DASHBOARD_PORT=18084

echo -e "${RED}==> Servicios:${NC}"
echo "    app       -> $APP_PORT"
echo "    mysql     -> $DB_HOST:$DB_PORT (externo / herramientas-mysql)"
echo "    mqtt      -> $MQTT_PORT"
echo "    dashboard -> $EMQX_DASHBOARD_PORT"
echo ""

# --- Pre-flight: MySQL externo ----------------------------------------------
# Pinguea el MySQL de herramientas-mysql via cliente efimero. Si no responde,
# los contenedores reactor/reactor-motor van a fallar en runtime al conectar.
echo -e "${RED}==> Verificando MySQL externo en $DB_HOST:$DB_PORT ...${NC}"
if ! docker run --rm \
        -e MYSQL_PWD="$DB_PASS" \
        --add-host=host.docker.internal:host-gateway \
        mysql:8.0 \
        mysqladmin -h "$DB_HOST" -P "$DB_PORT" -u"$DB_USER" --connect-timeout=5 ping > /dev/null 2>&1; then
    echo -e "${RED}ERROR: no se pudo conectar a $DB_HOST:$DB_PORT como $DB_USER.${NC}"
    echo -e "${YELLOW}Asegurate de que el stack herramientas-mysql este levantado y que las creds en .env.development sean correctas.${NC}"
    exit 1
fi

# --- Limpiar contenedores previos -------------------------------------------
# El orden importa:
#   1) `docker compose down -v --remove-orphans` SIEMPRE primero. Borra
#      contenedores, red y los volumenes propios del proyecto (solo
#      `reactor-emqx-data` desde que MySQL salio del compose). NO toca el
#      MySQL externo de herramientas-mysql.
#   2) `docker rm -f` como fallback por si quedaron contenedores con esos
#      nombres pero sin label de compose.
echo -e "${RED}==> Limpiando contenedores previos...${NC}"

docker compose -p reactor down -v --remove-orphans > /dev/null 2>&1 || true

existing=$(docker ps -a --format '{{.Names}}')
for name in reactor reactor-apache reactor-emqx reactor-motor; do
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
    echo -e "${YELLOW}--- docker logs reactor-emqx (ultimas 50 lineas) ---${NC}"
    docker logs --tail 50 reactor-emqx 2>&1 || true
    exit 1
fi

# --- Esperar que la app responda --------------------------------------------
# Aceptamos cualquier 2xx o 3xx: el cloud usa JWT con redirect a /login
# cuando no hay cookie, asi que GET / responde 302, no 200. Lo que importa
# es que Apache este sirviendo (no 000 / no 5xx).
echo ""
echo -e "${RED}==> Esperando a que la app responda en http://localhost:$APP_PORT ...${NC}"
deadline=$(( $(date +%s) + 60 ))
ok=false
while [ "$(date +%s)" -lt "$deadline" ]; do
    code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 2 "http://localhost:$APP_PORT/" 2>/dev/null || echo "000")
    if [[ "$code" =~ ^[23][0-9][0-9]$ ]]; then
        ok=true
        break
    fi
    sleep 1
done

# --- Helpers de MySQL externo -----------------------------------------------
# Ejecuta un archivo SQL contra el MySQL de herramientas-mysql usando un
# cliente mysql:8.0 efimero. No requiere mysql client local ni acopla a un
# nombre de contenedor especifico.
mysql_exec_file() {
    local sql_file="$1"
    local db="${2:-$DB_NAME}"
    docker run --rm -i \
        -e MYSQL_PWD="$DB_PASS" \
        --add-host=host.docker.internal:host-gateway \
        mysql:8.0 \
        mysql -h "$DB_HOST" -P "$DB_PORT" -u"$DB_USER" "$db" \
        < "$sql_file"
}

# --- Aplicar migraciones ----------------------------------------------------
# La BD ya existe en el MySQL externo (compartida con la app legacy de
# Reactor multi-app). No hay schema.sql que cargar: el cloud opera sobre las
# tablas legacy de la DB. Solo aplicamos migraciones, que estan escritas
# idempotentes (chequean information_schema antes de ALTER / usan
# CREATE TABLE IF NOT EXISTS).
migrations_dir="$REPO_ROOT/cloud/sql/migrations"
if [ -d "$migrations_dir" ]; then
    echo ""
    echo -e "${RED}==> Aplicando migraciones de cloud/sql/migrations/ ...${NC}"
    for m in "$migrations_dir"/*.sql; do
        [ -f "$m" ] || continue
        name=$(basename "$m")
        echo "    $name"
        if ! mysql_exec_file "$m"; then
            echo -e "${RED}ERROR aplicando $name${NC}"
            exit 1
        fi
    done
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
echo "  MySQL   : $DB_HOST:$DB_PORT  (externo / herramientas-mysql, db: $DB_NAME)"
echo "  EMQX    : MQTT localhost:$MQTT_PORT / Dashboard http://localhost:$EMQX_DASHBOARD_PORT (admin / rf412F576xWUgvLs)"
echo ""
echo "  Logs    : docker logs -f reactor-apache"
echo "  Down    : docker compose -p reactor down"
echo ""
