#!/bin/sh
# Сверка недели наблюдения ozon-fetcher (ADR-043 п. 5, этап 8
# docs/plan/ozon-fetcher.md) по каждому подключению из allowlist.
# Только чтение: ни ключей, ни тел, ни сумм — счётчики, даты и пути методов.
#
#   ssh conwix-prod 'cd /opt/conwix && sh -s' < bin/ozon-fetcher-observe.sh
#
# SINCE — первый день (по умолчанию 7 суток назад, но не раньше суток
# перевода журналов в journald — JOURNALD_START ниже), UNTIL — последний
# (по умолчанию сегодня). Сутки — UTC, границы журнала тоже.
#
# Что печатает, по дням и отдельно по Seller и Performance:
# - ответы 429 Ozon: PHP — `warning` клиентов (ADR-028, этап 4), сервис —
#   запись каждого запроса (не исход попытки: 429 заказа отчёта рекламы
#   попытку не завершает); критерий — сумма за сутки не больше
#   BASELINE + 3 (база 0 — решение владельца 25.10.2026: базы за неделю
#   до включения нет, журнал json-file стирался выкладкой);
# - ответы 403: признак блокировки по Client ID решает агент по списку
#   (метод, по которому шла загрузка, без смены ключей — ADR-043 п. 5);
# - остановки сервиса и паузы ресурсов, исходы auth/invalid_* попыток;
# - отметки загрузки дня PHP по типам отчётов;
# - покрытие: день, загруженный PHP, сервис хоть раз загрузил успешно.
#
# Performance PHP пишет рекламный client_id, а не подключение: связать его
# с подключением можно только через зашифрованные учётные данные. Поэтому
# 429 и 403 Performance PHP считаются по всем кабинетам — строже критерия.
#
# Компания подключения ищется по его идентификатору из allowlist, без
# известной заранее компании: это операционная задача (CLAUDE.md §1,
# первый случай исключения) — сверку запускает агент из консоли, а
# результатом чтения является сам company_id. Дальше все чтения —
# по компании и подключению.
#
# Код выхода: 0 — критерии 429 выполнены, 403 нет, покрытие полное;
# 1 — нарушение или 403 (разобрать); 2 — данные не собраны или неполны
# (сутки до перевода журналов в journald, журнал без записей при попытках
# сервиса, пустое окно, сбой чтения) — это не «всё хорошо». Сутки перевода
# журналов (JOURNALD_START) помечаются «частично» и кода 2 не дают.

set -eu

DOTENV=${DOTENV:-.env}
C=${COMPOSE:-docker compose -f docker-compose.prod.yml}
J=${JOURNALCTL:-journalctl}
D=${DOCKER:-docker}
BASELINE=${BASELINE:-0}
UNTIL=${UNTIL:-$(date -u +%F)}
# Контейнеры проекта: долгоживущие (-1) и разовые `docker compose run`
# (-run-<hash>) — ручной запуск консоли, ходящий в Ozon, тоже в счёте.
# Вывод `exec` в журнал контейнера не попадает: на время наблюдения
# команды, ходящие в Ozon, — через `run` (operations-checklist.md).
PHP_CONTAINERS='api worker-ingestion worker-backfill worker-calc worker-scheduler worker-freshness'
PHP_NAME_RE='^conwix-(api|worker-[a-z]+)-(1|run-[0-9a-f]+)$'
SERVICE_CONTAINER=conwix-ozon-fetcher-1
SERVICE_NAME_RE='^conwix-ozon-fetcher-(1|run-[0-9a-f]+)$'
# Пары «тип отчёта PHP — ресурс сервиса» для покрытия; дни не свежее
# COVERAGE_LAG суток — свежие догружает текущий тик обеих сторон.
COVERAGE_PAIRS='ozon_posting_fbo_list:ozon.orders.fbo ozon_accrual_by_day:ozon.finance.accrual-by-day ozon_ad_expense:ozon.ads.expense ozon_ad_daily:ozon.ads.daily'
COVERAGE_LAG=2
COVERAGE_DAYS=120
ROWS_LIMIT=2000

# Любой выход, кроме явного итога и die, — код 2: под set -e упавшая
# команда вернула бы свой код, и 1 читался бы как «нарушение».
finished=
trap 'rc=$?; if [ -z "$finished" ] && [ "$rc" -ne 2 ]; then echo "ozon-fetcher-observe: непредвиденный сбой (код $rc)" >&2; exit 2; fi' EXIT

die() {
    echo "ozon-fetcher-observe: $*" >&2
    exit 2
}

