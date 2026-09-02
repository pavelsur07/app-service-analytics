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
    local label=$4
    local actual expected
    expected=$(printf 'backend=%s\nfrontend=%s' "$expected_backend" "$expected_frontend")

    actual=$(printf '%s' "$paths" | "$ROOT/bin/ci-classify-paths")

    [[ "$actual" == "$expected" ]] ||
        fail "$label классифицирован неверно: $actual"
}

test_existing_classification_semantics_are_preserved() {
    local paths backend frontend label

    while IFS='|' read -r paths backend frontend label; do
        assert_classification "$paths" "$backend" "$frontend" "$label"
    done <<'CASES'
|true|true|пустой список
api/src/Identity/Domain/User.php|true|false|api
apps/seller/src/main.tsx|false|true|apps
packages/api-schema/openapi.json|true|true|общий API-контракт
bin/e2e-seed.sh|true|false|bin
docs/patterns.md|false|false|только документация
.github/workflows/ci.yml|true|true|workflow
docker-compose.yml|true|true|dev Compose
CASES
}

test_production_compose_change_runs_both_suites() {
    assert_classification 'docker-compose.prod.yml' true true 'production Compose'
}

test_early_force_all_match_survives_large_trailing_list() {
    local paths index
    paths=$(
        printf 'docker-compose.prod.yml\n'
        for ((index = 1; index <= 5000; index++)); do
            printf 'docs/%05d-%0240d.md\n' "$index" 0
        done
    )

    assert_classification "$paths" true true 'production Compose с большим docs-хвостом'
}

test_existing_classification_semantics_are_preserved
test_production_compose_change_runs_both_suites
test_early_force_all_match_survives_large_trailing_list

printf 'OK: CI path classifier covers production Compose\n'
