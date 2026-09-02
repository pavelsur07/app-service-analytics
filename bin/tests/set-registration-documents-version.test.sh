#!/usr/bin/env bash

set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
SCRIPT="$ROOT/bin/set-registration-documents-version.sh"

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

test_replaces_duplicates_without_exposing_or_changing_other_values() {
    local work dotenv output permissions
    work=$(mktemp -d)
    dotenv="$work/.env"
    printf '%s\n' \
        'APP_SECRET=do-not-print-me' \
        'REGISTRATION_DOCUMENTS_VERSION=old' \
        'DATABASE_URL=postgresql://keep-me' \
        'REGISTRATION_DOCUMENTS_VERSION=duplicate' > "$dotenv"
    chmod 640 "$dotenv"

    output=$("$SCRIPT" "$dotenv" '2026-09-02')

    [[ $(grep -c '^REGISTRATION_DOCUMENTS_VERSION=' "$dotenv") == 1 ]] ||
        fail 'после обновления осталось не ровно одно значение версии'
    grep -qx 'REGISTRATION_DOCUMENTS_VERSION=2026-09-02' "$dotenv" ||
        fail 'версия документов не обновлена'
    grep -qx 'APP_SECRET=do-not-print-me' "$dotenv" ||
        fail 'соседний секрет изменён или потерян'
    grep -qx 'DATABASE_URL=postgresql://keep-me' "$dotenv" ||
        fail 'соседняя настройка изменена или потеряна'
    [[ "$output" != *'do-not-print-me'* ]] || fail 'секрет попал в вывод updater'
    permissions=$(stat -c '%a' "$dotenv")
    [[ "$permissions" == 640 ]] || fail 'права production .env изменились'
}

test_appends_version_when_key_is_missing() {
    local work dotenv
    work=$(mktemp -d)
    dotenv="$work/.env"
    printf '%s\n' 'APP_ENV=prod' > "$dotenv"

    "$SCRIPT" "$dotenv" '2026-09-02' >/dev/null

    grep -qx 'APP_ENV=prod' "$dotenv" || fail 'исходная настройка потеряна'
    grep -qx 'REGISTRATION_DOCUMENTS_VERSION=2026-09-02' "$dotenv" ||
        fail 'отсутствующий ключ не добавлен'
}

test_rejects_invalid_version_without_touching_file() {
    local work dotenv before status
    work=$(mktemp -d)
    dotenv="$work/.env"
    printf '%s\n' 'APP_ENV=prod' > "$dotenv"
    before=$(sha256sum "$dotenv")

    set +e
    "$SCRIPT" "$dotenv" 'not-a-version' >/dev/null 2>&1
    status=$?
    set -e

    [[ $status -ne 0 ]] || fail 'невалидная версия была принята'
    [[ $(sha256sum "$dotenv") == "$before" ]] ||
        fail 'файл изменился после отказа валидации'
}

[[ -x "$SCRIPT" ]] || fail 'production updater ещё не реализован'

test_replaces_duplicates_without_exposing_or_changing_other_values
test_appends_version_when_key_is_missing
test_rejects_invalid_version_without_touching_file

printf 'OK: production documents version updater\n'
