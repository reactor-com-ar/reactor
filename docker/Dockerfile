FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql pcntl \
 && a2enmod rewrite headers expires

# Directorio de logs del Programador de tareas (cloud/jobs). El endpoint
# tareas_ejecutar.php y el bootstrap escriben en /var/log/reactor/cloud/
# ejecuciones/<id>.log; sin este dir preexistente y writable por www-data,
# el mkdir dinamico en /var/log falla y la fila queda 'corriendo' sin log.
RUN mkdir -p /var/log/reactor/cloud/ejecuciones \
 && chown -R www-data:www-data /var/log/reactor

# Permitir que los .htaccess tomen efecto
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# Apache escucha en 8086 (cloud), 8087 (panel) y 8115 (app). Puertos fijos,
# mismo interno=externo, mismo dev=prod. El default ports.conf (Listen 80) se
# reemplaza, y el 000-default.conf se deshabilita porque cada app tiene su
# propio vhost explicito en vhosts.conf.
COPY ports.conf /etc/apache2/ports.conf
COPY vhosts.conf /etc/apache2/sites-available/010-reactor-vhosts.conf
RUN a2dissite 000-default && a2ensite 010-reactor-vhosts

WORKDIR /var/www/html

EXPOSE 8086 8087 8115
