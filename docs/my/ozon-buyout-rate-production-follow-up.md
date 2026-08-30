# Production-проверка hotfix процента выкупа Ozon

Дата фиксации инструкции: **2026-08-30**.

## Какую задачу продолжать

> Завершить rollout hotfix SQL N+1 в `buyout_outcome`: применить
> `Version20260901100000`, повторить остановленную выкладку и проверить
> list/daily API и план PostgreSQL. Реализацию процента выкупа заново не
> делать; базовая функциональность уже смержена PR #117.

Production доступен только через SSH alias `conwix-prod`:

```bash
ssh -F /home/deploy/.ssh/config -o BatchMode=yes conwix-prod \
  'hostname && date -u'
```

Ключ через `-i` не указывать: путь и параметры доступа принадлежат alias.

## Причина hotfix

Frontend не создаёт повторов (`retry: false`), а application action выполняет
фиксированные два data SELECT, либо три для пустого summary fallback.
Production `EXPLAIN` показал SQL N+1 внутри view: коррелированный aggregate
`sales_evidence` повторялся примерно 4 138 раз, стоимость плана была около
217 млн, а запросы занимали 3–4,5 минуты.

Миграция заменяет коррелированные scans оконными агрегатами, сохраняя колонки
view и классификацию T1/D/T2/P/R. Новое приложение внутри read-only транзакции
отчёта отключает JIT и nested-loop plans, чтобы batch import не возвращал SQL
N+1 в коротком окне до PostgreSQL auto-analyze, и ограничивает каждый SQL
`statement_timeout = 5s`. Миграция использует transaction-local
`lock_timeout = 5s`, поэтому не будет ждать старый запрос и блокировать новые
чтения view без ограничения.

## 1. Проверка перед миграцией

После merge deploy ожидаемо остановится на migration gate. Новый образ уже
должен быть записан в `/opt/conwix/.env`.

```bash
ssh -F /home/deploy/.ssh/config -o BatchMode=yes conwix-prod 'bash -s' <<'REMOTE'
set -euo pipefail
cd /opt/conwix
C=docker-compose.prod.yml

docker compose -f "$C" ps --format '{{.Service}} | {{.Image}} | {{.Status}}'
docker compose -f "$C" run --rm --no-deps -T api \
  php bin/console doctrine:migrations:status
docker compose -f "$C" run --rm --no-deps -T api \
  php bin/console doctrine:migrations:list
docker compose -f "$C" run --rm --no-deps -T api \
  php bin/console doctrine:migrations:migrate --dry-run --no-interaction
REMOTE
```

Продолжать только если единственная pending migration —
`DoctrineMigrations\\Version20260901100000`, а dry-run содержит только
`SET LOCAL lock_timeout = '5s'` и `CREATE OR REPLACE VIEW buyout_outcome`.

Перед DDL найти старые активные запросы к view. Сначала только посмотреть:

```bash
ssh -F /home/deploy/.ssh/config -o BatchMode=yes conwix-prod 'bash -s' <<'REMOTE'
set -euo pipefail
cd /opt/conwix
C=docker-compose.prod.yml

docker compose -f "$C" run --rm --no-deps -T api \
  php bin/console dbal:run-sql \
  "SELECT pid, application_name, now() - query_start AS age, wait_event_type, wait_event
     FROM pg_stat_activity
    WHERE datname = current_database()
      AND pid <> pg_backend_pid()
      AND state = 'active'
      AND query ILIKE '%buyout_outcome%'
    ORDER BY query_start"
REMOTE
```

Если есть buyout-запросы старше пяти секунд, отменить только их и повторить
предыдущий read-only запрос. Не завершать backend принудительно:

```bash
ssh -F /home/deploy/.ssh/config -o BatchMode=yes conwix-prod 'bash -s' <<'REMOTE'
set -euo pipefail
cd /opt/conwix
C=docker-compose.prod.yml

docker compose -f "$C" run --rm --no-deps -T api \
  php bin/console dbal:run-sql \
  "SELECT pid, pg_cancel_backend(pid) AS cancelled
     FROM pg_stat_activity
    WHERE datname = current_database()
      AND pid <> pg_backend_pid()
      AND state = 'active'
      AND now() - query_start > interval '5 seconds'
      AND query ILIKE '%buyout_outcome%'"
REMOTE
```

## 2. Применение и проверка схемы

