#!/bin/sh
# docker compose боевой топологии с профилем ozon-fetcher, когда сервис
# включён: OZON_FETCHER_ENABLED=true в /opt/conwix/.env (ADR-043,
# services/AGENTS.md §10). Включённость читает сам compose
# (config --environment), а не разбор .env здесь: кавычки, export
# и последнее вхождение понимаются так же, как при запуске контейнеров.
# Выполняется на сервере, в /opt/conwix.
#
#   sh ozon-fetcher/ozon-fetcher-compose.sh --enabled   # 0 — включён, 1 — выключен,
#                                                       # 2 — не удалось узнать
#   sh ozon-fetcher/ozon-fetcher-compose.sh up -d        # с профилем, если включён
#
# Профиль — механизм, переключатель — OZON_FETCHER_ENABLED: ручной
# `docker compose up -d` без этой обёртки сервис не запускает никогда.
# «Не удалось узнать» не равно «выключен»: выкладка на нём падает, а не
# пропускает гейт сервиса и не удаляет его контейнер.

set -eu

C=${COMPOSE:-docker compose -f docker-compose.prod.yml}

# state — 0/1/2 как у --enabled. Окружение compose не печатается: в нём
# значения всех переменных .env.
state() {
    if ! environment=$($C config --environment 2>/dev/null); then
        echo "ozon-fetcher: docker compose config --environment не выполнился — включённость неизвестна" >&2
        return 2
    fi
    if printf '%s\n' "$environment" | grep -qx 'OZON_FETCHER_ENABLED=true'; then
        return 0
    fi
    return 1
}

rc=0
state || rc=$?
if [ "${1:-}" = "--enabled" ]; then
    exit "$rc"
fi
case $rc in
    0) exec $C --profile ozon-fetcher "$@" ;;
    1) exec $C "$@" ;;
    *) exit "$rc" ;;
esac
