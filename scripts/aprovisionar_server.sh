#!/bin/bash
# ============================================================
# aprovisionar_server.sh - Setup interno del server reactor.
#
# Este script NO se corre a mano: lo invoca scripts/aprovisionar.sh
# despues de transferir los archivos del proyecto via SSH. Si necesitas
# re-correr el setup en el server (idempotente), podes ejecutarlo
# directamente:
#   bash /opt/app/reactor/scripts/aprovisionar_server.sh
#
# Sistema esperado: Amazon Linux 2023.
#
# Variables que recibe (opcionales, con default):
#   DOMAIN          - default cloud.reactor.com.ar
#   PANEL_DOMAIN    - default panel.reactor.com.ar
#   PWA_DOMAIN      - default app.reactor.com.ar
#   PWA_DOMAIN_ALIASES - alias del vhost de app, separados por espacio.
#                     Default: pwa. newapp. webapp.
#   CERTBOT_EMAIL   - default javieralvarez@databox.net.ar
# ============================================================

set -eo pipefail

APP_DIR="/opt/app/reactor"
APP_PORT_HOST=8086        # cloud
PANEL_PORT_HOST=8087      # panel
PWA_PORT_HOST=8115        # app end-user
DOMAIN="${DOMAIN:-cloud.reactor.com.ar}"
PANEL_DOMAIN="${PANEL_DOMAIN:-panel.reactor.com.ar}"
# La app end-user se sirve en app.reactor.com.ar (DNS repuntado a este server
# el 2026-09-05) y responde ademas por una lista de alias, todos apuntando al
# MISMO vhost del contenedor (8115):
#   pwa.    - era el dominio de preview; sigue en accesos directos y pestañas
#             abiertas, por eso no se saca.
#   newapp. - alias adicional.
#   webapp. - alias adicional.
# Los tres tienen que estar en tres lugares o el dominio no funciona:
#   1) el server_name de nginx (abajo), o el request cae en el primer server
#      block y se sirve el vhost equivocado;
#   2) el certificado (bloque de certbot, mas abajo), o el browser tira
#      ERR_CERT_COMMON_NAME_INVALID;
#   3) el ServerAlias de docker/vhosts.conf.
# Cuando alguno deje de usarse, se saca de PWA_DOMAIN_ALIASES y de vhosts.conf.
PWA_DOMAIN="${PWA_DOMAIN:-app.reactor.com.ar}"
PWA_DOMAIN_ALIASES="${PWA_DOMAIN_ALIASES:-pwa.reactor.com.ar newapp.reactor.com.ar webapp.reactor.com.ar}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-javieralvarez@databox.net.ar}"
COMPOSE_FILE="docker-compose.prod.yml"

echo ""
echo "============================================================"
echo "  Setup remoto reactor (Amazon Linux 2023)"
echo "  Dominio: ${DOMAIN}"
echo "  App dir: ${APP_DIR}"
echo "============================================================"
echo ""

# ---- 1. Actualizar sistema ----
echo "[ 1/9 ] Actualizando sistema..."
sudo dnf update -y -q
echo "        OK"

# ---- 2. Instalar Docker, Git, Nginx, bind-utils, python3, cronie ----
# cronie: Amazon Linux 2023 viene SIN cron. Lo necesita el Programador de
# tareas de cloud/ (/etc/cron.d/reactor-cloud).
#
# OJO: instalar cronie NO deja `crond` corriendo. Verificado el 2026-09-05: el
# host lo tenia inactivo, asi que todo lo que dependiera de /etc/cron.d nunca
# se ejecuto. Por eso la renovacion del certificado (paso 9) pasó a un timer de
# systemd. El cron del Programador de tareas vive DENTRO del contenedor, que si
# lo tiene corriendo, y por eso ese si funciona.
echo "[ 2/9 ] Instalando Docker, Nginx, bind-utils, python3, cronie..."
sudo dnf install -y -q docker git nginx bind-utils python3 python3-pip augeas-libs cronie
sudo systemctl enable docker nginx crond
sudo systemctl start docker crond
sudo usermod -aG docker ec2-user
echo "        OK -- $(sudo docker --version)"

