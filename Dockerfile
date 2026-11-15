FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libxml2-dev libonig-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql dom mbstring \
    && rm -rf /var/lib/apt/lists/*

RUN sed -ri 's/^Listen 80$/Listen 10000/' /etc/apache2/ports.conf \
    && sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf \
    && a2enmod headers \
    && printf '<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/ystek.conf \
    && a2enconf ystek

WORKDIR /var/www/html
COPY . .

RUN touch app/error.log \
    && chown www-data:www-data app/error.log \
    && chmod 664 app/error.log

EXPOSE 10000
