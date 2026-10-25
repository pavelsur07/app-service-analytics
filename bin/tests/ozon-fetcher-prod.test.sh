#!/usr/bin/env bash
# Контракт утилит выкладки ozon-fetcher (этап 7 docs/plan/ozon-fetcher.md):
# секреты генерируются один раз, не печатаются и не меняют соседей
# и права .env; скрипт БД отказывает без полного набора секретов, не
# передаёт пароль в argv и ставит правила pg_hba тем же файлом, что
# у песочницы. docker не нужен: compose подменяется записью вызовов.

set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
SET_ENV="$ROOT/bin/set-ozon-fetcher-env.sh"
PROD_DB="$ROOT/bin/ozon-fetcher-prod-db.sh"
HBA="$ROOT/services/docker/postgres/init/02-ozon-fetcher-hba.sh"
NAMES='INTERNAL_AUTH_TOKEN OZON_FETCHER_SERVICE_API_TOKEN OZON_FETCHER_DB_PASSWORD'

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

value() {
    sed -n "s/^$1=//p" "$2" | tail -n 1
}

test_secrets_are_generated_once_and_never_printed() {
    local work dotenv output name first
    work=$(mktemp -d)
    dotenv="$work/.env"
    printf '%s\n' 'APP_SECRET=keep-me' 'INTERNAL_AUTH_TOKEN=' > "$dotenv"
    chmod 600 "$dotenv"

    output=$(DOTENV="$dotenv" sh "$SET_ENV")

    for name in $NAMES; do
        [[ $(grep -c "^$name=" "$dotenv") == 1 ]] || fail "$name: не ровно одна строка"
        value "$name" "$dotenv" | grep -Eq '^[0-9a-f]{64}$' || fail "$name: не 64 hex-символа"
        [[ "$output" != *"$(value "$name" "$dotenv")"* ]] || fail "$name: значение попало в вывод"
    done
    grep -qx 'APP_SECRET=keep-me' "$dotenv" || fail 'соседний секрет изменён'
    [[ $(stat -c '%a' "$dotenv") == 600 ]] || fail 'права .env изменились'
    [[ $(value INTERNAL_AUTH_TOKEN "$dotenv") != $(value OZON_FETCHER_DB_PASSWORD "$dotenv") ]] ||
        fail 'значения совпадают'

    first=$(cat "$dotenv")
    printf '%s\n' 'OZON_FETCHER_DB_PASSWORD=' >> "$dotenv"
    DOTENV="$dotenv" sh "$SET_ENV" > /dev/null
    [[ $(cat "$dotenv") == "$first" ]] ||
        fail 'повторный запуск изменил заданные значения или оставил пустое переопределение'
}

# Запись вызовов вместо docker compose: аргументы и stdin каждого вызова.
fake_compose() {
    local log=$1
    cat > "$log.sh" <<EOF
#!/bin/sh
printf 'ARGS %s\n' "\$*" >> '$log'
cat >> '$log'
EOF
    chmod +x "$log.sh"
}

prepare_run() {
    local work=$1
    mkdir -p "$work/ozon-fetcher"
    cp "$PROD_DB" "$HBA" "$work/ozon-fetcher/"
    printf '%s\n' 'OZON_FETCHER_IMAGE=ghcr.io/x/ozon-fetcher:1-abc' > "$work/.env"
    DOTENV="$work/.env" sh "$SET_ENV" > /dev/null
    printf '%s\n' 'OZON_FETCHER_CONNECTION_ALLOWLIST=01a17223-68d8-7223-b10a-82c83f9fb302' >> "$work/.env"
}

test_db_script_passes_password_by_stdin_and_reuses_sandbox_hba() {
    local work log password
    work=$(mktemp -d)
    log="$work/compose.log"
    prepare_run "$work"
    fake_compose "$log"
    password=$(value OZON_FETCHER_DB_PASSWORD "$work/.env")

    (cd "$work" && COMPOSE="$log.sh" sh ozon-fetcher/ozon-fetcher-prod-db.sh > /dev/null) ||
        fail 'скрипт БД отказал на полном наборе секретов'

    ! grep '^ARGS' "$log" | grep -q "$password" || fail 'пароль роли в argv'
    grep -q "\\\\set pw '$password'" "$log" || fail 'пароль не дошёл до psql через stdin'
    grep -q 'CREATE DATABASE ozon_fetcher OWNER svc_ozon_fetcher' "$log" || fail 'нет создания БД'
    grep -q 'REVOKE CONNECT ON DATABASE %I FROM PUBLIC' "$log" || fail 'нет REVOKE CONNECT'
    grep -q 'svc_ozon_fetcher  all  reject' "$log" || fail 'правила pg_hba песочницы не переданы'
}

test_db_script_refuses_incomplete_secrets() {
    local work log name
    for name in OZON_FETCHER_IMAGE $NAMES; do
        work=$(mktemp -d)
        log="$work/compose.log"
        prepare_run "$work"
        fake_compose "$log"
        sed -i "/^$name=/d" "$work/.env"
        [[ $name == OZON_FETCHER_DB_PASSWORD ]] && printf '%s\n' "$name=not-hex" >> "$work/.env"
        [[ $name == INTERNAL_AUTH_TOKEN ]] && printf '%s\n' "$name=short" >> "$work/.env"

        if (cd "$work" && COMPOSE="$log.sh" sh ozon-fetcher/ozon-fetcher-prod-db.sh > /dev/null 2>&1); then
            fail "$name: скрипт БД прошёл без значения"
        fi
        [[ ! -s "$log" ]] || fail "$name: обращение к postgres до проверки секретов"
    done
}

test_secrets_are_generated_once_and_never_printed
test_db_script_passes_password_by_stdin_and_reuses_sandbox_hba
test_db_script_refuses_incomplete_secrets
echo 'ozon-fetcher-prod: OK'
