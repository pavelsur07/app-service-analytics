#!/bin/sh
# Снимает фикстуры каталога Ozon с реального кабинета. Живой запрос
# к площадке делает человек, не агент и не конвейер (CLAUDE.md, «Периметр
# автономной работы»: ключей кабинетов в песочнице нет), поэтому скрипт
# спрашивает реквизиты и никуда их не сохраняет — ни в файл, ни в историю
# оболочки.
#
# Запроса два, и нужны они разному:
#
#   product-list       каталогу хватает его одного: sku приходит прямо
#                      в списке (проверено на фикстуре 2026-08-13 — sku
#                      те же, что в v3/product/info/list, до последнего
#                      товара), а карточка на сайте и строка продажи
#                      опознаются именно по sku;
#   product-info-list  названия, цены, комиссии, остатки — каталогу
#                      не нужны, но это исходные данные юнит-экономики,
#                      и снимать их отдельным походом в кабинет позже
#                      дороже, чем взять сейчас.
#
# Ответы сохраняются побайтово, без переформатирования: причёсанная
# фикстура проверяла бы не то, что отдаёт площадка.
#
#   bash bin/ozon-fixture.sh
#
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR="$ROOT/api/tests/Fixtures/Marketplace/ozon"
DATE=$(date +%F)
IDS=$(mktemp)
trap 'rm -f "$IDS"' EXIT

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

echo '-> v3/product/list (каталог кабинета)'
curl -sS -X POST https://api-seller.ozon.ru/v3/product/list -H "Client-Id: $CID" -H "Api-Key: $KEY" -H 'Content-Type: application/json' -d '{"filter":{"visibility":"ALL"},"limit":1000,"last_id":""}' -o "$DIR/product-list-$DATE.json"

python3 - "$DIR/product-list-$DATE.json" "$IDS" <<'PYEOF'
import json, sys
d = json.load(open(sys.argv[1]))
if 'result' not in d:
    sys.exit(f'   Площадка ответила ошибкой, а не списком: {d}')
r = d['result']
items = r.get('items', [])
print(f"   товаров: {len(items)}   total: {r.get('total')}   last_id: {r.get('last_id')!r}")
blank = [i for i in items if not i.get('sku')]
if blank:
    print(f'   без sku: {len(blank)} — товары без карточки, в каталог они не попадут')
if len(items) == 1000:
    print('   ВНИМАНИЕ: страница полная — есть вторая, нужен ещё запрос с last_id')
json.dump({"product_id": [i['product_id'] for i in items]}, open(sys.argv[2], 'w'))
PYEOF

echo
echo '-> v3/product/info/list (названия, цены, комиссии — впрок)'
curl -sS -X POST https://api-seller.ozon.ru/v3/product/info/list -H "Client-Id: $CID" -H "Api-Key: $KEY" -H 'Content-Type: application/json' -d @"$IDS" -o "$DIR/product-info-list-$DATE.json"

echo
echo '-> получилось:'
ls -l "$DIR"
echo
echo 'Не забыть закоммитить: git add api/tests/Fixtures/Marketplace/ozon/'
