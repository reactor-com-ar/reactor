#!/bin/bash
# ============================================================
# deploy.sh - Sincroniza la app al servidor reactor
# Host objetivo:  paloalto.reactor.com.ar
# URLs servidas:  https://cloud.reactor.com.ar
#                 https://panel.reactor.com.ar
#                 https://app.reactor.com.ar   (app end-user;
#                                               alias pwa. / newapp. / webapp.)
#
# Uso:
#   bash deploy.sh           # solo sube cambios (NO toca los contenedores)
#   bash deploy.sh --restart # sube + recrea los contenedores
#   bash deploy.sh --rebuild # sube + reconstruye la imagen + recrea
#                            # (necesario si cambio docker/Dockerfile)
#
# El modo por defecto no reinicia nada: cloud/, panel/ y app/ estan
# bind-monteados como directorios, asi que el codigo nuevo queda vivo apenas
# termina el rsync. El script avisa al final si detecto un cambio que si
# requiere --restart o --rebuild.
# ============================================================

set -e

HOST="paloalto.reactor.com.ar"
USER="ec2-user"
KEY="/c/Users/Javier/OneDrive/Temp/Llaves/reactor/reactor.pem"
BASE_LOCAL="$(cd "$(dirname "$0")/.." && pwd)"
BASE_REMOTE="/opt/app/reactor"
COMPOSE_FILE="docker-compose.prod.yml"   # generado por aprovisionar_server.sh

# sync    -> solo sube archivos (default)
# restart -> sube + docker compose up -d --force-recreate
# rebuild -> sube + docker compose build + up -d --force-recreate
MODE="sync"
case "${1:-}" in
    ""|--sync)  MODE="sync"    ;;
    --restart)  MODE="restart" ;;
    --rebuild)  MODE="rebuild" ;;
    *)
        echo "ERROR: parametro desconocido '$1'"
        echo "       Uso: bash deploy.sh [--restart|--rebuild]"
        exit 1
        ;;
esac

VERSION="1.0.$(date +%s)"

echo ""
echo "================================================"
echo "  Deploy reactor -- version: $VERSION"
echo "  Host: $HOST"
case "$MODE" in
    sync)    echo "  Modo: sync (no se reinician contenedores)" ;;
    restart) echo "  Modo: restart (recrea contenedores)" ;;
    rebuild) echo "  Modo: rebuild (reconstruye imagen + recrea)" ;;
esac
echo "================================================"
echo ""

# ---- 1. version.txt en cloud/, panel/ y app/ ----
echo "$VERSION" > "$BASE_LOCAL/cloud/version.txt"
echo "$VERSION" > "$BASE_LOCAL/panel/version.txt"
echo "$VERSION" > "$BASE_LOCAL/app/version.txt"
echo "  version.txt actualizado en cloud/, panel/ y app/"
echo ""

# ---- 2. Verificar artefactos requeridos ----
for f in .env.production env.php docker/Dockerfile cloud panel app; do
    if [ ! -e "$BASE_LOCAL/$f" ]; then
        echo "ERROR: falta $BASE_LOCAL/$f"
        exit 1
    fi
done

# ---- 3. Subir cloud/, panel/, app/, docker/, db/, .env.production, env.php ----
# NO subimos docker-compose.yml: en el servidor vive docker-compose.prod.yml,
# generado por aprovisionar_server.sh (sin servicio reactor-db).
# .env.production y env.php se suben en cada deploy para mantener prod en sync.
# env.php es require_once'd desde cloud/index.php, panel/index.php y app/lib/
# db.php, y carga las constantes que leen las apps (APP_KEY_*, DB_*, MQTT_*)
# -- sin el, prod queda 500.
echo "  Subiendo cloud/, panel/, app/, docker/, db/, .env.production y env.php (mirror con --delete)..."
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

