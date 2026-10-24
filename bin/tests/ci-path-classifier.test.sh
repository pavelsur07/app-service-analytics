#!/usr/bin/env bash

set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

assert_classification() {
    local paths=$1
    local expected_backend=$2
    local expected_frontend=$3
    local expected_services=$4
    local label=$5
    local actual expected
    expected=$(printf 'backend=%s\nfrontend=%s\nservices=%s' "$expected_backend" "$expected_frontend" "$expected_services")

    actual=$(printf '%s' "$paths" | "$ROOT/bin/ci-classify-paths")

    [[ "$actual" == "$expected" ]] ||
        fail "$label классифицирован неверно: $actual"
}

test_existing_classification_semantics_are_preserved() {
    local paths backend frontend services label

    while IFS='|' read -r paths backend frontend services label; do
        assert_classification "$paths" "$backend" "$frontend" "$services" "$label"
    done <<'CASES'
|true|true|true|пустой список
api/src/Identity/Domain/User.php|true|false|false|api
apps/seller/src/main.tsx|false|true|false|apps
packages/api-schema/openapi.json|true|true|false|общий API-контракт
bin/e2e-seed.sh|true|false|false|bin
docs/patterns.md|false|false|false|только документация
.github/workflows/ci.yml|true|true|true|workflow
docker-compose.yml|true|true|true|dev Compose
Makefile|true|true|true|Makefile
services/ozon-fetcher/internal/store/tasks.go|false|false|true|сервис
services/_contracts/marketplace-connections/v1/schema.json|true|false|true|контракт сервиса
CASES
}

test_production_compose_change_runs_both_suites() {
    assert_classification 'docker-compose.prod.yml' true true true 'production Compose'
}

test_early_force_all_match_survives_large_trailing_list() {
    local paths index
    paths=$(
        printf 'docker-compose.prod.yml\n'
        for ((index = 1; index <= 5000; index++)); do
            printf 'docs/%05d-%0240d.md\n' "$index" 0
        done
    )

    assert_classification "$paths" true true true 'production Compose с большим docs-хвостом'
}

test_existing_classification_semantics_are_preserved
test_production_compose_change_runs_both_suites
test_early_force_all_match_survives_large_trailing_list

printf 'OK: CI path classifier covers production Compose\n'
