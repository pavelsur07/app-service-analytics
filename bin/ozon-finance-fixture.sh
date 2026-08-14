#!/bin/sh
# Разведка финансовых отчётов Ozon: какие эндпоинты живы у кабинета
# и что в них лежит.
#
# Живой запрос к площадке делает человек, не агент и не конвейер
# (CLAUDE.md, «Периметр автономной работы»). Скрипт спрашивает реквизиты
# и никуда их не сохраняет — ни в файл, ни в историю оболочки.
#
# Отдельный файл от bin/ozon-fixture.sh намеренно: тот снимает известные
# отчёты и запускается всякий раз, когда нужна свежая фикстура каталога.
# Этот — одноразовая разведка, и перебор кандидатов в общем скрипте
# заставлял бы платить за него при каждом обновлении каталога.
#
# Почему перебор, а не один эндпоинт: /v3/finance/transaction/list
# объявлен отключённым 6 июля 2026 и заменён тремя методами
# finance/accrual/*. При этом у кабинета он отвечает 200 и отдаёт полные
# данные — то есть «работает» и «на нём можно строить» здесь разные вещи.
# Разведка отвечает на второй вопрос: живы ли методы-замены и что в них.
#
# Тела запросов новых методов — предположения по описанию. Отказ 400
# такой же полезный результат, как 200: в теле ошибки площадка говорит,
# чего ждёт. Поэтому у каждого эндпоинта несколько кандидатов тела,
# и перебор останавливается на первом успешном.
#
# Зачем это нужно вообще: в /v2/posting/fbo/list, который мы уже качаем,
# ровно один расход — комиссия (проверено на фикстуре: у всех строк
# с выплатой payout = price + commission_amount в точности). Ни логистики,
# ни хранения, ни рекламы там нет, и юнит-экономику из постингов
# не собрать.
#
# Ответы сохраняются побайтово, без переформатирования: причёсанная
# фикстура проверяла бы не то, что отдаёт площадка.
#
#   bash bin/ozon-finance-fixture.sh
#
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR="$ROOT/api/tests/Fixtures/Marketplace/ozon"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

# Период — прошлый полный календарный месяц: отчёт о реализации закрывается
# помесячно, и незакрытый текущий месяц показал бы неполную картину.
eval "$(python3 - <<'PYEOF'
import datetime as dt
today = dt.date.today()
first_of_this = today.replace(day=1)
last_of_prev = first_of_this - dt.timedelta(days=1)
first_of_prev = last_of_prev.replace(day=1)
print(f"MONTH={first_of_prev:%Y-%m}")
print(f"FROM={first_of_prev:%Y-%m-%d}T00:00:00.000Z")
print(f"TO={last_of_prev:%Y-%m-%d}T23:59:59.999Z")
print(f"STAMP={first_of_prev:%Y-%m}")
print(f"DAY_FROM={first_of_prev:%Y-%m-%d}")
print(f"DAY_TO={last_of_prev:%Y-%m-%d}")
PYEOF
)"

printf 'Ozon Client-Id: '
read -r CID
printf 'Ozon Api-Key:   '
stty -echo 2>/dev/null || true
read -r KEY
stty echo 2>/dev/null || true
printf '\n'

if [ -z "$CID" ] || [ -z "$KEY" ]; then
    echo 'Пустые реквизиты — ничего не делаю.' >&2
    exit 1
fi

echo "Период разведки: $MONTH (прошлый полный месяц)"
echo

# Кандидаты: имя файла | путь | тело запроса.
# Тела — по описанию площадки; отказ 400 на теле такой же результат
# разведки, как и 404 на пути: он говорит, что эндпоинт жив, но ждёт
# другого. Поэтому код ответа печатается для всех, а не только для 200.
probe() {
    name=$1
    path=$2
    shift 2

    # Кандидаты тела перебираются до первого успеха: форма запроса новых
    # методов известна нам по описанию, а не по ответу кабинета.
    attempt=0
    for body in "$@"; do
        attempt=$((attempt + 1))
        code=$(curl -sS -o "$WORK/$name.json" -w '%{http_code}' \
            -X POST "https://api-seller.ozon.ru$path" \
            -H "Client-Id: $CID" -H "Api-Key: $KEY" \
            -H 'Content-Type: application/json' \
            -d "$body" || echo '000')

        printf '%-38s %-22s тело %s  HTTP %s\n' "$path" "$name" "$attempt" "$code"

        if [ "$code" = "200" ]; then
            cp "$WORK/$name.json" "$DIR/finance-$name-$STAMP.json"
            SAVED="$SAVED $name"
            return 0
        fi

        # Тело ошибки Ozon — код и сообщение, секретов там нет.
        printf '    '
        head -c 220 "$WORK/$name.json"
        echo
    done
}

