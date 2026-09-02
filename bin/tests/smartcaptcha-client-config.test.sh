#!/usr/bin/env bash

set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
TEST_WORK=''
TEST_CLIENT_KEY='ysc1_stage3_build_test_only'

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

cleanup() {
    [[ -z "$TEST_WORK" ]] || rm -rf -- "$TEST_WORK"
}

trap cleanup EXIT

test_node_seller_receives_only_the_public_client_key() {
    local config_output
    TEST_WORK=$(mktemp -d)
    config_output="$TEST_WORK/compose.json"

    (
        cd "$ROOT"
        env -u SMARTCAPTCHA_SERVER_KEY \
            SMARTCAPTCHA_CLIENT_KEY="$TEST_CLIENT_KEY" \
            docker compose config --format json > "$config_output"
    )

    jq -e --arg key "$TEST_CLIENT_KEY" \
        '.services["node-seller"].environment.VITE_SMARTCAPTCHA_CLIENT_KEY == $key' \
        "$config_output" >/dev/null ||
        fail 'node-seller не получил публичный VITE_SMARTCAPTCHA_CLIENT_KEY'
    jq -e \
        '.services["node-seller"].environment | has("SMARTCAPTCHA_SERVER_KEY") | not' \
        "$config_output" >/dev/null ||
        fail 'SMARTCAPTCHA_SERVER_KEY попал в frontend-конфигурацию node-seller'
}

test_vite_rejects_missing_key_and_accepts_public_key() {
    local invalid_output missing_output
    missing_output="$TEST_WORK/missing-key.log"
    invalid_output="$TEST_WORK/invalid-key.log"

    if docker compose exec -T -e VITE_SMARTCAPTCHA_CLIENT_KEY= \
        node-seller npm run build > "$missing_output" 2>&1; then
        fail 'production build принят без VITE_SMARTCAPTCHA_CLIENT_KEY'
    fi
    grep -Fq 'VITE_SMARTCAPTCHA_CLIENT_KEY must contain a valid public SmartCaptcha client key' \
        "$missing_output" ||
        fail 'production build упал не из-за отсутствующего public SmartCaptcha key'

    if docker compose exec -T -e VITE_SMARTCAPTCHA_CLIENT_KEY=invalid-key \
        node-seller npm run build > "$invalid_output" 2>&1; then
        fail 'production build принят с malformed VITE_SMARTCAPTCHA_CLIENT_KEY'
    fi
    grep -Fq 'VITE_SMARTCAPTCHA_CLIENT_KEY must contain a valid public SmartCaptcha client key' \
        "$invalid_output" ||
        fail 'production build упал не из-за malformed public SmartCaptcha key'

    docker compose exec -T -e "VITE_SMARTCAPTCHA_CLIENT_KEY=$TEST_CLIENT_KEY" \
        node-seller npm run build
}

test_node_seller_receives_only_the_public_client_key

if [[ "${SMARTCAPTCHA_CLIENT_CONFIG_RUN_BUILDS:-0}" = '1' ]]; then
    test_vite_rejects_missing_key_and_accepts_public_key
fi

printf 'OK: public SmartCaptcha client configuration is isolated and build-validated\n'
