#!/usr/bin/env bash

set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
TEST_WORK=''

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

cleanup() {
    [[ -z "$TEST_WORK" ]] || rm -rf -- "$TEST_WORK"
}

trap cleanup EXIT

test_smartcaptcha_secret_is_required_only_by_api() {
    local env_file missing_output config_output dummy_key
    TEST_WORK=$(mktemp -d)
    env_file="$TEST_WORK/production.env"
    missing_output="$TEST_WORK/missing-key.log"
    config_output="$TEST_WORK/config.json"
    dummy_key='smartcaptcha-test-only-do-not-use'

    printf '%s\n' \
        'IMAGE=example.invalid/conwix/api:test' \
        'APP_SECRET=test-app-secret' \
        'DATABASE_URL=postgresql://test:test@postgres:5432/test' \
        'APP_ENCRYPTION_MASTER_KEY=test-encryption-key' \
        'REGISTRATION_DOCUMENTS_VERSION=2026-09-02' \
        'ACME_EMAIL=test@example.invalid' \
        'POSTGRES_USER=test' \
        'POSTGRES_PASSWORD=test' \
        'POSTGRES_DB=test' > "$env_file"

    if docker compose --env-file "$env_file" -f "$ROOT/docker-compose.prod.yml" \
        config > "$missing_output" 2>&1; then
        fail 'production Compose принят без SMARTCAPTCHA_SERVER_KEY'
    fi
    grep -q 'SMARTCAPTCHA_SERVER_KEY' "$missing_output" ||
        fail 'production Compose упал не из-за отсутствующего SMARTCAPTCHA_SERVER_KEY'

    printf 'SMARTCAPTCHA_SERVER_KEY=%s\n' "$dummy_key" >> "$env_file"
    docker compose --env-file "$env_file" -f "$ROOT/docker-compose.prod.yml" \
        config --format json > "$config_output"

    jq -e --arg key "$dummy_key" \
        '.services.api.environment.SMARTCAPTCHA_SERVER_KEY == $key' \
        "$config_output" >/dev/null ||
        fail 'api не получил SMARTCAPTCHA_SERVER_KEY'
    jq -e \
        '[.services | to_entries[] | select(.key | startswith("worker-"))] | length > 0' \
        "$config_output" >/dev/null ||
        fail 'production Compose не содержит worker-сервисов для проверки'
    jq -e \
        '[.services | to_entries[] | select(.value.environment.SMARTCAPTCHA_SERVER_KEY? != null) | .key] == ["api"]' \
        "$config_output" >/dev/null ||
        fail 'SMARTCAPTCHA_SERVER_KEY передан не только api'
}

test_smartcaptcha_secret_is_required_only_by_api

printf 'OK: production SmartCaptcha secret is api-only\n'
