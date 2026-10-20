FROM php:8.3-apache

ENV DEBIAN_FRONTEND=noninteractive

# Instalar MariaDB Server, Supervisor y librerias requeridas por Moodle
RUN apt-get update && apt-get install -y --no-install-recommends \
    mariadb-server \
    mariadb-client \
    supervisor \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libicu-dev \
    libxml2-dev \
    ghostscript \
    unzip \
    curl \
    git \
    cron \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        mysqli \
        pdo_mysql \
        gd \
        intl \
        zip \
        soap \
        bcmath \
        opcache \
        exif \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Configuracion de PHP y Apache
COPY php.ini /usr/local/etc/php/conf.d/moodle.ini
COPY moodle.conf /etc/apache2/sites-available/000-default.conf

# Configuracion de Supervisor y Entrypoint
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
