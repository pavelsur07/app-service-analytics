#!/usr/bin/env bash

set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

test_successful_capture_saves_raw_pages_without_echoing_credentials() {
    local work fake_bin fixtures output
    work=$(mktemp -d)
    fake_bin="$work/bin"
    fixtures="$work/fixtures"
    output="$work/output.log"
    mkdir -p "$fake_bin"

    cat > "$fake_bin/date" <<'EOF'
#!/usr/bin/env bash
printf '2026-08-30\n'
EOF
    cat > "$fake_bin/curl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

out=''
url=''
body=''
while (($# > 0)); do
    case "$1" in
        -o)
            out=$2
            shift 2
            ;;
        -w)
            shift 2
            ;;
        --data-binary)
            body=$2
            shift 2
            ;;
        http*)
            url=$1
            shift
            ;;
        *)
            shift
            ;;
    esac
done

# Конфигурация приходит через stdin, чтобы Api-Key не попадал в argv.
cat >/dev/null

case "$url" in
    */v2/posting/fbo/list)
        [[ $(jq -r '.filter.since' <<<"$body") == '2026-07-31T21:00:00Z' ]] || exit 95
        [[ $(jq -r '.filter.to' <<<"$body") == '2026-08-30T20:59:59Z' ]] || exit 96
        printf '{"result":[]}' > "$out"
        ;;
    */v1/returns/list)
        [[ $(jq -r '.filter.visual_status_change_moment.time_from' <<<"$body") == '2026-07-31T21:00:00Z' ]] || exit 97
        [[ $(jq -r '.filter.visual_status_change_moment.time_to' <<<"$body") == '2026-08-30T20:59:59Z' ]] || exit 98
        printf '{"returns":[],"has_next":false}' > "$out"
        ;;
    */v1/posting/fbo/cancel-reason/list)
        printf '{"result":[{"id":352,"title":"Test reason","type_id":"seller"}]}' > "$out"
        ;;
    *)
        printf '{"code":404,"message":"unexpected endpoint"}' > "$out"
        printf '404'
        exit 0
        ;;
esac

printf '200'
EOF
    chmod +x "$fake_bin/date" "$fake_bin/curl"

    printf 'seller-42\nsecret-api-key\n2026-08-01\n2026-08-30\n\n' |
        PATH="$fake_bin:$PATH" OZON_FIXTURE_DIR="$fixtures" \
        bash "$ROOT/bin/capture-ozon-buyout-fixtures.sh" >"$output" 2>&1

    [[ -f "$fixtures/posting-fbo-list-buyout-2026-08-30-page-001.json" ]] ||
        fail 'не сохранена первая страница списка отправлений'
    [[ -f "$fixtures/returns-list-buyout-2026-08-30-page-001.json" ]] ||
        fail 'не сохранена первая страница возвратов'
    [[ -f "$fixtures/posting-fbo-cancel-reason-list-buyout-2026-08-30.json" ]] ||
        fail 'не сохранён справочник причин отмены FBO'
    [[ $(<"$fixtures/posting-fbo-list-buyout-2026-08-30-page-001.json") == '{"result":[]}' ]] ||
        fail 'тело ответа fbo/list было переформатировано'
    [[ $(<"$fixtures/returns-list-buyout-2026-08-30-page-001.json") == '{"returns":[],"has_next":false}' ]] ||
        fail 'тело ответа returns/list было переформатировано'
    ! grep -q 'secret-api-key' "$output" || fail 'Api-Key попал в вывод'
    ! grep -q 'seller-42' "$output" || fail 'Client-Id попал в вывод'
}

