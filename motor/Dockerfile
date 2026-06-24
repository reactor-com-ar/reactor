FROM python:3.12-slim

# unbuffered stdout para que los prints aparezcan en docker logs en tiempo real
ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    TZ=America/Argentina/Buenos_Aires

WORKDIR /app

# Dependencias del sistema: tzdata para la zona horaria; default-libmysqlclient-dev
# por si pymysql no alcanza y hace falta mysqlclient en el futuro.
RUN apt-get update \
 && apt-get install -y --no-install-recommends tzdata \
 && ln -fs /usr/share/zoneinfo/${TZ} /etc/localtime \
 && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# El codigo se monta como bind volume desde docker-compose (./motor:/app),
# asi que no se COPIA aca -- los cambios en motor/*.py se reflejan en vivo.

CMD ["python", "main.py"]
