#!/usr/bin/env bash
# Снимает живые ответы Ozon для исследования остатков по кластерам
# (docs/plan/ozon-stock-placement-report.md, пакет 0). Только чтение:
# ни один запрос ничего не меняет в кабинете.
#
# Реквизиты читаются интерактивно и передаются curl через stdin-конфиг:
# Api-Key не попадает ни в историю shell, ни в argv процесса, ни в фикстуры.
#
#   bin/capture-ozon-stock-fixtures.sh            снять фикстуры
#   bin/capture-ozon-stock-fixtures.sh --dry-run  показать запросы, ничего не отправляя
#
# Порядок: каталог (/v3/product/list → /v3/product/info/list) даёт SKU;
# по ним /v1/analytics/stocks пачками; затем /v1/cluster/list
# и /v2/analytics/stock_on_warehouses. Ответ не 200 не сохраняется
# как фикстура — тело ошибки печатается, по нему правится контракт запроса.

set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=${OZON_FIXTURE_DIR:-"$ROOT/api/tests/Fixtures/Marketplace/ozon/stocks"}
STAMP=$(date +%F)
WORK=$(mktemp -d)
DRY_RUN=false
MAX_PAGES=100
STOCKS_BATCH=100
trap 'unset KEY; rm -rf "$WORK"' EXIT INT TERM

if [[ "${1:-}" == --dry-run ]]; then
    DRY_RUN=true
fi

for tool in curl jq; do
    command -v "$tool" >/dev/null || { printf 'Нужен %s.\n' "$tool" >&2; exit 1; }
done

CID=''
KEY=''
if [[ "$DRY_RUN" != true ]]; then
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
    if [[ -z "$CID" || -z "$KEY" ]]; then
        printf 'Пустые реквизиты — запросы не выполнялись.\n' >&2
        exit 1
    fi
fi

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

# request <путь> <тело> <файл> <jq-контракт>
request() {
    local path=$1 body=$2 target=$3 contract=$4 code answer
    local temporary="$WORK/response.json" headers="$WORK/response.headers"

    if [[ "$DRY_RUN" == true ]]; then
        printf 'POST %s\n  тело: %s\n  → %s\n' "$path" "$body" "$target"
        return 0
    fi

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
                --data-binary "$body" -D "$headers" -o "$temporary" -w '%{http_code}'
    )

    if [[ "$code" != 200 ]]; then
        # Тело ошибки Ozon — код и сообщение о неверном поле, данных кабинета
        # в нём нет; оно нужно, чтобы поправить контракт запроса.
        printf '%s: Ozon ответил HTTP %s; как фикстура не сохранено. Тело ответа:\n' "$path" "$code" >&2
        head -c 2000 "$temporary" >&2 || true
        printf '\n' >&2
        exit 1
    fi
    if ! jq -e . "$temporary" >/dev/null; then
        printf '%s: HTTP 200 содержит невалидный JSON; ответ не сохранён как фикстура.\n' "$path" >&2
        exit 1
    fi
    if ! jq -e "$contract" "$temporary" >/dev/null; then
        printf '%s: структура ответа не та, что ожидалась; как фикстура не сохранено. Ключи верхнего уровня: %s\n' \
            "$path" "$(jq -c 'keys' "$temporary")" >&2
        exit 1
    fi

    mv "$temporary" "$target"
    # Заголовки — рядом, как у фикстур рекламы: по ним видны лимиты.
    grep -i -v -E '^(set-cookie|authorization|api-key|client-id):' "$headers" > "$target.headers" || true
    printf 'Сохранено: %s\n' "$target"
    scan_sensitive "$target"
}

# 1. Каталог → product_id.
PRODUCT_IDS="$WORK/product-ids.txt"
: > "$PRODUCT_IDS"
last_id=''
catalog_complete=false
for ((page = 1; page <= MAX_PAGES; page++)); do
    printf -v page_number '%03d' "$page"
    TARGET="$DIR/product-list-$STAMP-page-$page_number.json"
    BODY=$(jq -cn --arg lastId "$last_id" '{filter: {visibility: "ALL"}, last_id: $lastId, limit: 1000}')
    request '/v3/product/list' "$BODY" "$TARGET" '(.result.items | type) == "array"'
    if [[ "$DRY_RUN" == true ]]; then
        catalog_complete=true
        break
    fi

    jq -r '.result.items[].product_id' "$TARGET" >> "$PRODUCT_IDS"
    count=$(jq '.result.items | length' "$TARGET")
    next=$(jq -r '.result.last_id // empty' "$TARGET")
    if ((count < 1000)) || [[ -z "$next" || "$next" == "$last_id" ]]; then
        catalog_complete=true
        break
    fi
    last_id=$next