test_capture_follows_both_pagination_contracts() {
    local work fake_bin fixtures
    work=$(mktemp -d)
    fake_bin="$work/bin"
    fixtures="$work/fixtures"
    mkdir -p "$fake_bin"

    cat > "$fake_bin/date" <<'EOF'
#!/usr/bin/env bash
printf '2026-08-30\n'
EOF
    cat > "$fake_bin/curl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

out=''
url=''
body=''
while (($# > 0)); do
    case "$1" in
        -o)
            out=$2
            shift 2
            ;;
        -w)
            shift 2
            ;;
        --data-binary)
            body=$2
            shift 2
            ;;
        http*)
            url=$1
            shift
            ;;
        *)
            shift
            ;;
    esac
done
cat >/dev/null

case "$url" in
    */v2/posting/fbo/list)
        offset=$(jq -r '.offset' <<<"$body")
        if [[ "$offset" == 0 ]]; then
            jq -cn '{result: [range(0; 1000) | {posting_number: tostring}]}' > "$out"
        elif [[ "$offset" == 1000 ]]; then
            printf '{"result":[{"posting_number":"last"}]}' > "$out"
        else
            exit 92
        fi
        ;;
    */v1/returns/list)
        last_id=$(jq -r '.last_id' <<<"$body")
        if [[ "$last_id" == 0 ]]; then
            printf '{"returns":[{"id":77}],"has_next":true}' > "$out"
        elif [[ "$last_id" == 77 ]]; then
            printf '{"returns":[{"id":88}],"has_next":false}' > "$out"
        else
            exit 93
        fi
        ;;
    */v1/posting/fbo/cancel-reason/list)
        printf '{"result":[]}' > "$out"
        ;;
esac
printf '200'
EOF
    chmod +x "$fake_bin/date" "$fake_bin/curl"

    printf 'seller-42\nsecret-api-key\n2026-08-01\n2026-08-30\n\n' |
        PATH="$fake_bin:$PATH" OZON_FIXTURE_DIR="$fixtures" \
        bash "$ROOT/bin/capture-ozon-buyout-fixtures.sh" >/dev/null

    [[ -f "$fixtures/posting-fbo-list-buyout-2026-08-30-page-002.json" ]] ||
        fail 'полная FBO-страница не привела к запросу следующего offset'
    [[ -f "$fixtures/returns-list-buyout-2026-08-30-page-002.json" ]] ||
        fail 'has_next=true не привёл к запросу следующего last_id'
    [[ $(jq -r '.returns[0].id' "$fixtures/returns-list-buyout-2026-08-30-page-002.json") == 88 ]] ||
        fail 'вторая страница возвратов сохранена не из следующего last_id'
}

test_optional_posting_number_captures_detail_response() {
    local work fake_bin fixtures
    work=$(mktemp -d)
    fake_bin="$work/bin"
    fixtures="$work/fixtures"
    mkdir -p "$fake_bin"

    cat > "$fake_bin/date" <<'EOF'
#!/usr/bin/env bash
printf '2026-08-30\n'
EOF
    cat > "$fake_bin/curl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
out=''
url=''
body=''
while (($# > 0)); do
    case "$1" in
        -o) out=$2; shift 2 ;;
        -w) shift 2 ;;
        --data-binary) body=$2; shift 2 ;;
        http*) url=$1; shift ;;
        *) shift ;;
    esac
done
cat >/dev/null

case "$url" in
    */v2/posting/fbo/list) printf '{"result":[]}' > "$out" ;;
    */v1/returns/list) printf '{"returns":[],"has_next":false}' > "$out" ;;
    */v1/posting/fbo/cancel-reason/list) printf '{"result":[]}' > "$out" ;;
    */v2/posting/fbo/get)
        [[ $(jq -r '.posting_number' <<<"$body") == '40705738-0407-1' ]] || exit 94
        printf '{"result":{"posting_number":"40705738-0407-1"}}' > "$out"
        ;;
