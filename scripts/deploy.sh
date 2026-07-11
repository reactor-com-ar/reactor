#!/bin/bash
# ============================================================
# deploy.sh - Sincroniza la app al servidor reactor
# Host objetivo:  paloalto.reactor.com.ar
# URL servida:    https://cloud.reactor.com.ar
#
# Uso:
#   bash deploy.sh           # sync + recreate
#   bash deploy.sh --rebuild # ademas reconstruye la imagen Docker
#                            # (necesario si cambio docker/Dockerfile)
# ============================================================

set -e

HOST="paloalto.reactor.com.ar"
USER="ec2-user"
KEY="/c/Users/Javier/OneDrive/Temp/Llaves/reactor/reactor.pem"
BASE_LOCAL="$(cd "$(dirname "$0")/.." && pwd)"
BASE_REMOTE="/opt/app/reactor"
COMPOSE_FILE="docker-compose.prod.yml"   # generado por aprovisionar_server.sh

REBUILD=false
if [ "$1" == "--rebuild" ]; then
    REBUILD=true
fi

VERSION="1.0.$(date +%s)"

echo ""
echo "================================================"
echo "  Deploy reactor -- version: $VERSION"
echo "  Host: $HOST"
echo "================================================"
echo ""

# ---- 1. version.txt en cloud/ y panel/ ----
echo "$VERSION" > "$BASE_LOCAL/cloud/version.txt"
echo "$VERSION" > "$BASE_LOCAL/panel/version.txt"
echo "  version.txt actualizado en cloud/ y panel/"
echo ""

# ---- 2. Verificar artefactos requeridos ----
for f in .env.production env.php docker/Dockerfile cloud panel; do
    if [ ! -e "$BASE_LOCAL/$f" ]; then
        echo "ERROR: falta $BASE_LOCAL/$f"
        exit 1
    fi
done

# ---- 3. Subir cloud/, panel/, docker/, db/, .env.production, env.php ----
# NO subimos docker-compose.yml: en el servidor vive docker-compose.prod.yml,
# generado por aprovisionar_server.sh (sin servicio reactor-db).
# .env.production y env.php se suben en cada deploy para mantener prod en sync.
# env.php es require_once'd desde cloud/index.php y panel/index.php y carga
# las constantes que leen las apps (APP_KEY_*, DB_*, MQTT_*) -- sin el, prod
# queda 500.
echo "  Subiendo cloud/, panel/, docker/, db/, .env.production y env.php (mirror con --delete)..."
cd "$BASE_LOCAL"

# db/ se incluye porque CLAUDE.md lo declara como schema de referencia.
# Si no existe (proyecto recien clonado en otra maquina), se omite.
INCLUDE_DB=""
if [ -d "$BASE_LOCAL/db" ]; then
    INCLUDE_DB="db"
fi

# Sync con borrado: si un archivo (o carpeta) no esta en local, tampoco
# debe quedar en el server. `tar -xzf` solo extrae encima (aditivo), por
# eso el flujo es:
#   (1) tar local -> stdin del ssh
#   (2) en remoto: extraer a un staging temporal
#   (3) en remoto: rsync -a --delete de staging hacia BASE_REMOTE por
#       carpeta (acota el alcance, evita tocar otras carpetas del server)
#   (4) en remoto: limpiar staging
# rsync vive en el server (Amazon Linux lo trae por default); no hace
# falta tenerlo instalado en local.
STAGING="/tmp/reactor-deploy-$(date +%s)"

tar \
    --exclude='./cloud/.git' \
    --exclude='./cloud/node_modules' \
    --exclude='./cloud/vendor' \
    --exclude='./panel/.git' \
    --exclude='./panel/node_modules' \
    --exclude='./panel/vendor' \
    --exclude='*.log' \
    --exclude='*.pem' \
    --exclude='*.key' \
    -czf - cloud panel docker $INCLUDE_DB .env.production env.php | \
ssh -i "$KEY" -o StrictHostKeyChecking=no \
    "$USER@$HOST" "
        set -e
        mkdir -p '$STAGING'
        tar -xzf - -C '$STAGING/'
        for dir in cloud panel docker $INCLUDE_DB; do
            if [ -d \"$STAGING/\$dir\" ]; then
                rsync -a --delete \"$STAGING/\$dir/\" \"$BASE_REMOTE/\$dir/\"
            fi
        done
        for f in .env.production env.php; do
            # Si Docker hizo bind-mount cuando el archivo no existia, el
            # path en el host quedo como directorio vacio. Lo removemos
            # antes de copiar el archivo nuevo.
            if [ -d \"$BASE_REMOTE/\$f\" ]; then
                rm -rf \"$BASE_REMOTE/\$f\"
            fi
            if [ -f \"$STAGING/\$f\" ]; then
                cp -f \"$STAGING/\$f\" \"$BASE_REMOTE/\$f\"
            fi
        done
        rm -rf '$STAGING'
    "
echo "  OK"
echo ""

# ---- 4. Rebuild (opcional) + force-recreate del contenedor ----
# force-recreate siempre: Docker bind-montea .env.production y env.php por
# inodo, no por path. El cp -f del paso 3 crea inodos nuevos, asi que sin
# --force-recreate el contenedor sigue viendo los archivos viejos. Es
# barato (~2s).
if [ "$REBUILD" = true ]; then
    echo "  Reconstruyendo imagen Docker y recreando contenedor..."
    ssh -i "$KEY" -o StrictHostKeyChecking=no "$USER@$HOST" \
        "cd '$BASE_REMOTE' && docker compose -f $COMPOSE_FILE build && docker compose -f $COMPOSE_FILE up -d --force-recreate"
    echo "  OK -- imagen reconstruida y contenedor levantado"
else
    echo "  Recreando contenedor..."
    ssh -i "$KEY" -o StrictHostKeyChecking=no "$USER@$HOST" \
        "cd '$BASE_REMOTE' && docker compose -f $COMPOSE_FILE up -d --force-recreate"
    echo "  OK -- contenedor actualizado"
fi
echo ""

# ---- 5. Migraciones SQL ----
# Las migraciones viven en cloud/sql/migrations/ y son idempotentes.
# Como el contenedor PHP no trae cliente mysql, se aplican manualmente
# desde un host con acceso a RDS, por ejemplo:
#   for f in cloud/sql/migrations/*.sql; do
#       mysql -h <RDS_HOST> -u <USER> -p<PASS> reactor < "$f"
#   done
echo "  Migraciones SQL: aplicar manualmente contra RDS (ver comentario en deploy.sh)."
echo ""

echo "================================================"
echo "  Deploy completo"
echo "    cloud: https://cloud.reactor.com.ar"
echo "    panel: https://panel.reactor.com.ar"
echo "================================================"
echo ""
