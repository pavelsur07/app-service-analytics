#!/bin/sh
# Снимает фикстуры каталога Ozon с реального кабинета: список товаров
# и детали по ним. Живой запрос к площадке делает человек, не агент
# и не конвейер (CLAUDE.md, «Периметр автономной работы»: ключей кабинетов
# в песочнице нет), поэтому скрипт спрашивает реквизиты и никуда их не
# сохраняет — ни в файл, ни в историю оболочки.
#
# Запросов два, потому что sku в первом ответе нет. v3/product/list отдаёт
# product_id и offer_id, а карточка на сайте и строка продажи опознаются
# по sku (число в конце URL товара) — его возвращает только
# v3/product/info/list. Каталог без sku нечем сопоставить ни с продажами,
# ни с открытой в браузере карточкой.
#
# Ответы сохраняются побайтово, без переформатирования: raw-слой хэширует
# точные байты (ADR-006), и причёсанная фикстура проверяла бы не то,
# что отдаёт площадка.
#
#   bash bin/ozon-fixture.sh
#
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR="$ROOT/api/tests/Fixtures/Marketplace/ozon"
DATE=$(date +%F)

printf 'Ozon Client-Id: '
read -r CID
printf 'Ozon Api-Key:   '
stty -echo 2>/dev/null || true
read -r KEY
stty echo 2>/dev/null || true
printf '\n\n'

if [ -z "$CID" ] || [ -z "$KEY" ]; then
    echo 'Пустые реквизиты — ничего не делаю.' >&2
    exit 1
fi

echo '-> v3/product/list (список товаров кабинета)'
curl -sS -X POST https://api-seller.ozon.ru/v3/product/list -H "Client-Id: $CID" -H "Api-Key: $KEY" -H 'Content-Type: application/json' -d '{"filter":{"visibility":"ALL"},"limit":1000,"last_id":""}' -o "$DIR/product-list-$DATE.json"

python3 - "$DIR/product-list-$DATE.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
if 'result' not in d:
    sys.exit(f'   Площадка ответила ошибкой, а не списком: {d}')
r = d['result']
items = r.get('items', [])
print(f"   товаров: {len(items)}   total: {r.get('total')}   last_id: {r.get('last_id')!r}")
if len(items) == 1000:
    print('   ВНИМАНИЕ: страница полная — есть вторая, нужен ещё один запрос с last_id')
json.dump({"product_id": [i['product_id'] for i in items]}, open('/tmp/ozon-ids.json', 'w'))
PY

echo
echo '-> v3/product/info/list (детали, здесь живёт sku)'
curl -sS -X POST https://api-seller.ozon.ru/v3/product/info/list -H "Client-Id: $CID" -H "Api-Key: $KEY" -H 'Content-Type: application/json' -d @/tmp/ozon-ids.json -o "$DIR/product-info-list-$DATE.json"
rm -f /tmp/ozon-ids.json

echo
echo '-> получилось:'
ls -l "$DIR"