esac
printf '200'
EOF
    chmod +x "$fake_bin/date" "$fake_bin/curl"

    printf 'seller-42\nsecret-api-key\n2026-08-01\n2026-08-30\n40705738-0407-1\n' |
        PATH="$fake_bin:$PATH" OZON_FIXTURE_DIR="$fixtures" \
        bash "$ROOT/bin/capture-ozon-buyout-fixtures.sh" >/dev/null

    [[ -f "$fixtures/posting-fbo-get-buyout-2026-08-30.json" ]] ||
        fail 'указанный posting_number не сохранил детальный ответ'
    [[ $(jq -r '.result.posting_number' "$fixtures/posting-fbo-get-buyout-2026-08-30.json") == '40705738-0407-1' ]] ||
        fail 'fbo/get получил не указанный posting_number'
}

test_existing_fixture_is_not_overwritten_without_confirmation() {
    local work fake_bin fixtures status
    work=$(mktemp -d)
    fake_bin="$work/bin"
    fixtures="$work/fixtures"
    mkdir -p "$fake_bin" "$fixtures"
    printf 'keep-me' > "$fixtures/posting-fbo-list-buyout-2026-08-30-page-001.json"

    cat > "$fake_bin/date" <<'EOF'
#!/usr/bin/env bash
printf '2026-08-30\n'
EOF
    cat > "$fake_bin/curl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
out=''
url=''
while (($# > 0)); do
    case "$1" in
        -o) out=$2; shift 2 ;;
        -w) shift 2 ;;
        http*) url=$1; shift ;;
        *) shift ;;
    esac
done
cat >/dev/null
case "$url" in
    */v2/posting/fbo/list) printf '{"result":[]}' > "$out" ;;
    */v1/returns/list) printf '{"returns":[],"has_next":false}' > "$out" ;;
esac
printf '200'
EOF
    chmod +x "$fake_bin/date" "$fake_bin/curl"

    set +e
    printf 'seller-42\nsecret-api-key\n2026-08-01\n2026-08-30\n\nn\n' |
        PATH="$fake_bin:$PATH" OZON_FIXTURE_DIR="$fixtures" \
        bash "$ROOT/bin/capture-ozon-buyout-fixtures.sh" >/dev/null 2>&1
    status=$?
    set -e

    [[ $status -ne 0 ]] || fail 'отказ от перезаписи должен остановить запуск'
    [[ $(<"$fixtures/posting-fbo-list-buyout-2026-08-30-page-001.json") == 'keep-me' ]] ||
        fail 'существующая фикстура была перезаписана без подтверждения'
}

test_http_error_is_not_saved_as_a_fixture() {
    local work fake_bin fixtures status target
    work=$(mktemp -d)
    fake_bin="$work/bin"
    fixtures="$work/fixtures"
    target="$fixtures/posting-fbo-list-buyout-2026-08-30-page-001.json"
    mkdir -p "$fake_bin"

    cat > "$fake_bin/date" <<'EOF'
#!/usr/bin/env bash
printf '2026-08-30\n'
EOF
    cat > "$fake_bin/curl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
out=''
while (($# > 0)); do
    case "$1" in
        -o) out=$2; shift 2 ;;
        -w) shift 2 ;;
        *) shift ;;
    esac
done
cat >/dev/null
printf '{"code":7,"message":"permission denied"}' > "$out"
printf '403'
EOF
    chmod +x "$fake_bin/date" "$fake_bin/curl"

    set +e
    printf 'seller-42\nsecret-api-key\n2026-08-01\n2026-08-30\n\n' |
        PATH="$fake_bin:$PATH" OZON_FIXTURE_DIR="$fixtures" \
        bash "$ROOT/bin/capture-ozon-buyout-fixtures.sh" >/dev/null 2>&1
    status=$?
    set -e

    [[ $status -ne 0 ]] || fail 'HTTP 403 должен завершать сбор с ошибкой'
    [[ ! -e "$target" ]] || fail 'тело HTTP-ошибки сохранено как тестовая фикстура'
}

