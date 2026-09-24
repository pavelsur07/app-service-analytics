#!/bin/sh
# Разведка рекламного API Ozon (Performance API): снимает фикстуры для
# ADR-026 и отвечает на вопросы, которые документация оставила открытыми.
#
# Живой запрос к площадке делает человек, не агент и не конвейер
# (CLAUDE.md, «Периметр автономной работы»). Скрипт спрашивает реквизиты
# и никуда их не сохраняет: секрет и токен лежат только во временном
# каталоге с правами 600 и удаляются на выходе. В аргументы curl они
# не попадают — иначе их видно в списке процессов.
#
# **Только чтение, и это проверяет сам скрипт.** Ключ Performance API
# даёт право включать и выключать кампании и менять ставки, причём три
# метода `all_sku_promo/*` делают это обычным GET. Поэтому правило
# «только GET» не защищает. Каждый вызов сверяется с явным списком
# разрешённых пар «метод путь» (allowed ниже), и всё, чего в списке нет,
# отклоняется до сети.
#
# Что нужно выяснить (docs/task/ozon-advertising-research.md):
#   1. формат сумм в JSON — точка или запятая, с НДС или без;
#   2. что отдаёт /statistics/json по нескольким кампаниям — JSON или ZIP;
#   3. отдаёт ли /statistics/products/sku дни старше вчерашнего;
#   4. глубину истории — запрос за месяц год назад;
#   5. поля отчёта по заказам «Оплаты за заказ»;
#   6. есть ли в ответах персональные данные покупателей или контрагентов;
#   7. форму отказа на неверный секрет и на неверный токен;
#   8. сколько кабинетов продавца видит одна Performance-организация.
#
# Тела запросов части методов — предположения по описанию. Отказ 400
# такой же полезный результат, как 200: в теле ошибки площадка говорит,
# чего ждёт. Поэтому у таких методов несколько кандидатов, и перебор
# останавливается на первом успешном.
#
# Ответы сохраняются побайтово, без переформатирования: причёсанная
# фикстура проверяла бы не то, что отдаёт площадка.
#
#   sh bin/ozon-performance-fixture.sh
#
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=${OZON_PERFORMANCE_FIXTURE_DIR:-$ROOT/api/tests/Fixtures/Marketplace/ozon/performance}
# Переопределения каталога и адреса — только для прогона скрипта
# против локальной заглушки, чтобы не писать поддельные фикстуры в репозиторий.
BASE=${OZON_PERFORMANCE_FIXTURE_BASE:-https://api-performance.ozon.ru}
WORK=$(mktemp -d)
chmod 700 "$WORK"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$DIR"

# Даты — в московском времени: площадка группирует статистику по нему.
eval "$(python3 - <<'PYEOF'
import datetime as dt
from zoneinfo import ZoneInfo

today = dt.datetime.now(ZoneInfo('Europe/Moscow')).date()
yesterday = today - dt.timedelta(days=1)
old_to = (today.replace(day=1) - dt.timedelta(days=365)).replace(day=1)
old_end = (old_to.replace(day=28) + dt.timedelta(days=4)).replace(day=1) - dt.timedelta(days=1)
print(f"TODAY={today:%Y-%m-%d}")
print(f"YESTERDAY={yesterday:%Y-%m-%d}")
print(f"DAY_BEFORE={yesterday - dt.timedelta(days=1):%Y-%m-%d}")
print(f"WEEK_AGO={yesterday - dt.timedelta(days=7):%Y-%m-%d}")
print(f"WEEK_FROM={yesterday - dt.timedelta(days=6):%Y-%m-%d}")
print(f"MONTH_FROM={yesterday - dt.timedelta(days=29):%Y-%m-%d}")
print(f"OLD_FROM={old_to:%Y-%m-%d}")
print(f"OLD_TO={old_end:%Y-%m-%d}")
PYEOF
)"

printf 'Performance client_id:     '
read -r CLIENT_ID
printf 'Performance client_secret: '
stty -echo 2>/dev/null || true
read -r CLIENT_SECRET
stty echo 2>/dev/null || true
printf '\n'