# ---- 3. Instalar Docker Compose v2 + buildx ----
echo "[ 3/9 ] Instalando Docker Compose y buildx..."
sudo mkdir -p /usr/local/lib/docker/cli-plugins

COMPOSE_VERSION="v2.32.4"
sudo curl -fsSL \
    "https://github.com/docker/compose/releases/download/${COMPOSE_VERSION}/docker-compose-linux-x86_64" \
    -o /usr/local/lib/docker/cli-plugins/docker-compose
sudo chmod +x /usr/local/lib/docker/cli-plugins/docker-compose

BUILDX_VERSION="v0.20.0"
sudo curl -fsSL \
    "https://github.com/docker/buildx/releases/download/${BUILDX_VERSION}/buildx-${BUILDX_VERSION}.linux-amd64" \
    -o /usr/local/lib/docker/cli-plugins/docker-buildx
sudo chmod +x /usr/local/lib/docker/cli-plugins/docker-buildx

echo "        OK -- Compose $(sudo docker compose version --short) / buildx $(sudo docker buildx version | awk '{print $2}')"

# ---- 4. Verificar artefactos transferidos ----
echo "[ 4/9 ] Verificando archivos del proyecto..."
for f in cloud panel app motor docker/Dockerfile docker/emqx/init.sh scripts/lib/emqx_seed.sh env.php .env.production; do
    if [ ! -e "$APP_DIR/$f" ]; then
        echo "        ERROR: falta $APP_DIR/$f"
        echo "        Re-correr scripts/aprovisionar.sh desde la maquina local."
        exit 1
    fi
done
# Override de compose es solo para dev local: si llego, lo borramos.
rm -f "$APP_DIR/docker-compose.override.yml"
echo "        OK"

# ---- 5. Generar docker-compose.prod.yml ----
# Difiere del docker-compose.yml del repo:
#   - No incluye el servicio reactor-db (en prod la BD es AWS RDS).
#   - Apache bindea solo a 127.0.0.1:8086 (Nginx hace el frente publico).
# Puertos en prod (interno = externo, mismos que dev cuando aplica):
#   - Apache  8086:8086 (cloud), 8087:8087 (panel), 8115:8115 (app) -- igual a dev
#   - EMQX MQTT 16273:16273 (dev usa 1884 por choque con vigicom-emqx)
#   - EMQX Dashboard 18083:18083 (dev usa 18084 por choque con vigicom-emqx)
# Tambien hay que bindear env.php y los .env.* al container para que las
# constantes (APP_KEY_*, DB_*) queden disponibles via env.php.
echo "[ 5/9 ] Generando $COMPOSE_FILE..."
cat > "$APP_DIR/$COMPOSE_FILE" << EOF
# Generado por scripts/aprovisionar_server.sh - no editar a mano.
# Produccion: sin servicio reactor-db (BD en AWS RDS, ver .env.production).
services:
  reactor:
    container_name: reactor-apache
    build:
      context: ./docker
      dockerfile: Dockerfile
    ports:
      - "127.0.0.1:${APP_PORT_HOST}:${APP_PORT_HOST}"      # cloud
      - "127.0.0.1:${PANEL_PORT_HOST}:${PANEL_PORT_HOST}"  # panel
      - "127.0.0.1:${PWA_PORT_HOST}:${PWA_PORT_HOST}"      # app end-user
    volumes:
      - ./cloud:/var/www/html
      - ./panel:/var/www/panel
      - ./app:/var/www/app
      - ./env.php:/var/www/env.php:ro
      - ./.env.production:/var/www/.env.production:ro
    env_file:
      - .env.production
    restart: unless-stopped

  reactor-emqx:
    container_name: reactor-emqx
    image: emqx/emqx:5.8
    ports:
      - "16273:16273"    # MQTT publico (interno = externo, abierto en security group)
      - "18083:18083"    # dashboard (filtrado por IP en security group)
    env_file:
      - .env.production
    environment:
      EMQX_ALLOW_ANONYMOUS: "false"
      EMQX_AUTHENTICATION__1__MECHANISM: password_based
      EMQX_AUTHENTICATION__1__BACKEND: built_in_database
      EMQX_AUTHENTICATION__1__USER_ID_TYPE: username
      EMQX_LISTENERS__TCP__DEFAULT__BIND: "0.0.0.0:16273"
      EMQX_DASHBOARD__LISTENERS__HTTP__BIND: "0.0.0.0:18083"
    volumes:
      - reactor-emqx-data:/opt/emqx/data
      - ./docker/emqx/init.sh:/init.sh:ro
    entrypoint: ["/init.sh"]
    command: ["/opt/emqx/bin/emqx", "foreground"]
    restart: unless-stopped

  reactor-motor:
    container_name: reactor-motor
    build:
      context: ./motor
      dockerfile: Dockerfile
    volumes:
      - ./motor:/app
    env_file:
      - .env.production
    depends_on:
      reactor-emqx:
        condition: service_started
    restart: unless-stopped

