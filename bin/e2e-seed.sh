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

seed_output=$(docker compose exec -T php-cli php bin/console app:identity:seed-ozon-sandbox-company \
    "E2E Sandbox LLC" "e2e-shop" "e2e-api-key")

company_id=$(printf '%s' "$seed_output" | grep -oE 'companyId=[^[:space:]]+' | cut -d= -f2)
account_id=$(printf '%s' "$seed_output" | grep -oE 'marketplaceAccountId=[^[:space:]]+' | cut -d= -f2)

if [ -z "$company_id" ] || [ -z "$account_id" ]; then
    echo "e2e-seed: не удалось разобрать companyId/marketplaceAccountId из вывода seed-команды" >&2
    exit 1
fi

docker compose exec -T php-cli php bin/console app:ingestion:import-ozon-fixture \
    "$company_id" "$account_id" 2026-07-01 \
    tests/Fixtures/Marketplace/ozon/posting-fbo-list-2026-07-01.json

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

mkdir -p var
printf '%s' "$company_id" > var/e2e-company-id
printf '%s' "$second_company_id" > var/e2e-second-company-id
printf '%s\n%s\n' "$user_email" "$user_password" > var/e2e-user-credentials

echo "e2e-seed: companyId=$company_id"
echo "e2e-seed: secondCompanyId=$second_company_id"
echo "e2e-seed: userEmail=$user_email"
