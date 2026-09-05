#!/bin/sh
# Заводит компанию, Ozon-подключение и пользователя-владельца, разбирает
# зафиксированную фикстуру в sales_fact — без обращения к реальному Ozon
# (ключей в песочнице нет, CLAUDE.md, «Периметр автономной работы»).
# Вызывается через `make test-e2e`, не напрямую.
#
# companyId и учётные данные пользователя печатаются в var/e2e-company-id
# и var/e2e-user-credentials, чтобы Playwright-сценарий мог войти и открыть
# экран компании — сеется заново при каждом запуске (не идемпотентно
# по компании: Company::register() всегда генерирует новый id), поэтому
# email пользователя тоже завязан на свежий company_id: тот же email
# на новую компанию при повторном прогоне без пересоздания базы иначе
# упёрся бы в уникальный индекс на email и не создал бы членство
# в новой компании.
#
# Компаний две, и это не избыточность: §10 требует сквозного сценария
# с выбором и переключением компании, а при единственной компании
# приложение уводит мимо списка автопереходом, и обе проверки
# не выполняются ни разу. Вторая компания намеренно без продаж —
# тогда переключение даёт заведомо различимую картину: были строки,
# стал пустой экран. Одинаковые данные не отличили бы переключение
# от кэша предыдущей компании, а это ровно тот риск, ради которого
# написано §7.
set -eu

cd "$(dirname "$0")/.."

# Сид разрушающий: ниже он удаляет всех администраторов, короткие ссылки
# и записи аудита, а затем заливает фикстуры в sales_fact. Отката на боевых
# данных у этого нет, поэтому цель проверяется до первой мутации — фактом
# окружения, а не договорённостью в документе.
#
# Проверяется ровно то соединение, в которое пойдут DELETE: имя базы.
# В песочнице она называется app, боевая — conwix, и роли app там нет вовсе.
# Пустой ответ (нет контейнера, нет роли, нет сети) — тоже отказ: не смогли
# убедиться, что цель верная, значит не сеем.
#
# APP_ENV для этой роли не годится, хотя и напрашивается: dev-образ её
# не задаёт (docker/php/Dockerfile, стадия dev наследует base, а не prod),
# поэтому проба возвращала бы пустоту в самой песочнице. А на проде нет
# сервиса php-cli, так что оттуда она не вернула бы 'prod' никогда.
#
# Прочие препятствия — на проде нет ни этого скрипта, ни сервиса php-cli —
# существуют, но возникли случайно, из-за несовпадения окружений. На них
# нельзя опираться как на защиту: они нигде не заявлены, поэтому никто
# не заметит, когда очередная правка инфраструктуры их снимет.
target_db=$(docker compose exec -T postgres psql -U app -d app -tAc \
    'SELECT current_database()' 2>/dev/null | tr -d '[:space:]')
if [ "$target_db" != "app" ]; then
    echo "e2e-seed.sh: целевая база '$target_db', ожидается app — отказ" >&2
    exit 1
fi

seed_output=$(docker compose exec -T php-cli php bin/console app:identity:seed-ozon-sandbox-company \
    "E2E Sandbox LLC" "e2e-shop" "e2e-api-key")

company_id=$(printf '%s' "$seed_output" | grep -oE 'companyId=[^[:space:]]+' | cut -d= -f2)
account_id=$(printf '%s' "$seed_output" | grep -oE 'marketplaceAccountId=[^[:space:]]+' | cut -d= -f2)

if [ -z "$company_id" ] || [ -z "$account_id" ]; then
    echo "e2e-seed: не удалось разобрать companyId/marketplaceAccountId из вывода seed-команды" >&2
    exit 1
fi

