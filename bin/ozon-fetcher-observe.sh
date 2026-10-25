#!/bin/sh
# Сверка недели наблюдения ozon-fetcher (ADR-043 п. 5, этап 8
# docs/plan/ozon-fetcher.md) по каждому подключению из allowlist.
# Только чтение: ни ключей, ни тел, ни сумм — счётчики, даты и пути методов.
#
#   ssh conwix-prod 'cd /opt/conwix && SINCE=2026-10-25 sh -s' < bin/ozon-fetcher-observe.sh
#
# SINCE — первый день наблюдения (UTC, по умолчанию 7 дней назад),
# UNTIL — последний (по умолчанию сегодня). Сутки — UTC.
#
# Что печатает, по дням и отдельно по Seller и Performance:
# - ответы 429 Ozon: PHP — `warning` клиентов (ADR-028, этап 4), сервис —
#   запись каждого запроса; критерий — сумма за сутки не больше
#   BASELINE + 3 (база 0 — решение владельца 25.10.2026: базы за неделю
#   до включения нет, журнал json-file стирался выкладкой);
# - ответы 403: признак блокировки по Client ID решает агент по списку
#   (метод, по которому шла загрузка, без смены ключей — ADR-043 п. 5);
# - остановки сервиса и паузы ресурсов, исходы auth/invalid_* попыток;
# - отметки загрузки дня PHP по типам отчётов;
# - покрытие: день, загруженный PHP, есть в журнале сервиса.
#
# Performance PHP пишет рекламный client_id, а не подключение: связать его
# с подключением можно только через зашифрованные учётные данные. Поэтому
# 429 и 403 Performance PHP считаются по всем кабинетам — строже критерия.
#
# Код выхода: 0 — критерии 429 выполнены и 403 нет, 1 — нарушение или
# 403 (разобрать), 2 — не удалось собрать данные.

set -eu

DOTENV=${DOTENV:-.env}
C=${COMPOSE:-docker compose -f docker-compose.prod.yml}
J=${JOURNALCTL:-journalctl}
BASELINE=${BASELINE:-0}
SINCE=${SINCE:-$(date -u -d '-7 days' +%F)}
UNTIL=${UNTIL:-$(date -u +%F)}
PHP_CONTAINERS='api worker-ingestion worker-backfill worker-calc worker-scheduler worker-freshness'
SERVICE_CONTAINER=conwix-ozon-fetcher-1

die() {
    echo "ozon-fetcher-observe: $*" >&2
    exit 2
}

command -v jq > /dev/null || die "нужен jq"
printf '%s\n%s\n' "$SINCE" "$UNTIL" | grep -Eqvx '[0-9]{4}-[0-9]{2}-[0-9]{2}' && die "SINCE/UNTIL — YYYY-MM-DD"
allowlist=$(sed -n 's/^OZON_FETCHER_CONNECTION_ALLOWLIST=//p' "$DOTENV" | tail -n 1 | tr -d '"'"'"' ')
[ -n "$allowlist" ] || die "OZON_FETCHER_CONNECTION_ALLOWLIST пуст — наблюдать нечего"

# psql в контейнере postgres: БД Conwix или сервиса, SQL — из stdin,
# переменные — через -v (значения проверены как UUID ниже).
psql_app() {
    $C exec -T postgres sh -c 'psql -X -q -At -F"|" -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" "$@"' sh "$@"
}
psql_svc() {
    $C exec -T postgres sh -c 'psql -X -q -At -F"|" -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d ozon_fetcher "$@"' sh "$@"
}

# Записи journald за период как JSON-строки сообщений с днём UTC:
# {"day":"YYYY-MM-DD","m":<сообщение>}. Не-JSON строки пропускаются.
# Сбой journalctl — отказ сверки, а не нулевой счёт.
journal() {
    matches=
    for name in "$@"; do
        matches="$matches CONTAINER_NAME=$name"
    done
    # shellcheck disable=SC2086 # matches — список полей journald
    raw=$($J -o json --since "$SINCE 00:00:00" --until "$UNTIL 23:59:59" $matches) || return 1
    [ -n "$raw" ] || return 0
    printf '%s\n' "$raw" |
        jq -c 'select(.MESSAGE|type=="string") | {day: (.__REALTIME_TIMESTAMP|tonumber/1000000|strftime("%Y-%m-%d")), m: (.MESSAGE|fromjson? // empty)}'
}

# Счёт по журналу верен, только если журнал контейнера в journald:
# json-file стирается выкладкой (docker-compose.prod.yml, x-journald-logging).
php_names=
for svc in $PHP_CONTAINERS; do
    php_names="$php_names conwix-$svc-1"
done
for name in $php_names $SERVICE_CONTAINER; do
    driver=$(${DOCKER:-docker} inspect -f '{{.HostConfig.LogConfig.Type}}' "$name" 2> /dev/null) ||
        die "контейнера $name нет — счёт по журналу неполный"
    [ "$driver" = journald ] || die "журнал $name — $driver, не journald: счёт по журналу неполный"