if [ -z "$CLIENT_ID" ] || [ -z "$CLIENT_SECRET" ]; then
    echo 'Пустые реквизиты — ничего не делаю.' >&2
    exit 1
fi

# Тело запроса токена — в файл, не в аргумент curl.
umask 077
CLIENT_ID="$CLIENT_ID" CLIENT_SECRET="$CLIENT_SECRET" python3 - "$WORK/token-request.json" <<'PYEOF'
import json, os, sys
with open(sys.argv[1], 'w') as handle:
    json.dump({
        'client_id': os.environ['CLIENT_ID'],
        'client_secret': os.environ['CLIENT_SECRET'],
        'grant_type': 'client_credentials',
    }, handle)
PYEOF
CLIENT_SECRET=''

# Разрешённые пары «метод путь» — всё, что скрипт вообще может вызвать.
# Путь сверяется без строки запроса, целиком от начала до конца.
allowed() {
    printf '%s %s\n' "$1" "${2%%\?*}" | grep -Eqx \
        -e 'POST /api/client/token' \
        -e 'GET /api/client/campaign' \
        -e 'GET /api/client/campaign/[0-9]+/v2/products' \
        -e 'POST /api/client/campaign/search_promo/v2/products' \
        -e 'GET /api/client/statistics/expense/json' \
        -e 'GET /api/client/statistics/daily/json' \
        -e 'POST /api/client/statistics/products/sku' \
        -e 'POST /api/client/statistics/json' \
        -e 'POST /api/client/statistics' \
        -e 'GET /api/client/statistics/[0-9a-fA-F-]{36}' \
        -e 'GET /api/client/statistics/report' \
        -e 'POST /api/client/statistic/orders/generate/json' \
        -e 'GET /api/client/statistics/all_sku_promo/orders/generate/json'
}

# call МЕТОД ПУТЬ ФАЙЛ [ТЕЛО] — печатает HTTP-код; тело ответа в ФАЙЛ,
# заголовки ответа в ФАЙЛ.headers.
call() {
    method=$1
    path=$2
    out=$3
    body=${4:-}

    if ! allowed "$method" "$path"; then
        echo "ОТКАЗ: $method $path нет в списке разрешённых, не вызываю" >&2
        exit 2
    fi

    set -- -sS -o "$out" -D "$out.headers" -w '%{http_code}' -X "$method" \
        -H 'Accept: application/json' -H "@$WORK/auth.header"
    if [ -n "$body" ]; then
        printf '%s' "$body" > "$WORK/body.json"
        set -- "$@" -H 'Content-Type: application/json' --data-binary "@$WORK/body.json"
    fi
    curl "$@" "$BASE$path" || echo '000'
}

# Сохранить ответ под именем фикстуры. Расширение — по Content-Type:
# отчёты приходят JSON, CSV или ZIP, и какой именно — вопрос разведки.
# Из заголовков остаются только Content-Type и Content-Disposition:
# в остальных бывают куки и трассировка, фикстуре они не нужны.
# Переменные функций с префиксами: в POSIX sh нет local, и функция,
# назвавшая переменную так же, как вызывающая, молча её перезапишет.
SAVED=''
save() {
    s_src=$1
    s_name=$2
    type=$(grep -i '^content-type:' "$s_src.headers" | tail -n 1 | tr -d '\r' | cut -d: -f2- | tr 'A-Z' 'a-z' || true)
    case "$type" in
        *zip*) ext=zip ;;
        *csv*) ext=csv ;;
        *json*) ext=json ;;
        *text/plain*) ext=txt ;;
        *) ext=bin ;;
    esac
    cp "$s_src" "$DIR/$s_name.$ext"
    grep -iE '^(content-type|content-disposition):' "$s_src.headers" | tr -d '\r' > "$DIR/$s_name.$ext.headers" || true
    SAVED="$SAVED $s_name.$ext"
    printf '    -> %s.%s (%s)\n' "$s_name" "$ext" "${type# }"
}

show_error() {
    # Тело ошибки площадки — текст причины, секретов там нет.
    printf '    '
    head -c 300 "$1"
    echo
}

