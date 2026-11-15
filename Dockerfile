FROM php:8.2-apache

ENV YSTEK_SESSION_DRIVER=pgsql \
    YSTEK_ERROR_LOG=/dev/stderr

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libxml2-dev libonig-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql dom mbstring \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod headers \
    && printf '<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/ystek.conf \
    && a2enconf ystek

WORKDIR /var/www/html
COPY . .

EXPOSE 80