volumes:
  reactor-emqx-data:
EOF
echo "        OK"

# ---- 6. Configurar Nginx ----
echo "[ 6/9 ] Configurando Nginx como reverse proxy..."
sudo tee /etc/nginx/conf.d/reactor.conf > /dev/null << NGX
# Reverse proxy reactor -- generado por aprovisionar_server.sh

# cloud.reactor.com.ar -> Apache 8086
server {
    listen 80;
    server_name ${DOMAIN};
    location / {
        proxy_pass         http://127.0.0.1:${APP_PORT_HOST};
        proxy_set_header   Host \$host;
        proxy_set_header   X-Real-IP \$remote_addr;
        proxy_set_header   X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto \$scheme;
        client_max_body_size 50M;
        proxy_read_timeout 120s;
    }
}

# panel.reactor.com.ar -> Apache 8087
server {
    listen 80;
    server_name ${PANEL_DOMAIN};
    location / {
        proxy_pass         http://127.0.0.1:${PANEL_PORT_HOST};
        proxy_set_header   Host \$host;
        proxy_set_header   X-Real-IP \$remote_addr;
        proxy_set_header   X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto \$scheme;
        client_max_body_size 50M;
        proxy_read_timeout 120s;
    }
}

# app.reactor.com.ar -> Apache 8115 (app end-user)
# Los alias (pwa. / newapp. / webapp.) van al mismo backend -- ver
# PWA_DOMAIN_ALIASES arriba.
server {
    listen 80;
    server_name ${PWA_DOMAIN} ${PWA_DOMAIN_ALIASES};
    location / {
        proxy_pass         http://127.0.0.1:${PWA_PORT_HOST};
        proxy_set_header   Host \$host;
        proxy_set_header   X-Real-IP \$remote_addr;
        proxy_set_header   X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto \$scheme;
        client_max_body_size 50M;
        proxy_read_timeout 120s;
    }
}
NGX

sudo rm -f /etc/nginx/conf.d/default.conf
sudo nginx -t
sudo systemctl restart nginx
echo "        OK"

# ---- 7. Construir imagen y levantar contenedor ----
# --pull: re-baja el base image cada vez. Sin esto, buildx puede mantener
# cacheados todos los layers cuando el base no cambio de digest y NO
# rebuildear pese a que el Dockerfile mando editado -- vimos ese bug con
# la fix de pcntl + /var/log/reactor/cloud/ejecuciones (imagen 2 semanas
# vieja seguia rodando aunque el Dockerfile local ya tenia el cambio).
echo "[ 7/9 ] Construyendo imagen Docker y levantando contenedor..."
cd "$APP_DIR"
sudo docker compose -f "$COMPOSE_FILE" build --pull
sudo docker compose -f "$COMPOSE_FILE" up -d --force-recreate
sleep 3
sudo docker compose -f "$COMPOSE_FILE" ps
echo "        OK"