# probe МЕТОД ИМЯ ПУТЬ [ТЕЛО...] — перебирает кандидатов тела (у GET —
# кандидатов пути со строкой запроса) до первого 200.
probe() {
    p_method=$1
    p_name=$2
    shift 2
    attempt=0
    for candidate in "$@"; do
        attempt=$((attempt + 1))
        if [ "$p_method" = GET ]; then
            code=$(call GET "$candidate" "$WORK/$p_name")
            label=${candidate%%\?*}
        else
            code=$(call POST "$PROBE_PATH" "$WORK/$p_name" "$candidate")
            label=$PROBE_PATH
        fi
        printf '%-58s %-30s вариант %s  HTTP %s\n' "$label" "$p_name" "$attempt" "$code"
        if [ "$code" = 200 ]; then
            save "$WORK/$p_name" "$p_name"
            return 0
        fi
        show_error "$WORK/$p_name"
    done
    return 1
}

# --- 7. Форма отказа: неверный секрет и неверный токен -------------------
# Нужна, чтобы отличать «ключ отозван» (broken) от «площадка лежит».
printf 'Authorization: Bearer invalid\n' > "$WORK/auth.header"
python3 - "$WORK/token-request.json" "$WORK/bad-token-request.json" <<'PYEOF'
import json, sys
data = json.load(open(sys.argv[1]))
data['client_secret'] = 'invalid-' + 'x' * 16
json.dump(data, open(sys.argv[2], 'w'))
PYEOF
code=$(call POST /api/client/token "$WORK/auth-bad-secret" "$(cat "$WORK/bad-token-request.json")")
printf '%-58s %-30s HTTP %s\n' /api/client/token auth-bad-secret "$code"
show_error "$WORK/auth-bad-secret"
save "$WORK/auth-bad-secret" "auth-bad-secret"

code=$(call GET /api/client/campaign "$WORK/auth-bad-token")
printf '%-58s %-30s HTTP %s\n' /api/client/campaign auth-bad-token "$code"
show_error "$WORK/auth-bad-token"
save "$WORK/auth-bad-token" "auth-bad-token"

# --- Токен ----------------------------------------------------------------
code=$(call POST /api/client/token "$WORK/token" "$(cat "$WORK/token-request.json")")
rm -f "$WORK/token-request.json" "$WORK/bad-token-request.json"
if [ "$code" != 200 ]; then
    echo "Токен не выдан: HTTP $code"
    show_error "$WORK/token"
    exit 1
fi
python3 - "$WORK/token" "$WORK/auth.header" <<'PYEOF'
import json, sys
data = json.load(open(sys.argv[1]))
open(sys.argv[2], 'w').write('Authorization: Bearer ' + data['access_token'] + '\n')
print(f"Токен получен: expires_in={data.get('expires_in')}, token_type={data.get('token_type')}")
PYEOF
rm -f "$WORK/token"

echo
echo "Вчера: $YESTERDAY, неделя: $WEEK_FROM..$YESTERDAY, месяц: $MONTH_FROM..$YESTERDAY, год назад: $OLD_FROM..$OLD_TO"
echo

# --- Кампании ---------------------------------------------------------------
probe GET campaign-list '/api/client/campaign' || exit 1

# Кампании по типу: SKU — оплата за клик, SEARCH_PROMO — оплата за заказ.
# Для снимков берутся не больше десяти — это потолок одного отчёта.
eval "$(python3 - "$DIR/campaign-list.json" <<'PYEOF'
import json, sys
items = json.load(open(sys.argv[1])).get('list', [])
by_type = {}
for item in items:
    by_type.setdefault(item.get('advObjectType', '?'), []).append(str(item['id']))
print(f"CAMPAIGN_COUNT={len(items)}")
print(f"ALL_IDS='{' '.join(str(i['id']) for i in items[:10])}'")
print(f"SKU_IDS='{' '.join(by_type.get('SKU', [])[:3])}'")
print(f"FIRST_ID='{items[0]['id'] if items else ''}'")
summary = ', '.join(f"{t}: {len(ids)}" for t, ids in sorted(by_type.items()))
print(f"TYPES='{summary}'")
PYEOF
)"
echo "    кампаний: $CAMPAIGN_COUNT ($TYPES)"
if [ "$CAMPAIGN_COUNT" = 0 ]; then
    echo 'Кампаний нет — статистику снимать не на чем.'
    exit 0
