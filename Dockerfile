FROM php:8.2-apache
RUN apt-get update && apt-get install -y \
    git \
    zip \
    unzip \
    libzip-dev \
    && docker-php-ext-install pdo pdo_mysql zip
RUN curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer
RUN a2enmod rewrite
COPY apache.conf /etc/apache2/sites-available/000-default.conf