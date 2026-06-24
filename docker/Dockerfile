FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite headers expires

# Permitir que los .htaccess tomen efecto
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# Apache escucha en 8086 (puerto fijo, mismo interno=externo, mismo dev=prod).
# El default ports.conf (Listen 80) se reemplaza, y el 000-default.conf se
# deshabilita porque cloud tiene su propio vhost explicito en vhosts.conf.
COPY ports.conf /etc/apache2/ports.conf
COPY vhosts.conf /etc/apache2/sites-available/010-reactor-vhosts.conf
RUN a2dissite 000-default && a2ensite 010-reactor-vhosts

WORKDIR /var/www/html

EXPOSE 8086 8087
