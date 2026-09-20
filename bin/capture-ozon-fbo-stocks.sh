#!/usr/bin/env bash
# Снимает каталог SKU и сохраняет сырой ответ Ozon по остаткам FBO.
set -euo pipefail
umask 077

root=$(cd "$(dirname "$0")/.." && pwd)
dir=${OZON_FIXTURE_DIR:-"$root/api/tests/Fixtures/Marketplace/ozon"}
mkdir -p "$dir"
prefix="fbo-stocks-by-warehouse-$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$root/var"
tmp=$(mktemp "$root/var/.fbo-stocks-XXXXXX")
catalog=$(mktemp "$root/var/.ozon-catalog-XXXXXX")
pages=$(mktemp -d "$root/var/.ozon-fbo-pages-XXXXXX")
trap 'rm -f "$tmp" "$catalog"; rm -rf "$pages"; unset key' EXIT INT TERM

read -rp 'Ozon Client-Id: ' client_id
read -rsp 'Ozon Api-Key: ' key
printf '\n'
[[ -n "$client_id" && -n "$key" && ! -e "$dir/$prefix-page-001.json" ]] || exit 1

post() {
    printf 'header = "Client-Id: %s"\nheader = "Api-Key: %s"\nheader = "Content-Type: application/json"\n' "$client_id" "$key" |
        curl --config - -sS -X POST "https://api-seller.ozon.ru$1" \
            --data-binary "$2" -o "$3" -w '%{http_code}'
}

check_status() {
    if [[ "$1" == 200 ]]; then return; fi
    printf 'Ozon HTTP %s; файл не сохранён.\n' "$1" >&2
    python3 - "$2" <<'PY' >&2
import json
import sys

try:
    with open(sys.argv[1], encoding='utf-8') as response:
        error = json.load(response)
except (OSError, ValueError):
    error = {}
if isinstance(error, dict):
    print('Код Ozon:', error.get('code', '—'))
    print('Сообщение:', error.get('message', 'нет текста ошибки'))
PY
    exit 1
}

status=$(post '/v3/product/list' '{"filter":{"visibility":"ALL"},"limit":1000,"last_id":""}' "$catalog")
check_status "$status" "$catalog"
body=$(python3 - "$catalog" <<'PY'
import json
import sys

with open(sys.argv[1], encoding='utf-8') as response:
    result = json.load(response).get('result', {})
items = result.get('items', [])
skus = [item['sku'] for item in items if isinstance(item.get('sku'), int) and item['sku'] > 0]
if not skus or len(skus) != len(items) or result.get('total', len(items)) > len(items):
    raise SystemExit('Каталог пустой, содержит SKU без номера или требует следующую страницу; файл не сохранён.')
print(json.dumps({'skus': skus, 'limit': 1000}, separators=(',', ':')))
PY
)

page=1
previous_cursor=''
while :; do
    status=$(post '/v1/product/info/stocks-by-warehouse/fbo' "$body" "$tmp")
    check_status "$status" "$tmp"
    cursor=$(python3 - "$tmp" <<'PY'
import json
import sys

with open(sys.argv[1], encoding='utf-8') as response:
    data = json.load(response)
if not isinstance(data, dict) or not isinstance(data.get('products'), list) or not isinstance(data.get('has_next'), bool):
    raise SystemExit('Ответ не похож на страницу остатков; файлы не сохранены.')
if data['has_next'] and (not isinstance(data.get('cursor'), str) or not data['cursor']):
    raise SystemExit('Нет курсора следующей страницы; файлы не сохранены.')
print(data['cursor'] if data['has_next'] else '')
PY
    )
    cp "$tmp" "$pages/$(printf 'page-%03d.json' "$page")"
    [[ -n "$cursor" ]] || break
    [[ "$cursor" != "$previous_cursor" ]] || { printf 'Ozon повторил курсор; файлы не сохранены.\n' >&2; exit 1; }
    body=$(python3 - "$body" "$cursor" <<'PY'
import json
import sys

body = json.loads(sys.argv[1])
body['cursor'] = sys.argv[2]
print(json.dumps(body, separators=(',', ':')))
PY
    )
    previous_cursor=$cursor
    page=$((page + 1))
done

for source in "$pages"/page-*.json; do
    mv "$source" "$dir/$prefix-${source##*/}"
done
printf 'Сохранено страниц остатков: %s; каталог: %s/%s-page-*.json\n' "$page" "$dir" "$prefix"
