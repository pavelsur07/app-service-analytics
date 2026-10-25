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
    # Покрытие: задачи сервиса «ресурс|день|успех» и дни PHP «тип|день».
    printf '%s\n' "ozon.orders.fbo|$DAY|t" "ozon.finance.accrual-by-day|$DAY|t" \
        "ozon.ads.expense|$DAY|t" "ozon.ads.daily|$DAY|t" > "$work/svc_rows"
    printf '%s\n' "ozon_posting_fbo_list|$DAY" > "$work/php_rows"
    : > "$work/attempts"
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
    *service_halt*) echo 'service_halt: 0' ;;
    *'FROM fetch_task'*) cat '$work/svc_rows' ;;
    *'period BETWEEN'*) cat '$work/php_rows' ;;
    *'FROM fetch_attempt'*) cat '$work/attempts' ;;
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
        DOCKER="$work/docker" SINCE=${SINCE:-$DAY} UNTIL=$DAY JOURNALD_SINCE=$DAY sh "$OBSERVE" 2>&1) || rc=$?
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
    grep -Eq "^$DAY +2 +1 +3 +3 +0 +3 +2 +0\$" <<< "$output" || fail "неверный счёт: $output"

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
    # 25.10 у сервиса есть задача, но успешной загрузки не было; 24.10 PHP
    # не загружал; 26.10 сервис перезагружает (pending), но уже загружал.
    printf '%s\n' "ozon.orders.fbo|2026-10-24|t" "ozon.orders.fbo|2026-10-25|f" "ozon.orders.fbo|$DAY|t" \
        "ozon.finance.accrual-by-day|$DAY|t" "ozon.ads.expense|$DAY|t" "ozon.ads.daily|$DAY|t" > "$work/svc_rows"
    printf '%s\n' "ozon_posting_fbo_list|2026-10-25" "ozon_posting_fbo_list|$DAY" > "$work/php_rows"
    run
    [[ $rc == 1 ]] || fail "день без успешной загрузки сервиса не нарушение: $output"
    grep -q 'ozon.orders.fbo: сервис не загрузил: 2026-10-25$' <<< "$output" || fail "пропущенный день не назван: $output"

    setup
    grep -v ads.daily "$work/svc_rows" > "$work/svc_rows.new" && mv "$work/svc_rows.new" "$work/svc_rows"
    run
    [[ $rc == 1 ]] || fail 'ресурс без задач сервиса не нарушение'
}

test_incomplete_days_are_not_success() {
    setup
    SINCE=2026-10-25 run
    [[ $rc == 2 ]] || fail "сутки до journald: код $rc вместо 2"
    grep -q '2026-10-25 .*НЕ СОБРАНО(журнал до journald)' <<< "$output" || fail "сутки до journald не помечены: $output"

    setup
    printf '%s\n' "$DAY|12" > "$work/attempts" # попытки есть, записей запросов в журнале нет
    run
    [[ $rc == 2 ]] || fail "попытки без журнала: код $rc вместо 2"

    setup
    printf '%s\n' "$DAY|12" > "$work/attempts"
    php_answer seller 403 25218
    run
    [[ $rc == 1 ]] || fail "403 при неполных сутках: код $rc вместо 1 — признак спрятан"
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
test_incomplete_days_are_not_success
echo 'ozon-fetcher-observe: OK'
