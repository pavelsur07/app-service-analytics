#!/usr/bin/env bash
# Снимает живые ответы Ozon, нужные для исследования процента выкупа.
# Реквизиты читаются интерактивно и передаются curl через stdin-конфиг:
# Api-Key не попадает ни в историю shell, ни в argv процесса, ни в фикстуры.

set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=${OZON_FIXTURE_DIR:-"$ROOT/api/tests/Fixtures/Marketplace/ozon"}
STAMP=$(date +%F)
WORK=$(mktemp -d)
trap 'unset KEY; rm -rf "$WORK"' EXIT INT TERM

mkdir -p "$DIR"

printf 'Ozon Client-Id: '
read -r CID
printf 'Ozon Api-Key:   '
if [[ -t 0 ]]; then
    read -r -s KEY
    printf '\n'
else
    read -r KEY
fi
printf 'Начало периода (YYYY-MM-DD): '
read -r FROM
printf 'Конец периода  (YYYY-MM-DD): '
read -r TO
printf 'Posting number для детального ответа, Enter чтобы пропустить: '
read -r POSTING_NUMBER

if [[ -z "$CID" || -z "$KEY" ]]; then
    printf 'Пустые реквизиты — запросы не выполнялись.\n' >&2
    exit 1
fi
if ! PERIOD_BOUNDS=$(python3 - "$FROM" "$TO" <<'PY'
import datetime as dt
import sys
from zoneinfo import ZoneInfo

try:
    start = dt.date.fromisoformat(sys.argv[1])
    end = dt.date.fromisoformat(sys.argv[2])
except ValueError:
    raise SystemExit(1)

if start > end:
    raise SystemExit(1)

moscow = ZoneInfo("Europe/Moscow")
utc = dt.timezone.utc
start_at = dt.datetime.combine(start, dt.time.min, tzinfo=moscow).astimezone(utc)
end_at = dt.datetime.combine(end, dt.time(23, 59, 59), tzinfo=moscow).astimezone(utc)
print(
    start_at.isoformat(timespec="seconds").replace("+00:00", "Z"),
    end_at.isoformat(timespec="seconds").replace("+00:00", "Z"),
)
PY
)
then
    printf 'Период должен состоять из существующих дат YYYY-MM-DD; начало не позже конца.\n' >&2
    exit 1
fi
read -r FROM_AT TO_AT <<<"$PERIOD_BOUNDS"

