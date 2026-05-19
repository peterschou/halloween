FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev zip unzip \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
