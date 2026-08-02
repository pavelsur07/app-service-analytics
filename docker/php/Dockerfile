ARG PHP_VARIANT=cli
FROM php:8.4-${PHP_VARIANT}-alpine

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