```bash
ssh -F /home/deploy/.ssh/config -o BatchMode=yes conwix-prod 'bash -s' <<'REMOTE'
set -euo pipefail
cd /opt/conwix
C=docker-compose.prod.yml

docker compose -f "$C" run --rm --no-deps -T api \
  php bin/console doctrine:migrations:migrate \
  --no-interaction --allow-no-migration
docker compose -f "$C" run --rm --no-deps -T api \
  php bin/console doctrine:migrations:up-to-date
docker compose -f "$C" run --rm --no-deps -T api \
  php bin/console doctrine:schema:validate
docker compose -f "$C" run --rm --no-deps -T api \
  php bin/console dbal:run-sql \
  "SELECT to_regclass('public.buyout_outcome') AS outcome_view"
REMOTE
```

Ожидание: миграция применена, схема валидна, `outcome_view` не `NULL`.
Если миграция получила `lock timeout`, это безопасный останов без изменения
версии: снова выполнить поиск блокеров из раздела 1, дождаться их исчезновения
и повторить migration. Не запускать несколько migration-процессов параллельно.

## 3. Повтор deploy

Номер остановленного run получить после merge:

```bash
gh run list --branch master --limit 5
gh run rerun RUN_ID --failed
gh run watch RUN_ID --exit-status
```

После зелёного deploy:

```bash
curl -fsS https://app.conwix.com/api/seller/ping

ssh -F /home/deploy/.ssh/config -o BatchMode=yes conwix-prod 'bash -s' <<'REMOTE'
set -euo pipefail
cd /opt/conwix
C=docker-compose.prod.yml

docker compose -f "$C" ps --format '{{.Service}} | {{.Image}} | {{.Status}}'
docker compose -f "$C" exec -T api \
  php bin/console debug:router ingestion_buyout_rate_list
docker compose -f "$C" exec -T api \
  php bin/console debug:router ingestion_buyout_rate_daily
docker compose -f "$C" logs --since=15m --no-color api nginx \
  | grep -E -i 'statement timeout|exception|buyout-rate| 5[0-9][0-9] ' || true
REMOTE
```

## 4. Проверка данных и производительности

Подставить компанию и account из инцидента:

```bash
COMPANY_ID='019fe6ea-cd6a-7c81-a869-883a0a562b47'
ACCOUNT_ID='<marketplace account UUID>'

ssh -F /home/deploy/.ssh/config -o BatchMode=yes conwix-prod \
  "cd /opt/conwix && docker compose -f docker-compose.prod.yml exec -T api \
   php bin/console dbal:run-sql \
   \"SELECT
        (SELECT COALESCE(SUM(quantity), 0) FROM sales_fact
          WHERE company_id = '$COMPANY_ID' AND marketplace_account_id = '$ACCOUNT_ID') AS sales_quantity,
        (SELECT COALESCE(SUM(quantity), 0) FROM buyout_outcome
          WHERE company_id = '$COMPANY_ID' AND marketplace_account_id = '$ACCOUNT_ID') AS outcome_quantity\""
```

Ожидание: `sales_quantity = outcome_quantity`. Затем открыть экран выкупа с
действующей сессией и проверить:

- первый list-запрос сразу после batch import и ещё четыре последовательных
  запроса возвращают HTTP 200, каждый быстрее пяти секунд;
- daily-запрос выбранного SKU возвращает HTTP 200;
- данные не меняются относительно прежней классификации;
- после проверки в `pg_stat_activity` нет buyout-запросов старше пяти секунд;
- в свежих логах нет `statement timeout` и HTTP 500.

Дополнительные запросы к Ozon API и backfill для hotfix не нужны.

## Откат

Предыдущий image приложения совместим с новым view: контракт колонок не
изменился. При откате application image **не выполнять `down()`** — оставить
оптимизированный view и только повторить list/daily smoke test.

`down()` нужен исключительно если обнаружена ошибка семантики данных в новом
view. В этом случае оставить текущий image с пятисекундным timeout и planner
guards, отменить старые buyout-запросы по процедуре раздела 1, затем выполнить:

```bash
ssh -F /home/deploy/.ssh/config -o BatchMode=yes conwix-prod \
  "cd /opt/conwix && docker compose -f docker-compose.prod.yml run --rm --no-deps -T api \
   php bin/console doctrine:migrations:execute \
   'DoctrineMigrations\\Version20260901100000' --down --no-interaction"
```

Старый view снова может быть медленным и давать timeout; это аварийная
деградация доступности до следующего исправления, а не штатный способ отката
приложения.
