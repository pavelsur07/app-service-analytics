#!/usr/bin/env bash
# Записывает реквизиты хранилища сырья (ADR-024, S3 Timeweb Cloud)
# в /opt/conwix/.env на боевом сервере.
#
# Запускает человек со своей машины: значения читаются интерактивно,
# секретный ключ — без эха, и уходят на сервер через stdin SSH — не
# в argv, не в историю shell, не в файл на этой машине. На сервере
# прежние строки RAW_STORAGE_* заменяются, остальной файл не трогается,
# права файла сохраняются.
#
#   bash bin/set-raw-storage-env.sh
#
# Приложение увидит значения после пересоздания контейнеров — ближайшей
# выкладкой или вручную (docs/operations-checklist.md, «Хранилище сырья»).

set -euo pipefail

HOST=${CONWIX_PROD_HOST:-conwix-prod}
DOTENV=${CONWIX_PROD_DOTENV:-/opt/conwix/.env}

ask() {
    local prompt=$1 value
    printf '%s' "$prompt" >&2
    read -r value
    printf '%s' "$value"
}

endpoint=$(ask 'RAW_STORAGE_ENDPOINT (адрес S3 из панели Timeweb, https://…): ')
region=$(ask 'RAW_STORAGE_REGION (регион из панели Timeweb): ')
bucket=$(ask 'RAW_STORAGE_BUCKET: ')
access_key=$(ask 'RAW_STORAGE_ACCESS_KEY: ')
printf 'RAW_STORAGE_SECRET_KEY: ' >&2
if [[ -t 0 ]]; then
    read -r -s secret_key
    printf '\n' >&2
else
    read -r secret_key
fi
trap 'unset secret_key access_key' EXIT

for pair in "RAW_STORAGE_ENDPOINT=$endpoint" "RAW_STORAGE_REGION=$region" "RAW_STORAGE_BUCKET=$bucket" \
    "RAW_STORAGE_ACCESS_KEY=$access_key" "RAW_STORAGE_SECRET_KEY=$secret_key"; do
    name=${pair%%=*}
    value=${pair#*=}
    if [[ -z "$value" ]]; then
        printf '%s пуст — ничего не записано.\n' "$name" >&2
        exit 1
    fi
    # Значение пишется в dotenv без кавычек: пробел, кавычка или # его
    # сломали бы, а $ docker compose подставил бы как переменную — этот
    # файл он читает для интерполяции docker-compose.prod.yml.
    if [[ "$value" =~ [[:space:]\"\'#\\\$] ]]; then
        printf '%s содержит пробел, кавычку, #, $ или обратный слэш — ничего не записано.\n' "$name" >&2
        exit 1
    fi
done
if [[ "$endpoint" != https://* ]]; then
    printf 'RAW_STORAGE_ENDPOINT должен начинаться с https:// — ничего не записано.\n' >&2
    exit 1
fi

# Скрипт на сервере — в argv (секретов в нём нет), значения — в stdin.
# В base64: так команда не зависит от оболочки пользователя на сервере.
# shellcheck disable=SC2016
remote='
set -eu
dotenv=$1
[ -f "$dotenv" ] || { echo "Файл не найден: $dotenv" >&2; exit 1; }
values=$(cat)
temporary=$(mktemp "${dotenv}.tmp.XXXXXX")
trap "rm -f -- \"$temporary\"" EXIT HUP INT TERM
# Код grep 1 — ни одной строки не осталось, допустимо; 2 и выше — файл
# не прочитан, и замена им /opt/conwix/.env стёрла бы все секреты прода.
status=0
grep -v "^[[:space:]]*\(export[[:space:]]\+\)\?RAW_STORAGE_[A-Z_]*[[:space:]]*=" "$dotenv" > "$temporary" || status=$?
if [ "$status" -gt 1 ]; then
    echo "Не удалось прочитать $dotenv — файл не изменён." >&2
    exit 1
fi
printf "%s\n" "$values" >> "$temporary"
chmod "$(stat -c %a "$dotenv")" "$temporary"
chown "$(stat -c %u:%g "$dotenv")" "$temporary"
mv -f -- "$temporary" "$dotenv"
trap - EXIT HUP INT TERM
printf "Записано в %s: %s\n" "$dotenv" "$(grep -o "^RAW_STORAGE_[A-Z_]*" "$dotenv" | tr "\n" " ")"
'

printf 'RAW_STORAGE_ENDPOINT=%s\nRAW_STORAGE_REGION=%s\nRAW_STORAGE_BUCKET=%s\nRAW_STORAGE_ACCESS_KEY=%s\nRAW_STORAGE_SECRET_KEY=%s' \
    "$endpoint" "$region" "$bucket" "$access_key" "$secret_key" |
    ssh -o BatchMode=yes "$HOST" "sh -c \"\$(echo $(printf '%s' "$remote" | base64 -w0) | base64 -d)\" sh '$DOTENV'"
