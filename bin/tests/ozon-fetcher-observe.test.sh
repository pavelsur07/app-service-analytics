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
    # Первая запись сервиса в journald — сутками раньше наблюдения.
    jq -cn --arg ts "$((TS - 86400000000))" '{__REALTIME_TIMESTAMP: $ts}' > "$work/first"
    cat > "$work/journalctl" <<EOF
#!/bin/sh
[ -f '$work/journal_fail' ] && exit 1
case "\$*" in
    *CONTAINER_NAME=conwix-ozon-fetcher-1*) cat '$work/first' ;;
    *_SYSTEMD_UNIT=docker.service*) cat '$work/php.json' '$work/svc.json' ;;
    *) exit 64 ;;
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
    # Сервис жив оба дня: запись без запроса к Ozon (опрос подключений).
    for ts in "$((TS - 86400000000))" "$TS"; do
        jq -cn --arg ts "$ts" --arg msg '{"msg":"connections refreshed"}' \
            '{__REALTIME_TIMESTAMP: $ts, CONTAINER_NAME: "conwix-ozon-fetcher-1", MESSAGE: $msg}' >> "$work/svc.json"
    done
}

# Запись journald драйвера docker: контейнер и JSON-сообщение.
entry() { # container message
    jq -cn --arg c "$1" --arg msg "$2" --arg ts "$TS" '{__REALTIME_TIMESTAMP: $ts, CONTAINER_NAME: $c, MESSAGE: $msg}'
}

php_answer() { # api status client_id [container]
    local name=Seller
    [[ $1 == performance ]] && name=Performance
    entry "${4:-conwix-worker-ingestion-1}" "$(jq -cn --arg msg "Ozon $name API answered {status}" --arg api "$1" --argjson st "$2" --arg c "$3" \
        '{message: $msg, context: {api: $api, status: $st, method: "POST", path: "/v3/finance/transaction/list", client_id: $c}}')" >> "$work/php.json"
}

svc_request() { # msg status account
    entry conwix-ozon-fetcher-1 "$(jq -cn --arg m "$1" --argjson st "$2" --arg a "$3" \
        '{msg: $m, status: $st, marketplace_account_id: $a, endpoint: "/v3/posting/fbo/list"}')" >> "$work/svc.json"
}

run() {
    rc=0
    output=$(cd "$work" && DOTENV="$work/.env" COMPOSE="$work/compose" JOURNALCTL="$work/journalctl" \
        DOCKER="$work/docker" SINCE=${SINCE:-$DAY} UNTIL=$DAY sh "$OBSERVE" 2>&1) || rc=$?
}

test_429_threshold_is_per_api_and_per_connection() {
    setup
    php_answer seller 429 25218
    php_answer seller 429 25218 conwix-api-run-0a1b2c3d4e5f # ручной `compose run` — в счёте
    php_answer seller 429 99999 # чужой кабинет Seller — не в счёт
    php_answer seller 429 25218 other-project-api-1 # чужой контейнер — не в счёт
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

    # 403 в сутки перевода журналов (частичные) — тоже признак.
    setup
    jq -cn --arg ts "$TS" '{__REALTIME_TIMESTAMP: $ts}' > "$work/first"
    php_answer seller 403 25218
    run
    [[ $rc == 1 ]] || fail "403 в частичные сутки: код $rc вместо 1"
    grep -q "$DAY .*ЧАСТИЧНО" <<< "$output" || fail "сутки перевода не помечены: $output"
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
    # Первая запись сервиса — 25.10: эти сутки частичные, 24.10 не собраны.
    setup
    SINCE=2026-10-24 run
    [[ $rc == 2 ]] || fail "сутки до journald: код $rc вместо 2"
    grep -q '2026-10-24 .*НЕ СОБРАНО(журнал до journald)' <<< "$output" || fail "сутки до journald не помечены: $output"
    grep -q '2026-10-25 .*ЧАСТИЧНО(журнал с выкладки)' <<< "$output" || fail "сутки перевода не помечены: $output"
    grep -q "журнал собран с 2026-10-25" <<< "$output" || fail "JOURNALD_START не выведен из первой записи: $output"

    setup
    SINCE=2026-10-25 run
    [[ $rc == 0 ]] || fail "сутки перевода журналов дали код $rc — так кончался бы каждый запуск недели"

    setup
    SINCE=2026-10-27 run
    [[ $rc == 2 ]] || fail "SINCE позже UNTIL: код $rc вместо 2"

    setup
    : > "$work/first" # у сервиса нет ни одной записи в journald
    run
    [[ $rc == 2 ]] || fail "пустой journald сервиса: код $rc вместо 2"

    setup
    : > "$work/svc.json" # в основной выборке у сервиса нет ни одной записи
    SINCE=2026-10-25 run
    [[ $rc == 2 ]] || fail "сутки без записей сервиса: код $rc вместо 2"
    grep -q "2026-10-25 .*НЕ СОБРАНО(у сервиса нет записей за сутки)" <<< "$output" || fail "частичные сутки без записей не помечены: $output"

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
