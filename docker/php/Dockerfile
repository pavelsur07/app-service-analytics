# Один файл на development и production, стадии выбираются через --target.
#
# Два файла означали бы дублированную базу, которая разъезжается:
# расширение добавят в dev и забудут в prod, и разница вылезет на боевом.
# Здесь общая часть физически одна — стадия base, от которой наследуются
# и prod, и dev.
#
# Контекст сборки — корень репозитория, а не docker/php: prod-стадии
# нужен api/. Что в контекст не уезжает — .dockerignore.
#
# Стадии:
#   base       расширения, общий php.ini, WORKDIR
#   prod-deps  composer install без dev-зависимостей; только для сборки,
#              в конечный образ не попадает
#   prod       код внутри образа, opcache без проверки времени файлов,
#              прогретый кэш контейнера, healthcheck, www-data
#   dev        pcov, composer, поднятый memory_limit; код монтируется томом
#
# База — alpine, решение зафиксировано в docs/patterns.md («База образов»).
# Версия до патча: плавающий тег означает, что образ, собранный сегодня
# и через месяц, — разные образы при одном и том же коммите.
ARG PHP_VERSION=8.4.24

# --- base ---------------------------------------------------------------

FROM php:${PHP_VERSION}-fpm-alpine AS base

# opcache здесь нет намеренно: базовый образ уже собрал его и включил
# через conf.d/docker-php-ext-opcache.ini. Повторный docker-php-ext-install
# распаковал бы исходники PHP и скомпилировал то же расширение заново.
# Настройки opcache для prod задаются отдельным ini в стадии prod.
#
# postgresql-dev нужен только чтобы скомпилировать pdo_pgsql, в рантайме
# достаточно libpq. Оставленный целиком, он весит около 500 МБ — вдесятеро
# больше самого приложения. Поэтому --virtual и apk del в том же RUN:
# в отдельном слое удаление ничего не экономит, слой уже записан.
#
# fcgi даёт cgi-fcgi для healthcheck: проверять живость php-fpm снаружи
# нечем, TCP-порт открыт и у зависшего мастера.
RUN apk add --no-cache libpq fcgi \
    && apk add --no-cache --virtual .build-deps postgresql-dev \
    && docker-php-ext-install pdo_pgsql \
    && apk del .build-deps

WORKDIR /var/www/html

# --- prod-deps ----------------------------------------------------------
# Стадия сборки. Composer живёт только здесь и в конечный образ
# не попадает: в проде он позволил бы доустановить что угодно.

FROM base AS prod-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Сначала только манифесты — слой с зависимостями инвалидируется
# изменением composer.lock, а не правкой любого PHP-файла.
COPY api/composer.json api/composer.lock ./

# --no-scripts: post-install-cmd вызывает cache:clear и assets:install,
# которым нужны APP_SECRET и DATABASE_URL. На сборке их нет и быть
# не должно — секреты приезжают в рантайме.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

COPY api/ ./

# classmap-authoritative: в проде классы не появляются на лету,
# поэтому обход файловой системы при промахе автозагрузчика не нужен.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# --- prod ---------------------------------------------------------------

FROM base AS prod

# Официальный образ кладёт два пресета и не активирует ни один: без этой
# строки php работает на встроенных умолчаниях, а они ближе к development.
# Здесь это не про удобство, а про две утечки:
#   display_errors=On            — фатальные ошибки и предупреждения уходят
#                                  в HTTP-ответ вместе с путями (Symfony
#                                  ловит исключения, но не то, что падает
#                                  до загрузки ядра);
#   zend.exception_ignore_args=0 — аргументы функций попадают в стектрейсы,
#                                  а туда приезжают ключи площадок
#                                  и данные компаний.
# conf.d/zz-*.ini читаются после php.ini и продолжают перекрывать нужное.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# validate_timestamps=0 — php не проверяет mtime файлов на каждом запросе.
# Код внутри образа неизменен, проверять нечего; при выкладке поднимается
# новый контейнер, а не подменяются файлы.
RUN { \
        echo "opcache.enable=1"; \
        echo "opcache.validate_timestamps=0"; \
        echo "opcache.max_accelerated_files=20000"; \
        echo "opcache.memory_consumption=128"; \
        echo "opcache.interned_strings_buffer=16"; \
    } > /usr/local/etc/php/conf.d/zz-opcache.ini

# ping.path — единственный способ спросить у php-fpm, жив ли он,
# не поднимая приложение целиком.
RUN { \
        echo "[www]"; \
        echo "ping.path = /ping"; \
        echo "ping.response = pong"; \
    } > /usr/local/etc/php-fpm.d/zz-ping.conf

COPY --from=prod-deps --chown=www-data:www-data /var/www/html /var/www/html

ENV APP_ENV=prod

# Кэш контейнера собирается на сборке, а не на первом запросе: иначе его
# строит www-data в каталоге, который ему не принадлежит, и первый же
# запрос отвечает 500 (ловили на Stage 4).
RUN APP_SECRET=build-time-only php bin/console cache:warmup --env=prod \
    && chown -R www-data:www-data var

# Тег сборки. Значение по умолчанию заведомо неправдоподобно: образ,
# собранный мимо конвейера, обязан отличаться от собранного им,
# а не притворяться версией 0.1.0.
ARG APP_VERSION=0.0.0-unbuilt
ENV APP_VERSION=$APP_VERSION

USER www-data

HEALTHCHECK --interval=10s --timeout=3s --start-period=5s --retries=3 \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET \
        cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1

# --- dev ----------------------------------------------------------------
# Код сюда не копируется — он монтируется томом из docker-compose.yml,
# иначе правка файла требовала бы пересборки образа.

FROM base AS dev

# Парный пресет к prod: здесь ошибки, наоборот, должны быть видны сразу
# и целиком, включая аргументы в стектрейсах.
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 128M по умолчанию не хватает PHPStan level 9 и composer на этом коде.
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/zz-memory-limit.ini

# pcov — драйвер покрытия для make test-cov. Выбран вместо Xdebug:
# написан специально под покрытие, без накладных расходов на пошаговую
# отладку. В prod-стадии его нет — это перехват выполнения и лишняя
# поверхность атаки.
RUN apk add --no-cache --virtual .pcov-deps $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del .pcov-deps