# El remoto emite marcadores REACTOR_*: los consumimos abajo para decidir
# si hay que avisar que este deploy necesita --restart / --rebuild.
SYNC_OUT="$(
tar \
    --exclude='./cloud/.git' \
    --exclude='./cloud/node_modules' \
    --exclude='./cloud/vendor' \
    --exclude='./panel/.git' \
    --exclude='./panel/node_modules' \
    --exclude='./panel/vendor' \
    --exclude='./app/.git' \
    --exclude='./app/node_modules' \
    --exclude='./app/vendor' \
    --exclude='*.log' \
    --exclude='*.pem' \
    --exclude='*.key' \
    -czf - cloud panel app docker $INCLUDE_DB .env.production env.php | \
ssh -i "$KEY" -o StrictHostKeyChecking=no \
    "$USER@$HOST" "
        set -e
        mkdir -p '$STAGING'
        tar -xzf - -C '$STAGING/'
        for dir in cloud panel app docker $INCLUDE_DB; do
            if [ -d \"$STAGING/\$dir\" ]; then
                # -i itemiza; filtramos a transferencias/borrados reales
                # (las lineas que empiezan con '.' son solo atributos).
                changed=\$(rsync -ai --delete \"$STAGING/\$dir/\" \"$BASE_REMOTE/\$dir/\" \
                          | grep -E '^(>|<|\*deleting)' || true)
                # docker/ define la imagen (Dockerfile, ports.conf, vhosts.conf):
                # si cambio, el contenedor corriendo quedo desactualizado.
                if [ \"\$dir\" = docker ] && [ -n \"\$changed\" ]; then
                    echo 'REACTOR_DOCKER_CHANGED'
                fi
            fi
        done
        # app/ es el ultimo docroot que se sumo: si el compose del server
        # todavia no lo bind-montea, subir los archivos no alcanza -- el
        # contenedor sirve /var/www/app vacio y app.reactor.com.ar da 404.
        if ! grep -q '/var/www/app' \"$BASE_REMOTE/$COMPOSE_FILE\" 2>/dev/null; then
            echo 'REACTOR_COMPOSE_SIN_APP'
        fi
        for f in .env.production env.php; do
            [ -f \"$STAGING/\$f\" ] || continue
            # Si Docker hizo bind-mount cuando el archivo no existia, el
            # path en el host quedo como directorio vacio. Lo removemos
            # antes de copiar el archivo nuevo.
            if [ -d \"$BASE_REMOTE/\$f\" ]; then
                rm -rf \"$BASE_REMOTE/\$f\"
            fi
            if [ -f \"$BASE_REMOTE/\$f\" ]; then
                if ! cmp -s \"$STAGING/\$f\" \"$BASE_REMOTE/\$f\"; then
                    # Escritura in-place: '>' trunca y reescribe el MISMO
                    # inodo. Docker bind-montea estos archivos por inodo, asi
                    # que el contenedor ve el contenido nuevo sin recrearse
                    # (un 'cp' que reemplace el inodo lo dejaria viendo el
                    # archivo viejo).
                    cat \"$STAGING/\$f\" > \"$BASE_REMOTE/\$f\"
                    echo \"REACTOR_ENV_CHANGED:\$f\"
                fi
            else
                # No existia (o era el directorio vacio que acabamos de
                # borrar): el bind-mount del contenedor apunta a otra cosa.
                cp -f \"$STAGING/\$f\" \"$BASE_REMOTE/\$f\"
                echo \"REACTOR_ENV_NEW:\$f\"
            fi
        done
        rm -rf '$STAGING'
    "
)"
echo "  OK"
echo ""

# ---- 4. Rebuild / recreate del contenedor (solo si se pidio) ----
# Por defecto NO se toca el contenedor. La gran mayoria de los deploys son
# cambios de codigo en cloud/ y panel/, y esas dos carpetas estan
# bind-monteadas como DIRECTORIOS: el contenedor resuelve cada archivo por
# path en cada request, asi que el codigo nuevo ya esta vivo al terminar el
# paso 3. (No hay opcache habilitado en la imagen -- ver docker/Dockerfile --
# asi que tampoco hay bytecode cacheado que invalidar.)
#
# .env.production y env.php se bind-montean por ARCHIVO, o sea por inodo. El
# paso 3 los reescribe in-place para no romper ese mount, de modo que env.php
# (require_once en cada request) tambien queda vivo sin recrear.
#
# Cuando SI hace falta recrear:
#   --restart : cambio .env.production. Las vars de 'env_file:' se inyectan
#               al proceso al CREAR el contenedor; el archivo nuevo no las
#               actualiza. Tambien si el bind-mount quedo roto (env NEW).
#   --rebuild : cambio docker/ (Dockerfile, ports.conf, vhosts.conf) o
#               cualquier cosa horneada en la imagen.
case "$MODE" in
    rebuild)
        echo "  Reconstruyendo imagen Docker y recreando contenedores..."
        ssh -i "$KEY" -o StrictHostKeyChecking=no "$USER@$HOST" \
            "cd '$BASE_REMOTE' && docker compose -f $COMPOSE_FILE build && docker compose -f $COMPOSE_FILE up -d --force-recreate"
        echo "  OK -- imagen reconstruida y contenedores levantados"
        ;;
    restart)
        echo "  Recreando contenedores..."
        ssh -i "$KEY" -o StrictHostKeyChecking=no "$USER@$HOST" \
            "cd '$BASE_REMOTE' && docker compose -f $COMPOSE_FILE up -d --force-recreate"
        echo "  OK -- contenedores recreados"
        ;;
    sync)
        echo "  Sin reinicio: cloud/, panel/ y app/ son bind-mounts, los cambios ya estan vivos."

        # Avisos: cambios que el sync solo NO alcanza a activar.
        AVISOS=""
        if echo "$SYNC_OUT" | grep -q '^REACTOR_DOCKER_CHANGED$'; then
            AVISOS="$AVISOS
    - Cambio docker/ (imagen): correr 'bash deploy.sh --rebuild' para activarlo."
        fi
        if echo "$SYNC_OUT" | grep -q '^REACTOR_ENV_CHANGED:\.env\.production$'; then
            AVISOS="$AVISOS
    - Cambio .env.production: las vars de env_file: se inyectan al crear el
      contenedor. Correr 'bash deploy.sh --restart' para activarlas."
        fi
        if echo "$SYNC_OUT" | grep -q '^REACTOR_ENV_NEW:'; then
            AVISOS="$AVISOS
    - Se creo un archivo de entorno que no existia en el server: el
      bind-mount del contenedor quedo apuntando al inodo viejo. Correr
      'bash deploy.sh --restart'."
        fi

        if [ -n "$AVISOS" ]; then
            echo ""
            echo "  AVISO -- este deploy incluye cambios que necesitan recrear:$AVISOS"
        fi
        ;;
esac
echo ""

# ---- 4b. El compose del server no monta app/ ----
# aprovisionar_server.sh genera docker-compose.prod.yml, pero deploy.sh NO lo
# regenera: si el server quedo con la version previa a que app/ existiera,
# ningun modo de este script alcanza para publicarla. Hay que re-aprovisionar.
if echo "$SYNC_OUT" | grep -q '^REACTOR_COMPOSE_SIN_APP$'; then
    echo "  AVISO -- el $COMPOSE_FILE del server no bind-montea ./app:/var/www/app."
    echo "           Los archivos se subieron, pero el contenedor no los sirve todavia."
    echo "           Correr una vez: bash scripts/aprovisionar.sh"
    echo "           (regenera el compose con el puerto 8115, agrega el server block"
    echo "            de nginx para app.reactor.com.ar y suma el dominio al cert SSL)."
    echo ""
fi

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
echo "    app:   https://app.reactor.com.ar   (alias: pwa. / newapp. / webapp.)"
echo "================================================"
echo ""
