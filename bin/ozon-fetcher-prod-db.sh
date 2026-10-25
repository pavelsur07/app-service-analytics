#!/bin/sh
# Роль и БД ozon-fetcher на боевом postgres (ADR-043 п. 3, этап 7
# docs/plan/ozon-fetcher.md). Выполняет выкладка на сервере, в /opt/conwix,
# только у включённого сервиса (OZON_FETCHER_ENABLED=true в .env);
# повторный запуск ничего не меняет, кроме пароля роли — он сверяется
# с .env (замена пароля = новая строка в .env и выкладка).
#
#   sh ozon-fetcher/ozon-fetcher-prod-db.sh
#
# Делает:
# - проверяет, что в .env есть всё, без чего сервис не стартует: образ,
#   пароль роли (64 hex), оба токена (не короче 32 символов);
# - создаёт роль svc_ozon_fetcher и БД ozon_fetcher, ставит пароль;
# - REVOKE CONNECT на БД Conwix для PUBLIC и правила pg_hba: роль
#   подключается только к своей БД (02-ozon-fetcher-hba.sh — тот же файл,
#   что у песочницы).
#
# Секреты не попадают в argv: пароль уходит в psql через stdin.

set -eu

DOTENV=${DOTENV:-.env}
C=${COMPOSE:-docker compose -f docker-compose.prod.yml}
HERE=$(dirname "$0")

value() {
    sed -n "s/^$1=//p" "$DOTENV" | tail -n 1
}

fail() {
    echo "ozon-fetcher: $*" >&2
    exit 1
}

[ -n "$(value OZON_FETCHER_IMAGE)" ] || fail "OZON_FETCHER_IMAGE не задан"
password=$(value OZON_FETCHER_DB_PASSWORD)
printf '%s' "$password" | grep -Eq '^[0-9a-f]{64}$' \
    || fail "OZON_FETCHER_DB_PASSWORD — 64 hex-символа (bin/set-ozon-fetcher-env.sh)"
for token in INTERNAL_AUTH_TOKEN OZON_FETCHER_SERVICE_API_TOKEN; do
    [ "$(value "$token" | wc -c)" -gt 32 ] || fail "$token не задан или короче 32 символов"
done
[ -n "$(value OZON_FETCHER_CONNECTION_ALLOWLIST)" ] \
    || echo "ozon-fetcher: OZON_FETCHER_CONNECTION_ALLOWLIST пуст — сервис не возьмёт ни одного подключения" >&2

# \set и :'pw' — psql экранирует значение сам; пароль — только hex.
{
    printf "\\\\set pw '%s'\n" "$password"
    cat <<'SQL'
SELECT 'CREATE ROLE svc_ozon_fetcher LOGIN'
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'svc_ozon_fetcher')\gexec
ALTER ROLE svc_ozon_fetcher LOGIN PASSWORD :'pw';
SELECT 'CREATE DATABASE ozon_fetcher OWNER svc_ozon_fetcher'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'ozon_fetcher')\gexec
-- Граница §1: роль сервиса к БД Conwix не подключается.
SELECT format('REVOKE CONNECT ON DATABASE %I FROM PUBLIC', current_database())\gexec
SQL
} | $C exec -T postgres sh -c 'psql -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"'

$C exec -T postgres sh -s < "$HERE/02-ozon-fetcher-hba.sh"

echo "ozon-fetcher: роль, БД и pg_hba на месте"