# Даты фикстур двигаются вместе с текущим днём: большой набор остаётся
# внутри 90, но вне 30 дней, а synthetic status history — внутри 30, но
# вне 7 дней. Так E2E не протухает через несколько недель после написания.
fixture_dir=$(mktemp -d api/var/e2e-buyout-fixtures.XXXXXX)
fixture_container_dir=${fixture_dir#api/}
trap 'rm -rf -- "$fixture_dir"' EXIT
historical_date=$(TZ=Europe/Moscow date -d '60 days ago' +%F)
synthetic_date=$(TZ=Europe/Moscow date -d '20 days ago' +%F)
second_synthetic_date=$(TZ=Europe/Moscow date -d '19 days ago' +%F)
maturity_date=$(TZ=Europe/Moscow date -d '40 days ago' +%F)

sed \
    -e "s/2026-06-30/$historical_date/g" \
    -e "s/2026-07-01/$historical_date/g" \
    api/tests/Fixtures/Marketplace/ozon/posting-fbo-list-2026-07-01.json \
    > "$fixture_dir/posting-history.json"

sed -E "s/2026-08-(01|02|03|04|05|06)/$synthetic_date/g" \
    api/tests/Fixtures/Marketplace/ozon/ozon-buyout-posting-statuses-before.json \
    > "$fixture_dir/posting-before.json"

sed -E "s/2026-08-(01|02|03|04|05|06)/$synthetic_date/g" \
    api/tests/Fixtures/Marketplace/ozon/ozon-buyout-posting-statuses-after.json \
    > "$fixture_dir/posting-after.json"

sed -E "s/2026-08-(11|12|13|14|15|16)/$synthetic_date/g" \
    api/tests/Fixtures/Marketplace/ozon/ozon-buyout-returns.json \
    > "$fixture_dir/returns.json"

# 30 пар handover -> terminal дают измеренный p95=0 для сквозной проверки
# mature actual-series. Первая строка — вторая дата SKU 100002; остальные
# лежат вне 30-дневного отчёта и служат только maturity sample.
jq -n --arg maturity "$maturity_date" --arg current "$second_synthetic_date" '
  {result: [range(1; 31) as $i |
    ($i == 1) as $target |
    (if $target then 100002 else 990000 end) as $sku |
    (if $target then $current else $maturity end) as $day |
    {
      order_number: "E2E-MAT-\($i)",
      posting_number: "E2E-MAT-\($i)-1",
      status: "delivering",
      substatus: "posting_on_way_to_city",
      cancel_reason_id: 0,
      in_process_at: "\($day)T08:05:00Z",
      products: [{sku: $sku, quantity: 1}],
      financial_data: {products: [{product_id: $sku, price: 100, commission_amount: 0}]}
    }
  ]}
' > "$fixture_dir/maturity-before.json"

jq -n --arg maturity "$maturity_date" --arg current "$second_synthetic_date" '
  {result: [range(1; 31) as $i |
    ($i == 1) as $target |
    (if $target then 100002 else 990000 end) as $sku |
    (if $target then $current else $maturity end) as $day |
    {
      order_number: "E2E-MAT-\($i)",
      posting_number: "E2E-MAT-\($i)-1",
      status: "delivered",
      substatus: "posting_received",
      cancel_reason_id: 0,
      in_process_at: "\($day)T08:05:00Z",
      products: [{sku: $sku, quantity: 1}],
      financial_data: {products: [{product_id: $sku, price: 100, commission_amount: 0}]}
    }
  ]}
' > "$fixture_dir/maturity-after.json"

docker compose exec -T php-cli php bin/console app:ingestion:import-ozon-fixture \
    "$company_id" "$account_id" "$historical_date" \
    "$fixture_container_dir/posting-history.json"

docker compose exec -T php-cli php bin/console app:ingestion:import-ozon-fixture \
    "$company_id" "$account_id" "$maturity_date" \
    "$fixture_container_dir/maturity-before.json"

docker compose exec -T php-cli php bin/console app:ingestion:import-ozon-fixture \
    "$company_id" "$account_id" "$maturity_date" \
    "$fixture_container_dir/maturity-after.json"

# Две последовательные фикстуры одного набора заказов дают настоящую
# status history (в пути raw -> parser -> writer), а не подготовленные
# агрегаты для UI. На ней экран «Выкуп» видит delivered/cancelled/pending,
# quantity > 1 и mixed order. Июльская фикстура выше оставляет больше
# 50 SKU в 90-дневном окне, чтобы E2E проходил keyset next/previous.
docker compose exec -T php-cli php bin/console app:ingestion:import-ozon-fixture \
    "$company_id" "$account_id" "$synthetic_date" \
    "$fixture_container_dir/posting-before.json"

docker compose exec -T php-cli php bin/console app:ingestion:import-ozon-fixture \
    "$company_id" "$account_id" "$synthetic_date" \
    "$fixture_container_dir/posting-after.json"

docker compose exec -T php-cli php bin/console app:ingestion:import-ozon-returns-fixture \
    "$company_id" "$account_id" "$synthetic_date" \
    "$fixture_container_dir/returns.json"

user_email="e2e-owner+${company_id}@example.com"
user_password="e2e-password"

docker compose exec -T php-cli php bin/console app:identity:create-user \
    "$user_email" "$user_password" "$company_id"

second_output=$(docker compose exec -T php-cli php bin/console app:identity:seed-ozon-sandbox-company \
    "E2E Sandbox Two" "e2e-shop-2" "e2e-api-key")
second_company_id=$(printf '%s' "$second_output" | grep -oE 'companyId=[^[:space:]]+' | cut -d= -f2)

if [ -z "$second_company_id" ]; then
    echo "e2e-seed: не удалось разобрать companyId второй компании" >&2
    exit 1
fi

docker compose exec -T php-cli php bin/console app:identity:add-company-member \
    "$user_email" "$second_company_id"

# Администратор системного контура (ADR-017). Сначала удаление, потом
# создание. Ссылки и их клики удаляются первыми: short_link хранит
# обязательного автора-администратора, а short_link_click — саму ссылку.
# Строка без автора в таблице администраторов возможна ровно одна
# (uq_administrator_bootstrap), поэтому повторный запуск сида иначе
# упёрся бы в ограничение. Сид — не боевые данные, детерминированность
# здесь важнее сохранности.
admin_email="e2e-admin@example.com"
admin_password="e2e-admin-password"

docker compose exec -T postgres psql -U app -d app -q \
    -c "DELETE FROM short_link_click" \
    -c "DELETE FROM short_link" \
    -c "DELETE FROM audit_record WHERE actor_admin_id IS NOT NULL" \
    -c "DELETE FROM administrator" > /dev/null

# Пароль приходит из пайпа: аргументом он оседал бы в истории оболочки
# и был бы виден в списке процессов, поэтому команда его не принимает.
printf '%s\n' "$admin_password" | docker compose exec -T php-cli \
    php bin/console app:identity:create-super-admin "$admin_email"

mkdir -p var
printf '%s\n%s\n' "$admin_email" "$admin_password" > var/e2e-admin-credentials
printf '%s' "$company_id" > var/e2e-company-id
printf '%s' "$second_company_id" > var/e2e-second-company-id
printf '%s\n%s\n' "$user_email" "$user_password" > var/e2e-user-credentials

echo "e2e-seed: companyId=$company_id"
echo "e2e-seed: secondCompanyId=$second_company_id"
echo "e2e-seed: userEmail=$user_email"
echo "e2e-seed: adminEmail=$admin_email"