command -v jq > /dev/null || die "нужен jq"
command -v python3 > /dev/null || die "нужен python3"
# JOURNALD_START — сутки UTC первой записи сервиса в journald: с неё журналы
# собираются (сервис пишет каждый запрос; PHP-контейнеры пересоздаёт та же
# выкладка, что перевела журналы, — docker-compose.prod.yml,
# x-journald-logging). Эти сутки собраны частично: до выкладки записи были
# в json-file и стёрты пересозданием; раньше них записей нет вовсе.
# Выводится из факта, а не из даты выкладки; переменная окружения — только
# переопределение.
if [ -z "${JOURNALD_START:-}" ]; then
    # Тот же селектор, что у счёта ниже (_SYSTEMD_UNIT), — иначе граница
    # нашлась бы там, откуда счёт не читает.
    first=$($J -o json --output-fields=__REALTIME_TIMESTAMP _SYSTEMD_UNIT=docker.service "CONTAINER_NAME=$SERVICE_CONTAINER" | head -n 1) ||
        die "journald сервиса не прочитан"
    [ -n "$first" ] || die "у $SERVICE_CONTAINER нет записей в journald — журнал не собран"
    JOURNALD_START=$(printf '%s' "$first" | jq -r '.__REALTIME_TIMESTAMP|tonumber/1000000|strftime("%Y-%m-%d")') ||
        die "первая запись journald не разобрана"
fi
# По умолчанию — последние 7 суток, но не раньше JOURNALD_START: сутки
# раньше заведомо не собраны, и каждый запуск недели давал бы за них 2.
# Сами сутки JOURNALD_START читаются: 403 за собранную их часть — признак.
if [ -z "${SINCE:-}" ]; then
    SINCE=$(date -u -d '-7 days' +%F)
    [ "$(echo "$SINCE" | tr -d -)" -ge "$(echo "$JOURNALD_START" | tr -d -)" ] || SINCE=$JOURNALD_START
fi
for d in "$SINCE" "$UNTIL" "$JOURNALD_START"; do
    printf '%s' "$d" | grep -Eqx '[0-9]{4}-[0-9]{2}-[0-9]{2}' || die "даты — YYYY-MM-DD"
done
# Пустое окно проверило бы ноль суток и сообщило «всё хорошо».
[ "$(echo "$SINCE" | tr -d -)" -le "$(echo "$UNTIL" | tr -d -)" ] || die "SINCE ($SINCE) позже UNTIL ($UNTIL) — проверять нечего"
allowlist=$(sed -n 's/^OZON_FETCHER_CONNECTION_ALLOWLIST=//p' "$DOTENV" | tail -n 1 | tr -d '"'"'"' ')
[ -n "$allowlist" ] || die "OZON_FETCHER_CONNECTION_ALLOWLIST пуст — наблюдать нечего"

