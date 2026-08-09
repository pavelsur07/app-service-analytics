#!/bin/sh
# Заводит компанию и Ozon-подключение, разбирает зафиксированную фикстуру
# в sales_fact — без обращения к реальному Ozon (ключей в песочнице нет,
# CLAUDE.md, «Периметр автономной работы»). Вызывается через
# `make test-e2e`, не напрямую.
#
# companyId печатается в var/e2e-company-id, чтобы Playwright-сценарий
# мог открыть экран по прямой ссылке — сеется заново при каждом запуске
# (не идемпотентно по компании: Company::register() всегда генерирует
# новый id), поэтому свежий id должен дойти до теста, а не быть зашит.
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

mkdir -p var
printf '%s' "$company_id" > var/e2e-company-id

echo "e2e-seed: companyId=$company_id"