# ---- 8. Sembrar usuario MQTT en EMQX (idempotente, via API) ----
# El seeder espera a que el dashboard responda, hace login y upsertea el
# usuario MQTT_USER:MQTT_PASS leidos de .env.production.
# Reaplicable cuantas veces quieras: POST -> si 409, PUT.
echo "[ 8/9 ] Sembrando usuario MQTT en EMQX..."
if bash "$APP_DIR/scripts/lib/emqx_seed.sh" "$APP_DIR/.env.production"; then
    echo "        OK"
else
    echo "        AVISO: el seeder de EMQX fallo -- revisar: sudo docker logs reactor-emqx"
fi

# ---- 9. Emitir certificado SSL ----
echo "[ 9/9 ] Verificando DNS para SSL..."

IMDS_TOKEN=$(curl -s -X PUT "http://169.254.169.254/latest/api/token" \
    -H "X-aws-ec2-metadata-token-ttl-seconds: 60" --max-time 3 || echo "")
PUBLIC_IP=$(curl -s -H "X-aws-ec2-metadata-token: $IMDS_TOKEN" \
    --max-time 3 http://169.254.169.254/latest/meta-data/public-ipv4 || echo "")

if [ -z "$PUBLIC_IP" ]; then
    echo "        AVISO: no se pudo detectar la IP publica -- saltando SSL."
else
    echo "        IP publica del servidor: $PUBLIC_IP"

    RESOLVED_CLOUD=$(dig +short A "$DOMAIN" @8.8.8.8 | tail -n1)
    RESOLVED_PANEL=$(dig +short A "$PANEL_DOMAIN" @8.8.8.8 | tail -n1)
    RESOLVED_PWA=$(dig +short A "$PWA_DOMAIN" @8.8.8.8 | tail -n1)

    # Armar lista de dominios cuyo DNS YA apunta al server (-d por cada uno).
    CERT_DOMAINS=()
    if [ "$RESOLVED_CLOUD" = "$PUBLIC_IP" ]; then
        CERT_DOMAINS+=("-d" "$DOMAIN")
    else
        echo "        DNS de $DOMAIN -> ${RESOLVED_CLOUD:-(no resuelve)} (esperado $PUBLIC_IP) -- se salta este dominio."
    fi
    if [ "$RESOLVED_PANEL" = "$PUBLIC_IP" ]; then
        CERT_DOMAINS+=("-d" "$PANEL_DOMAIN")
    else
        echo "        DNS de $PANEL_DOMAIN -> ${RESOLVED_PANEL:-(no resuelve)} (esperado $PUBLIC_IP) -- se salta este dominio."
    fi
    if [ "$RESOLVED_PWA" = "$PUBLIC_IP" ]; then
        CERT_DOMAINS+=("-d" "$PWA_DOMAIN")
    else
        echo "        DNS de $PWA_DOMAIN -> ${RESOLVED_PWA:-(no resuelve)} (esperado $PUBLIC_IP) -- se salta este dominio."
    fi
    # Un alias que todavia no tenga el DNS apuntado no debe voltear la emision
    # del resto: se saltea y el cert sale con los que si resuelven.
    for PWA_ALIAS in $PWA_DOMAIN_ALIASES; do
        RESOLVED_PWA_ALIAS=$(dig +short A "$PWA_ALIAS" @8.8.8.8 | tail -n1)
        if [ "$RESOLVED_PWA_ALIAS" = "$PUBLIC_IP" ]; then
            CERT_DOMAINS+=("-d" "$PWA_ALIAS")
        else
            echo "        DNS de $PWA_ALIAS -> ${RESOLVED_PWA_ALIAS:-(no resuelve)} (esperado $PUBLIC_IP) -- se salta este dominio."
        fi
    done

    if [ ${#CERT_DOMAINS[@]} -eq 0 ]; then
        echo "        Ningun dominio resolvio al servidor -- configurar DNS y volver a correr para SSL."
    else
        echo "        DNS OK para: ${CERT_DOMAINS[*]}"
        echo "        Verificando certbot..."

        if [ ! -x /opt/certbot/bin/certbot ]; then
            echo "        Instalando certbot en /opt/certbot..."
            sudo python3 -m venv /opt/certbot
            sudo /opt/certbot/bin/pip install --quiet --upgrade pip
            sudo /opt/certbot/bin/pip install --quiet certbot certbot-nginx
            sudo ln -sf /opt/certbot/bin/certbot /usr/bin/certbot
        fi
        echo "        certbot $(/usr/bin/certbot --version 2>&1 | awk '{print $2}')"

        echo "        Emitiendo/verificando certificado..."
        if sudo certbot --nginx \
                --non-interactive \
                --agree-tos \
                --email "$CERTBOT_EMAIL" \
                --redirect \
                --keep-until-expiring \
                --expand \
                "${CERT_DOMAINS[@]}"; then
            echo "        OK -- SSL configurado."
        else
            echo "        AVISO: certbot fallo. Revisar /var/log/letsencrypt/letsencrypt.log"
        fi

        # Renovacion automatica por TIMER de systemd, no por cron.
        #
        # Antes esto escribia /etc/cron.d/certbot. No servia: en este host
        # `crond` esta inactivo (Amazon Linux 2023 no lo trae corriendo, y el
        # cron del Programador de tareas de cloud vive DENTRO del contenedor,
        # no aca). Resultado: el archivo existia y nadie lo ejecutaba nunca.
        # Se detecto el 2026-09-05, con el certificado a tres meses de vencer y
        # sin ningun mecanismo que lo renovara.
        #
        # systemd si esta corriendo, asi que el timer no depende de instalar ni
        # habilitar nada mas. `installer = nginx` queda anotado en
        # /etc/letsencrypt/renewal/*.conf, asi que certbot recarga nginx solo
        # cuando efectivamente renueva.
        echo "        Configurando renovacion automatica (timer de systemd)..."
        sudo tee /etc/systemd/system/certbot-renew.service > /dev/null <<'UNIT'
[Unit]
Description=Renovacion de certificados Let's Encrypt (reactor)
Documentation=https://certbot.eff.org/
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/bin/certbot renew -q
UNIT

        sudo tee /etc/systemd/system/certbot-renew.timer > /dev/null <<'UNIT'
[Unit]
Description=Corre certbot renew dos veces por dia

[Timer]
# Dos corridas diarias con desfasaje aleatorio, que es lo que recomienda
# Let's Encrypt para no golpear la API a la misma hora que todo el mundo.
OnCalendar=*-*-* 00,12:00:00
RandomizedDelaySec=3600
Persistent=true

[Install]
WantedBy=timers.target
UNIT

        sudo systemctl daemon-reload
        sudo systemctl enable --now certbot-renew.timer
        echo "        Timer certbot-renew.timer habilitado ($(systemctl is-active certbot-renew.timer))"

        # Limpieza del mecanismo viejo, para no dejar dos cosas compitiendo si
        # alguien llegara a levantar crond mas adelante.
        sudo rm -f /etc/cron.d/certbot
    fi
fi

echo ""
echo "============================================================"
echo "  Setup remoto completo."
echo ""
echo "  Cloud:      https://${DOMAIN}/         (proxy a 127.0.0.1:${APP_PORT_HOST})"
echo "  Panel:      https://${PANEL_DOMAIN}/   (proxy a 127.0.0.1:${PANEL_PORT_HOST})"
echo "  App (PWA):  https://${PWA_DOMAIN}/     (proxy a 127.0.0.1:${PWA_PORT_HOST})"
echo "  Repo:       $APP_DIR"
echo "  Compose:    docker compose -f $APP_DIR/$COMPOSE_FILE <cmd>"
echo "  Logs:       sudo docker logs -f reactor-apache"
echo "  Restart:    cd $APP_DIR && sudo docker compose -f $COMPOSE_FILE restart"
echo "  Ver SSL:    sudo certbot certificates"
echo "============================================================"
echo ""