test_malformed_success_body_is_not_saved_as_a_fixture() {
    local work fake_bin fixtures status target
    work=$(mktemp -d)
    fake_bin="$work/bin"
    fixtures="$work/fixtures"
    target="$fixtures/posting-fbo-list-buyout-2026-08-30-page-001.json"
    mkdir -p "$fake_bin"

    cat > "$fake_bin/date" <<'EOF'
#!/usr/bin/env bash
printf '2026-08-30\n'
EOF
    cat > "$fake_bin/curl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
out=''
while (($# > 0)); do
    case "$1" in
        -o) out=$2; shift 2 ;;
        -w) shift 2 ;;
        *) shift ;;
    esac
done
cat >/dev/null
printf '<html>proxy failure</html>' > "$out"
printf '200'
EOF
    chmod +x "$fake_bin/date" "$fake_bin/curl"

    set +e
    printf 'seller-42\nsecret-api-key\n2026-08-01\n2026-08-30\n\n' |
        PATH="$fake_bin:$PATH" OZON_FIXTURE_DIR="$fixtures" \
        bash "$ROOT/bin/capture-ozon-buyout-fixtures.sh" >/dev/null 2>&1
    status=$?
    set -e

    [[ $status -ne 0 ]] || fail 'невалидный JSON с HTTP 200 должен завершать сбор с ошибкой'
    [[ ! -e "$target" ]] || fail 'невалидный JSON сохранён как тестовая фикстура'
}

test_changed_response_shape_fails_loudly() {
    local work fake_bin fixtures status
    work=$(mktemp -d)
    fake_bin="$work/bin"
    fixtures="$work/fixtures"
    mkdir -p "$fake_bin"

    cat > "$fake_bin/date" <<'EOF'
#!/usr/bin/env bash
printf '2026-08-30\n'
EOF
    cat > "$fake_bin/curl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
out=''
while (($# > 0)); do
    case "$1" in
        -o) out=$2; shift 2 ;;
        -w) shift 2 ;;
        *) shift ;;
    esac
done
cat >/dev/null
printf '{"unexpected":[]}' > "$out"
printf '200'
EOF
    chmod +x "$fake_bin/date" "$fake_bin/curl"

    set +e
    printf 'seller-42\nsecret-api-key\n2026-08-01\n2026-08-30\n\n' |
        PATH="$fake_bin:$PATH" OZON_FIXTURE_DIR="$fixtures" \
        bash "$ROOT/bin/capture-ozon-buyout-fixtures.sh" >/dev/null 2>&1
    status=$?
    set -e

    [[ $status -ne 0 ]] || fail 'изменившаяся схема ответа была принята как пустая выдача'
}

test_invalid_date_is_rejected_before_any_api_call() {
    local work fake_bin fixtures status marker
    work=$(mktemp -d)
    fake_bin="$work/bin"
    fixtures="$work/fixtures"
    marker="$work/curl-called"
    mkdir -p "$fake_bin"

    cat > "$fake_bin/date" <<'EOF'
#!/usr/bin/env bash
printf '2026-08-30\n'
EOF
    cat > "$fake_bin/curl" <<EOF
#!/usr/bin/env bash
touch '$marker'
exit 96
EOF
    chmod +x "$fake_bin/date" "$fake_bin/curl"

    set +e
    printf 'seller-42\nsecret-api-key\n30.08.2026\n2026-08-30\n\n' |
        PATH="$fake_bin:$PATH" OZON_FIXTURE_DIR="$fixtures" \
        bash "$ROOT/bin/capture-ozon-buyout-fixtures.sh" >/dev/null 2>&1
    status=$?
    set -e

    [[ $status -ne 0 ]] || fail 'дата не в YYYY-MM-DD должна быть отвергнута'
    [[ ! -e "$marker" ]] || fail 'при неверной дате был выполнен запрос к Ozon'
}