done
if [[ "$catalog_complete" != true ]]; then
    printf '/v3/product/list: достигнут предел %s страниц.\n' "$MAX_PAGES" >&2
    exit 1
fi

# 2. product_id → sku (пачками по 1000).
SKUS="$WORK/skus.txt"
: > "$SKUS"
if [[ "$DRY_RUN" == true ]]; then
    request '/v3/product/info/list' '{"product_id":["<до 1000 product_id>"]}' "$DIR/product-info-list-$STAMP-page-001.json" '(.items | type) == "array"'
    echo 111 > "$SKUS"
else
    split -l 1000 "$PRODUCT_IDS" "$WORK/pid-chunk-"
    page=0
    for chunk in "$WORK"/pid-chunk-*; do
        page=$((page + 1))
        printf -v page_number '%03d' "$page"
        TARGET="$DIR/product-info-list-$STAMP-page-$page_number.json"
        BODY=$(jq -R -s -c 'split("\n") | map(select(length > 0)) | {product_id: .}' "$chunk")
        request '/v3/product/info/list' "$BODY" "$TARGET" '(.items | type) == "array"'
        jq -r '.items[] | (.sku // empty), (.sources[]?.sku // empty)' "$TARGET" >> "$SKUS"
    done
    sort -u -o "$SKUS" "$SKUS"
    if [[ ! -s "$SKUS" ]]; then
        printf 'В каталоге не нашлось ни одного SKU — остатки запрашивать не по чему.\n' >&2
        exit 1
    fi
    printf 'SKU в каталоге: %s\n' "$(wc -l < "$SKUS" | tr -d ' ')"
fi

# 3. Аналитика остатков по кластерам — пачками SKU.
split -l "$STOCKS_BATCH" "$SKUS" "$WORK/sku-chunk-"
page=0
for chunk in "$WORK"/sku-chunk-*; do
    page=$((page + 1))
    printf -v page_number '%03d' "$page"
    TARGET="$DIR/analytics-stocks-$STAMP-page-$page_number.json"
    BODY=$(jq -R -s -c 'split("\n") | map(select(length > 0)) | {skus: .}' "$chunk")
    request '/v1/analytics/stocks' "$BODY" "$TARGET" '(.items | type) == "array"'
done

# 4. Справочник кластеров и их складов.
request '/v1/cluster/list' '{"cluster_type":"CLUSTER_TYPE_OZON"}' "$DIR/cluster-list-$STAMP.json" '(.clusters | type) == "array"'

# 5. Остаток по складам (прежний метод) — для сравнения полноты.
offset=0
warehouses_complete=false
for ((page = 1; page <= MAX_PAGES; page++)); do
    printf -v page_number '%03d' "$page"
    TARGET="$DIR/stock-on-warehouses-$STAMP-page-$page_number.json"
    BODY=$(jq -cn --argjson offset "$offset" '{limit: 1000, offset: $offset, warehouse_type: "ALL"}')
    request '/v2/analytics/stock_on_warehouses' "$BODY" "$TARGET" '(.result.rows | type) == "array"'
    if [[ "$DRY_RUN" == true ]]; then
        warehouses_complete=true
        break
    fi
    count=$(jq '.result.rows | length' "$TARGET")
    if ((count < 1000)); then
        warehouses_complete=true
        break
    fi
    offset=$((offset + count))
done
if [[ "$warehouses_complete" != true ]]; then
    printf '/v2/analytics/stock_on_warehouses: достигнут предел %s страниц.\n' "$MAX_PAGES" >&2
    exit 1
fi

if [[ "$DRY_RUN" == true ]]; then
    printf '\nDry-run: запросы не отправлялись.\n'
else
    printf '\nГотово. Фикстуры в %s — пришлите агенту путь, git add не делайте:\n' "$DIR"
    printf 'сначала он проверит их на данные покупателей и третьих лиц (CLAUDE.md §9).\n'
fi