done

# Чтение журнала и разбор — два шага: в конвейере код выхода был бы
# у jq, и сбой journalctl превратился бы в нулевой счёт.
# shellcheck disable=SC2086
php_journal=$(journal $php_names) || die "journald PHP не прочитан"
svc_journal=$(journal "$SERVICE_CONTAINER") || die "journald сервиса не прочитан"
php_log=$(printf '%s\n' "$php_journal" | jq -c 'select(.m.message|type=="string" and test("^Ozon (Seller|Performance) API answered")) | {day, api: .m.context.api, status: .m.context.status, method: .m.context.method, path: .m.context.path, client: (.m.context.client_id|tostring)}')
svc_log=$(printf '%s\n' "$svc_journal" | jq -c 'select(.m.msg=="ozon request" or .m.msg=="ozon performance request") | {day, api: (if .m.msg=="ozon request" then "seller" else "performance" end), status: .m.status, account: .m.marketplace_account_id, endpoint: .m.endpoint}')

days=$(python3 -c 'import sys,datetime as d
a,b=(d.date.fromisoformat(x) for x in sys.argv[1:3])
while a<=b: print(a); a+=d.timedelta(1)' "$SINCE" "$UNTIL")

verdict=0
echo "Наблюдение ozon-fetcher: $SINCE..$UNTIL (сутки UTC), критерий 429: сумма PHP+сервис по API за сутки <= $((BASELINE + 3))"

for account in $(printf '%s' "$allowlist" | tr ',' ' '); do
    printf '%s' "$account" | grep -Eqx '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}' ||
        die "не UUID в allowlist"
    row=$(echo "SELECT company_id, external_shop_id, updated_at FROM marketplace_account WHERE id = :'acc' AND marketplace = 'ozon';" |
        psql_app -v acc="$account") || die "БД Conwix не прочитана"
    [ -n "$row" ] || { echo; echo "== $account: подключения Ozon нет в БД Conwix"; verdict=1; continue; }
    company=${row%%|*}
    rest=${row#*|}
    shop=${rest%%|*}
    echo
    echo "== Подключение $account (компания $company, Client-Id $shop, ключи обновлены $(echo "$rest" | cut -d'|' -f2))"

    echo
    echo "-- 429 по суткам (Performance PHP — все кабинеты; svc.req — все запросы сервиса: 0 при включённом сервисе — журнал не собран)"
    printf '%-10s  %10s %10s %6s  %10s %10s %6s  %8s\n' day seller.php seller.svc sum perf.php perf.svc sum svc.req
    for day in $days; do
        sp=$(printf '%s\n' "$php_log" | jq -s --arg d "$day" --arg c "$shop" '[.[]|select(.day==$d and .api=="seller" and .status==429 and .client==$c)]|length')
        pp=$(printf '%s\n' "$php_log" | jq -s --arg d "$day" '[.[]|select(.day==$d and .api=="performance" and .status==429)]|length')
        ss=$(printf '%s\n' "$svc_log" | jq -s --arg d "$day" --arg a "$account" '[.[]|select(.day==$d and .api=="seller" and .status==429 and .account==$a)]|length')
        ps=$(printf '%s\n' "$svc_log" | jq -s --arg d "$day" --arg a "$account" '[.[]|select(.day==$d and .api=="performance" and .status==429 and .account==$a)]|length')
        sr=$(printf '%s\n' "$svc_log" | jq -s --arg d "$day" --arg a "$account" '[.[]|select(.day==$d and .account==$a)]|length')
        mark=
        if [ $((sp + ss)) -gt $((BASELINE + 3)) ]; then mark=" НАРУШЕН(seller)"; verdict=1; fi
        if [ $((pp + ps)) -gt $((BASELINE + 3)) ]; then mark="$mark НАРУШЕН(performance)"; verdict=1; fi
        printf '%-10s  %10s %10s %6s  %10s %10s %6s  %8s%s\n' "$day" "$sp" "$ss" $((sp + ss)) "$pp" "$ps" $((pp + ps)) "$sr" "$mark"
    done

    echo
    echo "-- 403 Ozon (признак блокировки — 403 на методе, по которому шла загрузка, без смены ключей)"
    f403=$(printf '%s\n' "$php_log" | jq -r --arg c "$shop" 'select(.status==403 and (.api=="performance" or .client==$c)) | "PHP \(.day) \(.api) \(.method) \(.path) client=\(.client)"')
    s403=$(printf '%s\n' "$svc_log" | jq -r --arg a "$account" 'select(.status==403 and .account==$a) | "svc \(.day) \(.api) \(.endpoint)"' | sort | uniq -c)
    if [ -z "$f403$s403" ]; then
        echo "нет"
    else
        [ -n "$f403" ] && printf '%s\n' "$f403" | sort | uniq -c
        [ -n "$s403" ] && printf '%s\n' "$s403"
        verdict=1
    fi

    echo
    echo "-- Сервис: остановки, паузы, исходы попыток auth/invalid_* по суткам"
    psql_svc -v co="$company" -v acc="$account" -v since="$SINCE" -v until="$UNTIL" <<'SQL' || die "БД сервиса не прочитана"
SELECT 'service_halt: ' || count(*) FROM service_halt;
SELECT 'resource_pause: ' || resource_type || ' ' || reason
FROM resource_pause WHERE company_id = :'co' AND marketplace_account_id = :'acc';
SELECT 'connection_state: suspended_until=' || coalesce(suspended_until::text, '-') || ' reason=' || coalesce(suspend_reason, '-')
FROM connection_state WHERE company_id = :'co' AND marketplace_account_id = :'acc';
SELECT day || ' ' || outcome || ' ' || n
FROM (SELECT (started_at AT TIME ZONE 'UTC')::date AS day, outcome, count(*) AS n
      FROM fetch_attempt
      WHERE company_id = :'co' AND marketplace_account_id = :'acc'
        AND started_at >= :'since'::date AND started_at < :'until'::date + 1
        AND (outcome = 'auth' OR outcome LIKE 'invalid%')
      GROUP BY 1, 2) a
ORDER BY day, outcome;
SELECT 'исходы попыток: ' || coalesce(string_agg(outcome || '=' || n, ', ' ORDER BY outcome), 'нет')
FROM (SELECT coalesce(outcome, 'running') AS outcome, count(*) AS n FROM fetch_attempt
      WHERE company_id = :'co' AND marketplace_account_id = :'acc'
        AND started_at >= :'since'::date AND started_at < :'until'::date + 1
      GROUP BY 1) o;
SQL

    echo
    echo "-- Отметки загрузки дня PHP по дню установки (неделя до наблюдения и неделя наблюдения)"
    psql_app -v co="$company" -v acc="$account" -v since="$SINCE" -v until="$UNTIL" <<'SQL' || die "БД Conwix не прочитана"
SELECT completed_at::date || ' ' || string_agg(report_type || '=' || n, ' ' ORDER BY report_type)
FROM (SELECT completed_at::date AS completed_at, report_type, count(*) AS n
      FROM marketplace_day_load
      WHERE company_id = :'co' AND marketplace_account_id = :'acc'
        AND completed_at >= :'since'::date - 7 AND completed_at < :'until'::date + 1
      GROUP BY 1, 2) d
GROUP BY completed_at ORDER BY completed_at;
SQL

    echo
    echo "-- Покрытие: дни, загруженные PHP, которых нет в журнале сервиса (в окне сервиса)"
    for pair in ozon_posting_fbo_list:ozon.orders.fbo ozon_accrual_by_day:ozon.finance.accrual-by-day \
        ozon_ad_expense:ozon.ads.expense ozon_ad_daily:ozon.ads.daily; do
        php_type=${pair%%:*}
        svc_type=${pair#*:}
        svc_days=$(echo "SELECT period_key FROM fetch_task WHERE company_id = :'co' AND marketplace_account_id = :'acc' AND resource_type = :'rt' AND status = 'done' ORDER BY 1;" |
            psql_svc -v co="$company" -v acc="$account" -v rt="$svc_type") || die "БД сервиса не прочитана"
        if [ -z "$svc_days" ]; then
            echo "$svc_type: в журнале сервиса нет ни одного дня"
            verdict=1
            continue
        fi
        first=$(printf '%s\n' "$svc_days" | head -n 1)
        last=$(printf '%s\n' "$svc_days" | tail -n 1)
        php_days=$(echo "SELECT period FROM marketplace_day_load WHERE company_id = :'co' AND marketplace_account_id = :'acc' AND report_type = :'rt' AND completed_at IS NOT NULL AND period BETWEEN :'first' AND :'last' ORDER BY 1;" |
            psql_app -v co="$company" -v acc="$account" -v rt="$php_type" -v first="$first" -v last="$last") || die "БД Conwix не прочитана"
        missing=$(printf '%s\n' "$php_days" | while read -r d; do
            [ -n "$d" ] && ! printf '%s\n' "$svc_days" | grep -qx "$d" && echo "$d"
        done || true)
        if [ -z "$missing" ]; then
            echo "$svc_type: $first..$last — все дни PHP есть ($(printf '%s\n' "$php_days" | grep -c . || true) дн.)"
        else
            echo "$svc_type: нет в сервисе: $(printf '%s' "$missing" | tr '\n' ' ')"
            verdict=1
        fi
    done
done

echo
if [ "$verdict" = 0 ]; then
    echo "ИТОГ: критерии 429 выполнены, 403 нет, покрытие полное"
else
    echo "ИТОГ: есть нарушения или 403 — разобрать (выше)"
fi
exit "$verdict"