days=$(python3 -c 'import sys,datetime as d
a,b=(d.date.fromisoformat(x) for x in sys.argv[1:3])
while a<=b: print(a); a+=d.timedelta(1)' "$SINCE" "$UNTIL") || die "даты не разобраны"
after_until=$(python3 -c 'import sys,datetime as d; print(d.date.fromisoformat(sys.argv[1])+d.timedelta(1))' "$UNTIL")

# psql в контейнере postgres: БД Conwix или сервиса, SQL — из stdin,
# переменные — через -v (UUID проверены ниже).
psql_app() {
    $C exec -T postgres sh -c 'psql -X -q -At -F"|" -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" "$@"' sh "$@"
}
psql_svc() {
    $C exec -T postgres sh -c 'psql -X -q -At -F"|" -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d ozon_fetcher "$@"' sh "$@"
}

# Записи контейнеров (драйвер journald — dockerd) за период как JSON:
# {"day":"YYYY-MM-DD","c":<контейнер>,"m":<сообщение>}. Не-JSON строки
# пропускаются. Сбой journalctl — отказ сверки, а не нулевой счёт; чтение
# и разбор — два шага: в конвейере код выхода был бы у jq.
raw=$($J -o json --since "$SINCE 00:00:00 UTC" --until "$after_until 00:00:00 UTC" _SYSTEMD_UNIT=docker.service) ||
    die "journald не прочитан"
journal=$(printf '%s\n' "$raw" | jq -c 'select((.MESSAGE|type=="string") and (.CONTAINER_NAME|type=="string")) | {day: (.__REALTIME_TIMESTAMP|tonumber/1000000|strftime("%Y-%m-%d")), c: .CONTAINER_NAME, m: (.MESSAGE|fromjson? // empty)}')
unset raw

# Счёт по журналу верен, только если журнал контейнера в journald:
# json-file стирается выкладкой.
for svc in $PHP_CONTAINERS; do
    names="${names:-} conwix-$svc-1"
done
for name in $names $SERVICE_CONTAINER; do
    driver=$($D inspect -f '{{.HostConfig.LogConfig.Type}}' "$name" 2> /dev/null) ||
        die "контейнера $name нет — счёт по журналу неполный"
    [ "$driver" = journald ] || die "журнал $name — $driver, не journald: счёт по журналу неполный"
done

php_log=$(printf '%s\n' "$journal" | jq -c --arg re "$PHP_NAME_RE" 'select((.c|test($re)) and (.m.message|type=="string") and (.m.message|test("^Ozon (Seller|Performance) API answered"))) | {day, api: .m.context.api, status: .m.context.status, method: .m.context.method, path: .m.context.path, client: (.m.context.client_id|tostring)}')
svc_log=$(printf '%s\n' "$journal" | jq -c --arg re "$SERVICE_NAME_RE" 'select((.c|test($re)) and (.m.msg=="ozon request" or .m.msg=="ozon performance request")) | {day, api: (if .m.msg=="ozon request" then "seller" else "performance" end), status: .m.status, account: .m.marketplace_account_id, endpoint: .m.endpoint}')
# Сутки, в которые у сервиса есть хоть одна запись: сервис пишет и без
# запросов к Ozon (опрос подключений, планировщик), так что сутки без
# единой записи — сломанный сбор, а не тишина.
svc_alive=$(printf '%s\n' "$journal" | jq -r --arg re "$SERVICE_NAME_RE" 'select(.c|test($re)) | .day' | sort -u)
unset journal

verdict=0
incomplete=0
echo "Наблюдение ozon-fetcher: $SINCE..$UNTIL (сутки UTC; журнал собран с $JOURNALD_START, эти сутки — частично), критерий 429: сумма PHP+сервис по API за сутки <= $((BASELINE + 3))"

for account in $(printf '%s' "$allowlist" | tr ',' ' '); do
    printf '%s' "$account" | grep -Eqx '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}' ||
        die "не UUID в allowlist"
    # Операционное межарендаторное чтение (§1, см. заголовок): результат —
    # company_id подключения.
    row=$(echo "SELECT company_id, external_shop_id, updated_at FROM marketplace_account WHERE id = :'acc' AND marketplace = 'ozon';" |
        psql_app -v acc="$account") || die "БД Conwix не прочитана"
    [ -n "$row" ] || { echo; echo "== $account: подключения Ozon нет в БД Conwix"; verdict=1; continue; }
    company=${row%%|*}
    rest=${row#*|}
    shop=${rest%%|*}
    echo
    echo "== Подключение $account (компания $company, Client-Id $shop, ключи обновлены ${rest#*|})"

    attempts=$(psql_svc -v co="$company" -v acc="$account" -v since="$SINCE" -v after="$after_until" <<'SQL'
SELECT (started_at AT TIME ZONE 'UTC')::date, count(*)
FROM fetch_attempt
WHERE company_id = :'co' AND marketplace_account_id = :'acc'
  AND started_at >= :'since'::date AND started_at < :'after'::date
GROUP BY 1 ORDER BY 1;
SQL
) || die "БД сервиса не прочитана"

    echo
    echo "-- 429 по суткам (Performance PHP — все кабинеты; svc.req — запросы сервиса в журнале, svc.att — попытки в БД)"
    printf '%-10s  %10s %10s %6s  %10s %10s %6s  %8s %8s\n' day seller.php seller.svc sum perf.php perf.svc sum svc.req svc.att
    for day in $days; do
        sp=$(printf '%s\n' "$php_log" | jq -s --arg d "$day" --arg c "$shop" '[.[]|select(.day==$d and .api=="seller" and .status==429 and .client==$c)]|length')
        pp=$(printf '%s\n' "$php_log" | jq -s --arg d "$day" '[.[]|select(.day==$d and .api=="performance" and .status==429)]|length')
        ss=$(printf '%s\n' "$svc_log" | jq -s --arg d "$day" --arg a "$account" '[.[]|select(.day==$d and .api=="seller" and .status==429 and .account==$a)]|length')
        ps=$(printf '%s\n' "$svc_log" | jq -s --arg d "$day" --arg a "$account" '[.[]|select(.day==$d and .api=="performance" and .status==429 and .account==$a)]|length')
        sr=$(printf '%s\n' "$svc_log" | jq -s --arg d "$day" --arg a "$account" '[.[]|select(.day==$d and .account==$a)]|length')
        sa=$(printf '%s\n' "$attempts" | sed -n "s/^$day|//p")
        sa=${sa:-0}
        mark=
        if [ "$(echo "$day" | tr -d -)" -lt "$(echo "$JOURNALD_START" | tr -d -)" ]; then
            mark=" НЕ СОБРАНО(журнал до journald)"
            incomplete=1
        elif ! printf '%s\n' "$svc_alive" | grep -qx "$day"; then
            mark=" НЕ СОБРАНО(у сервиса нет записей за сутки)"
            incomplete=1
        elif [ "$day" = "$JOURNALD_START" ]; then
            # Счёт — нижняя граница: превышение порога и 403 — настоящие,
            # отсутствие их — не доказательство. Код 2 не ставится: иначе
            # им кончался бы каждый запуск недели.
            mark=" ЧАСТИЧНО(журнал с выкладки)"
        elif [ "$sa" -gt 0 ] && [ "$sr" -eq 0 ]; then
            mark=" НЕ СОБРАНО(попытки есть, журнала нет)"
            incomplete=1
        fi
        if [ $((sp + ss)) -gt $((BASELINE + 3)) ]; then mark="$mark НАРУШЕН(seller)"; verdict=1; fi
        if [ $((pp + ps)) -gt $((BASELINE + 3)) ]; then mark="$mark НАРУШЕН(performance)"; verdict=1; fi
        printf '%-10s  %10s %10s %6s  %10s %10s %6s  %8s %8s%s\n' "$day" "$sp" "$ss" $((sp + ss)) "$pp" "$ps" $((pp + ps)) "$sr" "$sa" "$mark"
    done

    echo
    echo "-- 403 Ozon (признак блокировки — 403 на методе, по которому шла загрузка, без смены ключей)"
    f403=$(printf '%s\n' "$php_log" | jq -r --arg c "$shop" 'select(.status==403 and (.api=="performance" or .client==$c)) | "PHP \(.day) \(.api) \(.method) \(.path) client=\(.client)"')
    s403=$(printf '%s\n' "$svc_log" | jq -r --arg a "$account" 'select(.status==403 and .account==$a) | "svc \(.day) \(.api) \(.endpoint)"')
    if [ -z "$f403$s403" ]; then
        echo "нет"
    else
        printf '%s\n%s\n' "$f403" "$s403" | grep . | sort | uniq -c
        verdict=1
    fi

    echo
    echo "-- Сервис: остановки, паузы, исходы попыток auth/invalid_* по суткам"
    psql_svc -v co="$company" -v acc="$account" -v since="$SINCE" -v after="$after_until" <<'SQL' || die "БД сервиса не прочитана"
SELECT 'service_halt: ' || count(*) FROM service_halt;
SELECT 'resource_pause: ' || resource_type || ' ' || reason
FROM resource_pause WHERE company_id = :'co' AND marketplace_account_id = :'acc';
SELECT 'connection_state: suspended_until=' || coalesce(suspended_until::text, '-') || ' reason=' || coalesce(suspend_reason, '-')
FROM connection_state WHERE company_id = :'co' AND marketplace_account_id = :'acc';
SELECT day || ' ' || outcome || ' ' || n
FROM (SELECT (started_at AT TIME ZONE 'UTC')::date AS day, outcome, count(*) AS n
      FROM fetch_attempt
      WHERE company_id = :'co' AND marketplace_account_id = :'acc'
        AND started_at >= :'since'::date AND started_at < :'after'::date
        AND (outcome = 'auth' OR outcome LIKE 'invalid%')
      GROUP BY 1, 2) a
ORDER BY day, outcome;
SELECT 'исходы попыток: ' || coalesce(string_agg(outcome || '=' || n, ', ' ORDER BY outcome), 'нет')
FROM (SELECT coalesce(outcome, 'running') AS outcome, count(*) AS n FROM fetch_attempt
      WHERE company_id = :'co' AND marketplace_account_id = :'acc'
        AND started_at >= :'since'::date AND started_at < :'after'::date
      GROUP BY 1) o;
SQL

    echo
    echo "-- Отметки загрузки дня PHP по дню установки (неделя до наблюдения и неделя наблюдения)"
    psql_app -v co="$company" -v acc="$account" -v since="$SINCE" -v after="$after_until" <<'SQL' || die "БД Conwix не прочитана"
SELECT completed_at::date || ' ' || string_agg(report_type || '=' || n, ' ' ORDER BY report_type)
FROM (SELECT completed_at::date AS completed_at, report_type, count(*) AS n
      FROM marketplace_day_load
      WHERE company_id = :'co' AND marketplace_account_id = :'acc'
        AND completed_at >= :'since'::date - 7 AND completed_at < :'after'::date
      GROUP BY 1, 2) d
GROUP BY completed_at ORDER BY completed_at;
SQL

    echo
    echo "-- Покрытие: дни, загруженные PHP, которые сервис ни разу не загрузил успешно"
    php_types=$(for p in $COVERAGE_PAIRS; do printf '%s,' "${p%%:*}"; done)
    svc_types=$(for p in $COVERAGE_PAIRS; do printf '%s,' "${p#*:}"; done)
    # Окно сервиса — все его задачи ресурса (любой статус): задача есть,
    # значит день сервис обязан загрузить; успех — last_success_at.
    svc_rows=$(psql_svc -v co="$company" -v acc="$account" -v types="${svc_types%,}" -v days="$COVERAGE_DAYS" -v lim="$ROWS_LIMIT" <<'SQL'
SELECT resource_type, period_key, (last_success_at IS NOT NULL)
FROM fetch_task
WHERE company_id = :'co' AND marketplace_account_id = :'acc'
  AND resource_type = ANY (string_to_array(:'types', ','))
  AND period_key >= to_char(current_date - :'days'::int, 'YYYY-MM-DD')
ORDER BY 1, 2
LIMIT :'lim';
SQL
) || die "БД сервиса не прочитана"
    php_rows=$(psql_app -v co="$company" -v acc="$account" -v types="${php_types%,}" -v days="$COVERAGE_DAYS" -v lag="$COVERAGE_LAG" -v lim="$ROWS_LIMIT" <<'SQL'
SELECT report_type, period
FROM marketplace_day_load
WHERE company_id = :'co' AND marketplace_account_id = :'acc'
  AND report_type = ANY (string_to_array(:'types', ','))
  AND completed_at IS NOT NULL
  AND period BETWEEN current_date - :'days'::int AND current_date - :'lag'::int
ORDER BY 1, 2
LIMIT :'lim';
SQL
) || die "БД Conwix не прочитана"
    for rows in "$svc_rows" "$php_rows"; do
        [ "$(printf '%s\n' "$rows" | grep -c .)" -lt "$ROWS_LIMIT" ] || die "покрытие: строк не меньше $ROWS_LIMIT — окно не прочитано целиком"
    done
    coverage=$(printf '%s\n===\n%s\n' "$svc_rows" "$php_rows" | python3 -c '
import sys
pairs = [p.split(":") for p in sys.argv[1].split()]
svc_part, php_part = sys.stdin.read().split("===\n")
tasks, ok = {}, {}
for line in svc_part.splitlines():
    if line:
        rt, day, success = line.split("|")
        tasks.setdefault(rt, set()).add(day)
        if success == "t":
            ok.setdefault(rt, set()).add(day)
php = {}
for line in php_part.splitlines():
    if line:
        rt, day = line.split("|")
        php.setdefault(rt, set()).add(day)
bad = False
for php_type, svc_type in pairs:
    window = tasks.get(svc_type, set())
    if not window:
        print(f"{svc_type}: у сервиса нет задач ресурса"); bad = True; continue
    first, last = min(window), max(window)
    loaded = sorted(d for d in php.get(php_type, set()) if first <= d <= last)
    missing = [d for d in loaded if d not in ok.get(svc_type, set())]
    if missing:
        gaps = " ".join(missing)
        print(f"{svc_type}: сервис не загрузил: {gaps}"); bad = True
    else:
        print(f"{svc_type}: {first}..{last} — все {len(loaded)} дн. PHP загружены сервисом")
# 10, а не 1: код 1 — у упавшего интерпретатора, это сбой, не нарушение.
sys.exit(10 if bad else 0)
' "$COVERAGE_PAIRS") && rc=0 || rc=$?
    printf '%s\n' "$coverage"
    case $rc in
        0) ;;
        10) verdict=1 ;;
        *) die "покрытие не посчитано" ;;
    esac
done

echo
finished=1
# Нарушение важнее неполноты: 403 за собранные сутки требует реакции,
# даже если другие сутки не собраны.
if [ "$verdict" = 1 ]; then
    [ "$incomplete" = 1 ] && echo "ИТОГ: есть нарушения или 403 — разобрать (выше); часть суток не собрана"
    [ "$incomplete" = 0 ] && echo "ИТОГ: есть нарушения или 403 — разобрать (выше)"
    exit 1
fi
if [ "$incomplete" = 1 ]; then
    echo "ИТОГ: нарушений в собранном нет, но часть суток не собрана — критерий по ним не проверен"
    exit 2
fi
echo "ИТОГ: критерии 429 выполнены, 403 нет, покрытие полное"
exit 0