test_sensitive_fields_produce_warning_without_printing_their_values() {
    local work fake_bin fixtures output
    work=$(mktemp -d)
    fake_bin="$work/bin"
    fixtures="$work/fixtures"
    output="$work/output.log"
    mkdir -p "$fake_bin"

    cat > "$fake_bin/date" <<'EOF'
#!/usr/bin/env bash
printf '2026-08-30\n'
EOF
    cat > "$fake_bin/curl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
out=''
url=''
while (($# > 0)); do
    case "$1" in
        -o) out=$2; shift 2 ;;
        -w) shift 2 ;;
        http*) url=$1; shift ;;
        *) shift ;;
    esac
done
cat >/dev/null
case "$url" in
    */v2/posting/fbo/list)
        printf '{"result":[{"legal_info":{"company_name":"Third Party LLC","inn":"1234567890"}}]}' > "$out"
        ;;
    */v1/returns/list)
        printf '{"returns":[],"has_next":false}' > "$out"
        ;;
    */v1/posting/fbo/cancel-reason/list)
        printf '{"result":[]}' > "$out"
        ;;
esac
printf '200'
EOF
    chmod +x "$fake_bin/date" "$fake_bin/curl"

    printf 'seller-42\nsecret-api-key\n2026-08-01\n2026-08-30\n\n' |
        PATH="$fake_bin:$PATH" OZON_FIXTURE_DIR="$fixtures" \
        bash "$ROOT/bin/capture-ozon-buyout-fixtures.sh" >"$output" 2>&1

    grep -q 'ВНИМАНИЕ.*чувствительные поля' "$output" ||
        fail 'непустой legal_info не породил предупреждение'
    grep -q 'result.0.legal_info.company_name' "$output" ||
        fail 'предупреждение не указало путь чувствительного поля'
    ! grep -q 'Third Party LLC' "$output" ||
        fail 'значение чувствительного поля попало в вывод'
}

test_page_ceiling_fails_instead_of_silently_truncating() {
    local work fake_bin fixtures full_page status
    work=$(mktemp -d)
    fake_bin="$work/bin"
    fixtures="$work/fixtures"
    full_page="$work/full-page.json"
    mkdir -p "$fake_bin"
    jq -cn '{result: [range(0; 1000) | {posting_number: tostring}]}' > "$full_page"

    cat > "$fake_bin/date" <<'EOF'
#!/usr/bin/env bash
printf '2026-08-30\n'
EOF
    cat > "$fake_bin/curl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
out=''
url=''
while (($# > 0)); do
    case "$1" in
        -o) out=$2; shift 2 ;;
        -w) shift 2 ;;
        http*) url=$1; shift ;;
        *) shift ;;
    esac
done
cat >/dev/null
case "$url" in
    */v2/posting/fbo/list) cp "$FULL_PAGE" "$out" ;;
    */v1/returns/list) printf '{"returns":[],"has_next":false}' > "$out" ;;
esac
printf '200'
EOF
    chmod +x "$fake_bin/date" "$fake_bin/curl"

    set +e
    printf 'seller-42\nsecret-api-key\n2026-08-01\n2026-08-30\n\n' |
        PATH="$fake_bin:$PATH" FULL_PAGE="$full_page" OZON_FIXTURE_DIR="$fixtures" \
        bash "$ROOT/bin/capture-ozon-buyout-fixtures.sh" >/dev/null 2>&1
    status=$?
    set -e

    [[ $status -ne 0 ]] || fail '100 полных страниц были приняты как полная выгрузка'
}

test_successful_capture_saves_raw_pages_without_echoing_credentials
test_capture_follows_both_pagination_contracts
test_optional_posting_number_captures_detail_response
test_existing_fixture_is_not_overwritten_without_confirmation
test_http_error_is_not_saved_as_a_fixture
test_malformed_success_body_is_not_saved_as_a_fixture
test_changed_response_shape_fails_loudly
test_invalid_date_is_rejected_before_any_api_call
test_sensitive_fields_produce_warning_without_printing_their_values
test_page_ceiling_fails_instead_of_silently_truncating
printf 'PASS: capture-ozon-buyout-fixtures\n'