scan_sensitive() {
    local target=$1 paths
    paths=$(jq -r '
        paths(scalars) as $path
        | getpath($path) as $value
        | ($path | map(tostring)) as $parts
        | ($parts | join(".")) as $joined
        | select(($value | tostring | length) > 0)
        | select(
            ($joined | test("(^|\\.)legal_info\\."; "i"))
            or ($joined | test("(^|\\.)(customer|buyer|addressee)(\\.|$)"; "i"))
            or (($parts[-1] // "") | test("^(phone|email|customer_name)$"; "i"))
        )
        | $joined
    ' "$target")

    if [[ -n "$paths" ]]; then
        printf 'ВНИМАНИЕ: %s содержит чувствительные поля; проверьте и обезличьте файл до git add:\n' "$target" >&2
        while IFS= read -r path; do
            printf '  - %s\n' "$path" >&2
        done <<<"$paths"
    fi
}

request() {
    local path=$1 body=$2 target=$3 contract=$4 code answer
    local temporary="$WORK/response.json"

    if [[ -e "$target" ]]; then
        printf 'Файл уже существует: %s. Перезаписать? [y/N] ' "$target"
        read -r answer
        if [[ "$answer" != y && "$answer" != Y ]]; then
            printf 'Перезапись отменена; запросы остановлены.\n' >&2
            exit 1
        fi
    fi

    code=$(
        printf 'header = "Client-Id: %s"\nheader = "Api-Key: %s"\nheader = "Content-Type: application/json"\n' "$CID" "$KEY" |
            curl --config - -sS -X POST "https://api-seller.ozon.ru$path" \
                --data-binary "$body" -o "$temporary" -w '%{http_code}'
    )

    if [[ "$code" != 200 ]]; then
        printf '%s: Ozon ответил HTTP %s; тело ошибки не сохранено как фикстура.\n' "$path" "$code" >&2
        exit 1
    fi
    if ! jq -e . "$temporary" >/dev/null; then
        printf '%s: HTTP 200 содержит невалидный JSON; ответ не сохранён как фикстура.\n' "$path" >&2
        exit 1
    fi
    if ! jq -e "$contract" "$temporary" >/dev/null; then
        printf '%s: структура ответа не соответствует ожидаемому контракту; ответ не сохранён как фикстура.\n' "$path" >&2
        exit 1
    fi

    mv "$temporary" "$target"
    printf 'Сохранено: %s\n' "$target"
    scan_sensitive "$target"
}

offset=0
fbo_complete=false
for ((page = 1; page <= 100; page++)); do
    printf -v page_number '%03d' "$page"
    FBO_TARGET="$DIR/posting-fbo-list-buyout-$STAMP-page-$page_number.json"
    FBO_BODY=$(jq -cn --arg since "$FROM_AT" --arg to "$TO_AT" --argjson offset "$offset" '{
        dir: "ASC",
        filter: {since: $since, to: $to},
        limit: 1000,
        offset: $offset,
        translit: true,
        with: {analytics_data: true, financial_data: true}
    }')
    request '/v2/posting/fbo/list' "$FBO_BODY" "$FBO_TARGET" '(.result | type) == "array"'

    fbo_count=$(jq '.result | length' "$FBO_TARGET")
    if ((fbo_count < 1000)); then
        fbo_complete=true
        break
    fi
    offset=$((offset + fbo_count))
done
if [[ "$fbo_complete" != true ]]; then
    printf '/v2/posting/fbo/list: достигнут предел 100 страниц; выгрузка может быть неполной. Сузьте период.\n' >&2
    exit 1
fi

last_id=0
returns_complete=false
for ((page = 1; page <= 100; page++)); do
    printf -v page_number '%03d' "$page"
    RETURNS_TARGET="$DIR/returns-list-buyout-$STAMP-page-$page_number.json"
    RETURNS_BODY=$(jq -cn --arg from "$FROM_AT" --arg to "$TO_AT" --argjson lastId "$last_id" '{
        filter: {visual_status_change_moment: {time_from: $from, time_to: $to}},
        limit: 500,
        last_id: $lastId
    }')
    request '/v1/returns/list' "$RETURNS_BODY" "$RETURNS_TARGET" '((.returns | type) == "array") and ((.has_next | type) == "boolean")'

    has_next=$(jq -r '.has_next' "$RETURNS_TARGET")
    if [[ "$has_next" != true ]]; then
        returns_complete=true
        break
    fi
    next_last_id=$(jq -r '.returns[-1].id // empty' "$RETURNS_TARGET")
    if [[ -z "$next_last_id" || "$next_last_id" == "$last_id" ]]; then
        printf '/v1/returns/list: has_next=true, но курсор не сдвинулся.\n' >&2
        exit 1
    fi
    last_id=$next_last_id
done
if [[ "$returns_complete" != true ]]; then
    printf '/v1/returns/list: достигнут предел 100 страниц; выгрузка может быть неполной. Сузьте период.\n' >&2
    exit 1
fi

CANCEL_REASONS_TARGET="$DIR/posting-fbo-cancel-reason-list-buyout-$STAMP.json"
request '/v1/posting/fbo/cancel-reason/list' '{}' "$CANCEL_REASONS_TARGET" '(.result | type) == "array"'

if [[ -n "$POSTING_NUMBER" ]]; then
    DETAIL_TARGET="$DIR/posting-fbo-get-buyout-$STAMP.json"
    DETAIL_BODY=$(jq -cn --arg postingNumber "$POSTING_NUMBER" '{posting_number: $postingNumber}')
    request '/v2/posting/fbo/get' "$DETAIL_BODY" "$DETAIL_TARGET" '(.result | type) == "object"'
fi