SAVED=''

# Методы-замены (объявлены взамен transaction/list с 6 июля 2026).
# Ради них разведка и повторяется: именно на них придётся строить.
probe accrual-types /v1/finance/accrual/types \
    '{}' \
    "{\"date_from\":\"$DAY_FROM\",\"date_to\":\"$DAY_TO\"}"

probe accrual-postings /v1/finance/accrual/postings \
    "{\"date_from\":\"$DAY_FROM\",\"date_to\":\"$DAY_TO\",\"page\":1,\"page_size\":1000}" \
    "{\"filter\":{\"date\":{\"from\":\"$FROM\",\"to\":\"$TO\"}},\"page\":1,\"page_size\":1000}" \
    "{\"date\":{\"from\":\"$DAY_FROM\",\"to\":\"$DAY_TO\"},\"limit\":1000,\"offset\":0}"

probe accrual-by-day /v1/finance/accrual/by-day \
    "{\"date_from\":\"$DAY_FROM\",\"date_to\":\"$DAY_TO\"}" \
    "{\"filter\":{\"date\":{\"from\":\"$FROM\",\"to\":\"$TO\"}}}" \
    "{\"date\":{\"from\":\"$DAY_FROM\",\"to\":\"$DAY_TO\"}}"

# Старый метод — для сверки, а не для стройки: он объявлен отключённым,
# но пока отвечает, и его итоги дают эталон, с которым сравниваются суммы
# новых методов. Расхождение — то, о чём документация предупреждает прямо.
probe transaction-list /v3/finance/transaction/list \
    "{\"filter\":{\"date\":{\"from\":\"$FROM\",\"to\":\"$TO\"},\"operation_type\":[],\"posting_number\":\"\",\"transaction_type\":\"all\"},\"page\":1,\"page_size\":1000}"

probe transaction-totals /v3/finance/transaction/totals \
    "{\"date\":{\"from\":\"$FROM\",\"to\":\"$TO\"},\"posting_number\":\"\",\"transaction_type\":\"all\"}"

probe cash-flow /v1/finance/cash-flow-statement/list \
    "{\"date\":{\"from\":\"$FROM\",\"to\":\"$TO\"},\"page\":1,\"page_size\":100,\"with_details\":true}"

echo
if [ -z "$SAVED" ]; then
    echo 'Ни один кандидат не ответил 200. Пришлите вывод выше — по кодам'
    echo 'и телам ошибок будет видно, что площадка ждёт.'
    exit 0
fi

echo '-> что пришло:'
for name in $SAVED; do
    python3 - "$DIR/finance-$name-$STAMP.json" "$name" <<'PYEOF'
import json, sys

path, name = sys.argv[1], sys.argv[2]
with open(path) as handle:
    data = json.load(handle)


def shape(value, depth=0):
    """Состав ключей, а не значения: в разведке важно, что вообще есть."""
    pad = '   ' + '  ' * depth
    if isinstance(value, dict):
        for key, inner in list(value.items())[:25]:
            if isinstance(inner, (dict, list)):
                print(f'{pad}{key}:')
                shape(inner, depth + 1)
            else:
                print(f'{pad}{key} = {inner!r}'[:110])
    elif isinstance(value, list):
        print(f'{pad}[{len(value)} элементов]')
        if value:
            shape(value[0], depth + 1)


print(f'\n=== {name}')
shape(data)

# Типы операций — то, ради чего разведка и делается: они говорят, какие
# расходы у кабинета вообще бывают.
raw = json.dumps(data, ensure_ascii=False)
for field in ('operation_type_name', 'operation_type', 'service_name', 'name'):
    values = set()

    def collect(node):
        if isinstance(node, dict):
            if field in node and isinstance(node[field], str):
                values.add(node[field])
            for inner in node.values():
                collect(inner)
        elif isinstance(node, list):
            for inner in node:
                collect(inner)

    collect(data)
    if values:
        print(f'   различных {field}: {len(values)}')
        for value in sorted(values)[:30]:
            print(f'      {value}')
PYEOF
done

echo
echo '-> файлы:'
ls -l "$DIR" | grep finance || true
echo
echo 'Пришлите вывод целиком. Файлы коммитить пока не нужно — сначала'
echo 'посмотрим, что там, и решим, какой отчёт становится источником'
echo 'расходов (это отдельный ADR).'
