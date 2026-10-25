#!/bin/sh
# docker compose боевой топологии с профилем ozon-fetcher, когда сервис
# включён: OZON_FETCHER_ENABLED=true в /opt/conwix/.env (ADR-043,
# services/AGENTS.md §10). Включённость читает сам compose
# (config --environment), а не разбор .env здесь: кавычки, export
# и последнее вхождение понимаются так же, как при запуске контейнеров.
# Выполняется на сервере, в /opt/conwix.
#
#   sh ozon-fetcher/ozon-fetcher-compose.sh --enabled   # код 0 — включён
#   sh ozon-fetcher/ozon-fetcher-compose.sh up -d        # с профилем, если включён
#
# Профиль — механизм, переключатель — OZON_FETCHER_ENABLED: ручной
# `docker compose up -d` без этой обёртки сервис не запускает никогда.

set -eu

C=${COMPOSE:-docker compose -f docker-compose.prod.yml}

enabled() {
    # Вывод не печатается: в нём значения всех переменных .env.
    $C config --environment 2>/dev/null | grep -qx 'OZON_FETCHER_ENABLED=true'
}

if [ "${1:-}" = "--enabled" ]; then
    enabled
    exit
fi

if enabled; then
    exec $C --profile ozon-fetcher "$@"
fi
exec $C "$@"
