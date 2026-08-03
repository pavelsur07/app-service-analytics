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

# pcov — coverage driver for `make test-cov`, cli tooling image only:
# php-fpm (real requests) never needs it. pcov over Xdebug — purpose-built
# for coverage, no step-debugging overhead.
RUN if [ "$PHP_VARIANT" = "cli" ]; then \
        apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
        && pecl install pcov \
        && docker-php-ext-enable pcov \
        && apk del .build-deps; \
    fi

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