fi

for id in $SKU_IDS; do
    probe GET "campaign-$id-products" "/api/client/campaign/$id/v2/products" || true
done

PROBE_PATH=/api/client/campaign/search_promo/v2/products
probe POST search-promo-products '{"page":1,"pageSize":100}' '{}' || true

# --- Синхронная статистика -------------------------------------------------
probe GET "statistics-expense-$MONTH_FROM" \
    "/api/client/statistics/expense/json?dateFrom=$MONTH_FROM&dateTo=$YESTERDAY" || true
probe GET "statistics-daily-$MONTH_FROM" \
    "/api/client/statistics/daily/json?dateFrom=$MONTH_FROM&dateTo=$YESTERDAY" || true

# 4. Глубина истории: тот же расход за месяц год назад.
probe GET "statistics-expense-$OLD_FROM" \
    "/api/client/statistics/expense/json?dateFrom=$OLD_FROM&dateTo=$OLD_TO" || true

# 3. products/sku: вчера и неделю назад. Документация говорит «dateFrom
# не раньше предыдущего дня» — проверяем, значит ли это «только вчера».
PROBE_PATH=/api/client/statistics/products/sku
probe POST "statistics-products-sku-$YESTERDAY" \
    "{\"dateFrom\":\"$YESTERDAY\",\"dateTo\":\"$YESTERDAY\"}" \
    "{\"dateFrom\":\"${YESTERDAY}T00:00:00Z\",\"dateTo\":\"${TODAY}T00:00:00Z\"}" || true
probe POST "statistics-products-sku-$WEEK_AGO" \
    "{\"dateFrom\":\"$WEEK_AGO\",\"dateTo\":\"$WEEK_AGO\"}" \
    "{\"dateFrom\":\"${WEEK_AGO}T00:00:00Z\",\"dateTo\":\"${DAY_BEFORE}T00:00:00Z\"}" || true

# --- Асинхронные отчёты ------------------------------------------------------
# Одновременно у аккаунта формируется один отчёт, поэтому они идут
# строго по очереди: заказать -> дождаться -> скачать -> следующий.
report_uuid() {
    python3 - "$1" <<'PYEOF'
import json, sys
data = json.load(open(sys.argv[1]))
print(data.get('UUID') or data.get('uuid') or '')
PYEOF
}

wait_and_download() {
    w_uuid=$1
    w_name=$2
    poll=0
    while [ "$poll" -lt 60 ]; do
        poll=$((poll + 1))
        sleep 5
        code=$(call GET "/api/client/statistics/$w_uuid" "$WORK/status")
        state=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("state",""))' "$WORK/status" 2>/dev/null || echo '?')
        printf '    опрос %2s: HTTP %s, state=%s\n' "$poll" "$code" "$state"
        case "$state" in
            OK) break ;;
            ERROR) show_error "$WORK/status"; return 1 ;;
        esac
    done
    [ "$state" = OK ] || { echo '    не дождались за 5 минут'; return 1; }
    cp "$WORK/status" "$WORK/$w_name-status"
    printf 'Content-Type: application/json\n' > "$WORK/$w_name-status.headers"
    save "$WORK/$w_name-status" "$w_name-status"

    code=$(call GET "/api/client/statistics/report?UUID=$w_uuid" "$WORK/$w_name")
    printf '%-58s %-30s HTTP %s\n' /api/client/statistics/report "$w_name" "$code"
    [ "$code" = 200 ] && save "$WORK/$w_name" "$w_name"
}

async_report() {
    a_name=$1
    shift
    if probe "$@"; then
        if [ ! -f "$DIR/$a_name.json" ]; then
            echo '    ответ на заказ отчёта — не JSON, UUID не извлечь'
            return 0
        fi
        uuid=$(report_uuid "$DIR/$a_name.json" 2>/dev/null || true)
        mv "$DIR/$a_name.json" "$DIR/$a_name-request.json"
        mv "$DIR/$a_name.json.headers" "$DIR/$a_name-request.json.headers"
        SAVED=$(printf '%s' "$SAVED" | sed "s| $a_name.json| $a_name-request.json|")
        echo "    UUID $uuid"
        [ -n "$uuid" ] && wait_and_download "$uuid" "$a_name" || true
    fi
}

