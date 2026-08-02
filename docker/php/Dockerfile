ARG PHP_VARIANT=cli
FROM php:8.4-${PHP_VARIANT}-alpine
ARG PHP_VARIANT

RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pdo_pgsql

# ponytail: default 128M is too little for PHPStan level 9 / composer on
# this codebase; bump it for the cli tooling image only, fpm (real
# requests) keeps the conservative default.
RUN if [ "$PHP_VARIANT" = "cli" ]; then \
        echo "memory_limit=512M" > /usr/local/etc/php/conf.d/zz-memory-limit.ini; \
    fi

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
