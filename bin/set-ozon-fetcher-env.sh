#!/bin/sh
# Секреты ozon-fetcher в /opt/conwix/.env на боевом сервере (ADR-043, этап 7
# docs/plan/ozon-fetcher.md). Значения генерируются на самом сервере
# и нигде не печатаются: не уходят в argv, в историю shell и на машину,
# с которой запущен скрипт.
#
#   ssh conwix-prod 'sh -s' < bin/set-ozon-fetcher-env.sh
#
# Заводит недостающие:
# - INTERNAL_AUTH_TOKEN — токен «сервис → PHP», общий у api и сервиса;
# - OZON_FETCHER_SERVICE_API_TOKEN — токен «PHP → сервис» для /internal/*;
# - OZON_FETCHER_DB_PASSWORD — пароль роли svc_ozon_fetcher.
# Уже заданные не трогает: повторный запуск ничего не меняет. Замена —
# удалить строку и запустить снова, затем выкладка.
#
# Ключи хранилища — те же RAW_STORAGE_* (решение владельца 25.10.2026),
# DSN GlitchTip (OZON_FETCHER_SENTRY_DSN) — позже, вручную.
# Приложение увидит значения после пересоздания контейнеров — выкладкой.

set -eu

DOTENV=${DOTENV:-/opt/conwix/.env}
[ -f "$DOTENV" ] || { echo "нет $DOTENV" >&2; exit 1; }

secret() {
    # 32 случайных байта в hex: годится и для токена (>= 32 символов),
    # и для пароля в DSN без экранирования.
    od -An -N32 -tx1 /dev/urandom | tr -d ' \n'
}

for name in INTERNAL_AUTH_TOKEN OZON_FETCHER_SERVICE_API_TOKEN OZON_FETCHER_DB_PASSWORD; do
    # Пустые NAME= удаляются всегда: compose берёт последнее вхождение,
    # и пустая строка после заданной обнулила бы значение. sed -i и
    # дописывание сохраняют права файла.
    sed -i "/^$name=\$/d" "$DOTENV"
    if grep -Eq "^$name=.+" "$DOTENV"; then
        echo "$name: уже задан, не меняется"
        continue
    fi
    printf '%s=%s\n' "$name" "$(secret)" >> "$DOTENV"
    echo "$name: заведён"
done
