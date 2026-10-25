#!/usr/bin/env bash
# Контракт сверки недели наблюдения ozon-fetcher (этап 8
# docs/plan/ozon-fetcher.md): 429 считаются по подключению и отдельно
# по API, порог — база + 3; 403 и пропуск дня в журнале сервиса — нарушение;
# сбой журнала или json-file вместо journald — отказ сверки, а не нулевой
# счёт. docker, journalctl и psql подменяются записанными ответами.

set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
OBSERVE="$ROOT/bin/ozon-fetcher-observe.sh"
ACC=019fe6ea-cd99-7af8-bf4a-623a5a31cf7b
CO=019fe6ea-cd6a-7c81-a869-883a0a562b47
OTHER=01a17223-68d8-7223-b10a-82c83f9fb302
DAY=2026-10-26
TS=1793016000000000 # 2026-10-26T12:00:00Z в микросекундах

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

# Окружение сверки: .env, docker (драйвер журнала), journalctl (записи
# из файлов по контейнеру), compose (ответы psql по тексту SQL).
setup() {
    work=$(mktemp -d)
    printf '%s\n' "OZON_FETCHER_CONNECTION_ALLOWLIST=$ACC" > "$work/.env"
    : > "$work/php.json"
    : > "$work/svc.json"
    printf '%s\n' "$DAY" > "$work/svc_days"
    printf '%s\n' "$DAY" > "$work/php_days"
    echo journald > "$work/driver"
    cat > "$work/docker" <<EOF
#!/bin/sh
cat '$work/driver'
EOF
    cat > "$work/journalctl" <<EOF
#!/bin/sh
[ -f '$work/journal_fail' ] && exit 1
case "\$*" in
    *conwix-ozon-fetcher-1*) cat '$work/svc.json' ;;
    *) cat '$work/php.json' ;;
esac
EOF
    cat > "$work/compose" <<EOF
#!/bin/sh
sql=\$(cat)
case "\$sql" in
    *'FROM marketplace_account'*) echo '$CO|25218|2026-08-09 14:26:15' ;;
    *'FROM fetch_task'*) cat '$work/svc_days' ;;
    *'period BETWEEN'*) cat '$work/php_days' ;;
    *service_halt*) echo 'service_halt: 0' ;;
    *) : ;;
esac
EOF
    chmod +x "$work/docker" "$work/journalctl" "$work/compose"
}

# Запись journald с JSON-сообщением.
entry() {
    jq -cn --arg msg "$1" --arg ts "$TS" '{__REALTIME_TIMESTAMP: $ts, MESSAGE: $msg}'
}

php_answer() { # api status client_id
    local name=Seller
    [[ $1 == performance ]] && name=Performance
    entry "$(jq -cn --arg msg "Ozon $name API answered {status}" --arg api "$1" --argjson st "$2" --arg c "$3" \
        '{message: $msg, context: {api: $api, status: $st, method: "POST", path: "/v3/finance/transaction/list", client_id: $c}}')" >> "$work/php.json"
}

svc_request() { # msg status account
    entry "$(jq -cn --arg m "$1" --argjson st "$2" --arg a "$3" \
        '{msg: $m, status: $st, marketplace_account_id: $a, endpoint: "/v3/posting/fbo/list"}')" >> "$work/svc.json"
}

run() {
    rc=0
    output=$(cd "$work" && DOTENV="$work/.env" COMPOSE="$work/compose" JOURNALCTL="$work/journalctl" \
        DOCKER="$work/docker" SINCE=$DAY UNTIL=$DAY sh "$OBSERVE" 2>&1) || rc=$?
}

test_429_threshold_is_per_api_and_per_connection() {
    setup
    for _ in 1 2; do php_answer seller 429 25218; done
    php_answer seller 429 99999 # чужой кабинет Seller — не в счёт
    svc_request 'ozon request' 429 "$ACC"
    svc_request 'ozon request' 429 "$OTHER" # чужое подключение — не в счёт
    svc_request 'ozon request' 200 "$ACC"
    for _ in 1 2 3; do php_answer performance 429 ads-1; done
    run
    [[ $rc == 0 ]] || fail "ровно порог (3 и 3) — нарушение: $output"
    grep -Eq "^$DAY +2 +1 +3 +3 +0 +3 +2\$" <<< "$output" || fail "неверный счёт: $output"

    php_answer seller 429 25218
    run
    [[ $rc == 1 ]] || fail 'порог Seller превышен, а нарушения нет'
    grep -q 'НАРУШЕН(seller)' <<< "$output" || fail 'нет пометки Seller'
    ! grep -q 'НАРУШЕН(performance)' <<< "$output" || fail 'Performance помечен из-за Seller'
}

test_403_is_reported() {
    setup
    php_answer seller 403 25218
    run
    [[ $rc == 1 ]] || fail '403 PHP не дал кода 1'
    grep -q 'PHP 2026-10-26 seller POST /v3/finance/transaction/list client=25218' <<< "$output" || fail "403 не в списке: $output"

    setup
    svc_request 'ozon performance request' 403 "$ACC"
    run
    [[ $rc == 1 ]] || fail '403 сервиса не дал кода 1'
}

test_missing_service_day_is_a_violation() {
    setup
    printf '%s\n' 2026-10-25 "$DAY" > "$work/php_days"
    printf '%s\n' 2026-10-24 "$DAY" > "$work/svc_days"
    run
    [[ $rc == 1 ]] || fail 'пропуск дня в сервисе не нарушение'
    grep -q 'нет в сервисе: 2026-10-25' <<< "$output" || fail "пропущенный день не назван: $output"
}

test_unreadable_journal_fails_closed() {
    setup
    touch "$work/journal_fail"
    run
    [[ $rc == 2 ]] || fail "сбой journalctl: код $rc вместо 2"

    setup
    echo json-file > "$work/driver"
    run
    [[ $rc == 2 ]] || fail "json-file вместо journald: код $rc вместо 2"
}

test_429_threshold_is_per_api_and_per_connection
test_403_is_reported
test_missing_service_day_is_a_violation
test_unreadable_journal_fails_closed
echo 'ozon-fetcher-observe: OK'
