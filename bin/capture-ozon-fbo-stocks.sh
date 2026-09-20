#!/usr/bin/env bash
# Человек запускает один read-only запрос Ozon FBO и сохраняет сырой JSON.
set -euo pipefail
umask 077

root=$(cd "$(dirname "$0")/.." && pwd)
dir=${OZON_FIXTURE_DIR:-"$root/api/tests/Fixtures/Marketplace/ozon"}
mkdir -p "$dir"
file="$dir/fbo-stocks-by-warehouse-probe-$(date -u +%Y%m%dT%H%M%SZ).json"
mkdir -p "$root/var"
tmp=$(mktemp "$root/var/.fbo-stocks-XXXXXX")
trap 'rm -f "$tmp"; unset key' EXIT INT TERM

read -rp 'Ozon Client-Id: ' client_id
read -rsp 'Ozon Api-Key: ' key
printf '\n'
[[ -n "$client_id" && -n "$key" && ! -e "$file" ]] || exit 1

status=$(printf 'header = "Client-Id: %s"\nheader = "Api-Key: %s"\nheader = "Content-Type: application/json"\n' "$client_id" "$key" |
    curl --config - -sS -X POST \
        'https://api-seller.ozon.ru/v1/product/info/stocks-by-warehouse/fbo' \
        --data-binary '{"limit":1000}' -o "$tmp" -w '%{http_code}')
[[ "$status" == 200 ]] || { printf 'Ozon HTTP %s; файл не сохранён.\n' "$status" >&2; exit 1; }

python3 - "$tmp" <<'PY'
import json
import sys

with open(sys.argv[1], encoding='utf-8') as response:
    data = json.load(response)
if not isinstance(data, dict) or not data or any(key in data for key in ('code', 'error', 'errors')):
    raise SystemExit('Ответ не похож на данные метода; файл не сохранён.')
PY
mv "$tmp" "$file"
printf 'Сохранён пробный JSON (проверить структуру и пагинацию): %s\n' "$file"