# 2. Несколько кампаний в одном отчёте: JSON или ZIP?
CAMPAIGNS_JSON=$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1].split()))' "$ALL_IDS")
PROBE_PATH=/api/client/statistics/json
async_report "statistics-json-many-$WEEK_FROM" POST "statistics-json-many-$WEEK_FROM" \
    "{\"campaigns\":$CAMPAIGNS_JSON,\"dateFrom\":\"$WEEK_FROM\",\"dateTo\":\"$YESTERDAY\",\"groupBy\":\"DATE\"}"

# Одна кампания, CSV-вариант — для сравнения форм.
PROBE_PATH=/api/client/statistics
async_report "statistics-csv-one-$WEEK_FROM" POST "statistics-csv-one-$WEEK_FROM" \
    "{\"campaigns\":[\"$FIRST_ID\"],\"dateFrom\":\"$WEEK_FROM\",\"dateTo\":\"$YESTERDAY\",\"groupBy\":\"DATE\"}"

# 5. «Оплата за заказ»: заказы, выбранные товары.
PROBE_PATH=/api/client/statistic/orders/generate/json
async_report "cpo-orders-$WEEK_FROM" POST "cpo-orders-$WEEK_FROM" \
    "{\"from\":\"${WEEK_FROM}T00:00:00Z\",\"to\":\"${TODAY}T00:00:00Z\"}" \
    "{\"dateFrom\":\"$WEEK_FROM\",\"dateTo\":\"$YESTERDAY\"}"

# «Оплата за заказ»: заказы, все товары.
async_report "cpo-all-sku-orders-$WEEK_FROM" GET "cpo-all-sku-orders-$WEEK_FROM" \
    "/api/client/statistics/all_sku_promo/orders/generate/json?from=${WEEK_FROM}T00:00:00Z&to=${TODAY}T00:00:00Z" \
    "/api/client/statistics/all_sku_promo/orders/generate/json?timeBounds.from=${WEEK_FROM}T00:00:00Z&timeBounds.to=${TODAY}T00:00:00Z"

# --- Что пришло --------------------------------------------------------------
# Состав полей, а не значения: по нему решаются вопросы 1, 5 и 6.
echo
echo '-> состав полей:'
for file in $SAVED; do
    python3 - "$DIR/$file" <<'PYEOF'
import csv, io, json, sys, zipfile

path = sys.argv[1]
print(f'\n=== {path.rsplit("/", 1)[-1]}')


def shape(value, depth=0):
    pad = '   ' + '  ' * depth
    if isinstance(value, dict):
        for key, inner in list(value.items())[:40]:
            if isinstance(inner, (dict, list)):
                print(f'{pad}{key}:')
                shape(inner, depth + 1)
            else:
                print(f'{pad}{key} = {type(inner).__name__} {inner!r}'[:120])
    elif isinstance(value, list):
        print(f'{pad}[{len(value)} элементов]')
        if value:
            shape(value[0], depth + 1)


def csv_head(text):
    lines = text.splitlines()
    for line in lines[:3]:
        print(f'   | {line[:200]}')
    print(f'   строк: {len(lines)}')


if path.endswith('.json'):
    shape(json.load(open(path)))
elif path.endswith('.csv'):
    csv_head(open(path, encoding='utf-8-sig', errors='replace').read())
elif path.endswith('.zip'):
    with zipfile.ZipFile(path) as archive:
        for member in archive.namelist():
            print(f'   файл в архиве: {member}')
            csv_head(archive.read(member).decode('utf-8-sig', errors='replace'))
else:
    print(f'   {open(path, "rb").read(200)!r}')
PYEOF
done

echo
echo '-> файлы:'
ls -l "$DIR"
echo
echo 'Пришлите вывод целиком. Файлы не коммитить: сначала проверим их'
echo 'на персональные данные покупателей и контрагентов (CLAUDE.md §9).'
