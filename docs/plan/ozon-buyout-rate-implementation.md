# Процент выкупа Ozon по SKU — подробный план реализации

> **Для agentic workers:** REQUIRED SUB-SKILL: использовать
> `superpowers:subagent-driven-development` (рекомендуется) или
> `superpowers:executing-plans`; выполнять план task-by-task. Шаги используют
> checkbox (`- [ ]`) для отслеживания. Пакеты нельзя объединять в один diff.

**Цель:** показать продавцу по каждому Ozon SKU фактический и прогнозный
процент выкупа, долю разрешившихся заказов и причины потерь T1/T2/P, сохранив
возможность пересчитать результат из неизменяемых raw-ответов.

**Исходная постановка:** `docs/task/ozon-buyout-rate-plan.md`.

**Фактическая спецификация источников:**
`docs/task/ozon-buyout-rate-research.md`; при расхождении предположений о JSON
с живым исследованием приоритет имеет research и следующий ADR-019.

**Архитектура:** новые данные остаются в модуле `Ingestion`. Ответы Ozon
сначала сохраняются в `marketplace_raw_document`, затем пакетными DBAL-запросами
попадают в историю статусов и факты возвратов. Итог T1/D/T2/P/R вычисляется
при чтении SQL-вьюхой; метрика, матурация и прогноз — DBAL query-классами.
HTTP-контракт отдаёт только готовые количества и basis points; React ничего
не пересчитывает, а только показывает данные.

**Стек:** PHP 8.4, Symfony 7.4, Doctrine DBAL/ORM metadata, PostgreSQL 16,
PHPUnit, React 19, TypeScript, TanStack Query, Recharts, Vitest, Playwright.

---

## Обязательные инварианты

- Порядок строго последовательный: 0 → ADR-019 → 1 → 2 → 3 → 4 → 5 → 6
  → 7A → 7B. Пакет 4 стартует только после завершения пакетов 2 и 3.
- Любое чтение и любой индекс tenant-данных начинается с `company_id`;
  контроллер не принимает company ID из тела запроса.
- Production raw хранит точные байты ответа. Живые исследовательские выгрузки
  содержат коммерческие данные и не коммитятся; для тестов создаются отдельные
  минимальные синтетические JSON с той же структурой.
- Факт-таблицы имеют Doctrine Entity для проверки схемы, но пишутся DBAL,
  пачками, без `persist()/flush()` и запросов на каждую строку.
- Повторная обработка держится на уникальном ключе/`ON CONFLICT`, а не на
  предварительном `SELECT`. Условие «значение изменилось» уменьшает историю,
  но само по себе не является защитой от гонки.
- Параллельные синхронизации одного дня сериализуются Symfony Lock. Ключ
  блокировки отгрузок содержит account ID **и business date**: блокировка
  только по кабинету потеряла бы остальные дни суточного 30-дневного рескана.
- Все агрегаты взвешены по `sales_fact.quantity`, не по числу строк.
- Проценты передаются целыми basis points: `8437` означает `84,37%`.
  При нулевом знаменателе API возвращает `null`, а не выдуманный `0%`.
- `R` означает `ClientReturn` после состоявшегося выкупа и исключается из
  знаменателя процента выкупа. `P` означает `Cancellation` конкретной SKU при
  вручении, когда sibling SKU того же order доставлена; P входит в знаменатель
  как невыкуп. Тип `PartialReturn` в живом срезе не встретился.
- Неизвестные status/substatus/return reason не относятся молча к T1/T2:
  outcome остаётся `NULL`, а диагностический запрос делает пробел видимым.
- Списки используют keyset pagination, `limit=50` по умолчанию, максимум
  `200`, выборку `limit + 1`; `COUNT(*)` по fact-таблицам не выполняется.
- Все календарные границы коннектора Ozon считаются в `Europe/Moscow`.
- Реальные Ozon-реквизиты не передаются агенту, не пишутся в файлы и не
  попадают в argv процесса. Живой сбор выполняет владелец ключа вручную.

Для каждой implementation-задачи ниже применяется один и тот же tracking
цикл; конкретные тестовые случаи, файлы и команды перечислены внутри задачи:

- [ ] Написать минимальный failing test на очередное поведение.
- [ ] Запустить только этот тест и увидеть ожидаемый RED по отсутствующей
  реализации, а не по ошибке fixture/окружения.
- [ ] Реализовать минимальный production-код для GREEN.
- [ ] Повторить целевой test, затем весь набор тестов пакета.
- [ ] Выполнить статические проверки, schema/OpenAPI checks, указанные в пакете.
- [ ] Просмотреть diff, пройти требуемое review и исправить принятые замечания.
- [ ] Создать отдельный commit пакета только после свежей зелёной проверки.

---

## Пакет 0 — фактическая разведка Ozon

### Задача 0.1. Снять безопасные живые фикстуры

**Файлы:**

- `bin/capture-ozon-buyout-fixtures.sh` — новый интерактивный сборщик.
- `bin/tests/capture-ozon-buyout-fixtures.test.sh` — автономный контрактный
  тест с поддельными `curl` и `date`.
- `api/tests/Fixtures/Marketplace/ozon/*buyout-2026-08-30*.json` — уже снятые
  локальные evidence-файлы; остаются untracked и не используются тестами.
- `api/tests/Fixtures/Marketplace/ozon/ozon-buyout-posting-statuses-before.json`
  и `ozon-buyout-posting-statuses-after.json` — создать минимальную пару
  synthetic status fixtures перед Package 2.
- `api/tests/Fixtures/Marketplace/ozon/ozon-buyout-returns.json` — создать
  минимальную synthetic returns fixture перед Package 3.

**Шаги:**

- [x] Запустить тесты сборщика:

   ```bash
   bash bin/tests/capture-ozon-buyout-fixtures.test.sh
   ```

   Ожидание: `PASS: capture-ozon-buyout-fixtures`.

- [x] Проверить зависимости локальной машины: `bash`, `curl`, `jq`, `python3`.

- [x] Владелец кабинета выполнил сбор за 2026-06-01 — 2026-08-30:

   ```bash
   bash bin/capture-ozon-buyout-fixtures.sh
   ```

   Скрипт спрашивает `Client-Id`, скрыто читает `Api-Key`, период и
   необязательный posting number. Он листает `/v2/posting/fbo/list` по
   `offset`, `/v1/returns/list` по `last_id`, получает справочник
   `/v1/posting/fbo/cancel-reason/list` и при необходимости
   `/v2/posting/fbo/get`.

- [x] Проверить завершённость страниц: FBO = 7×1000 + 617, returns =
  8×500 + 432; потолок 100 страниц не достигнут.

- [ ] Если будущая выдача достигает 100 страниц, сузить период и повторить:
   такой запуск завершается ошибкой, а не выдаёт неполную фикстуру как полную.

- [x] Проверить JSON и privacy paths. Непустого `legal_info` нет, но выгрузки
  содержат product names/offer IDs, города, Ozon company ID, адреса пунктов,
  barcodes и exemplar IDs. Поэтому **не выполнять `git add` этих файлов**.

- [x] Перед тестами вручную собрать минимальные synthetic fixtures из
  вымышленных идентификаторов и названий; сохранить только поля, доказанные в
  `docs/task/ozon-buyout-rate-research.md`:

   ```bash
   jq -e . api/tests/Fixtures/Marketplace/ozon/ozon-buyout-*.json >/dev/null
   rg -n -i 'legal_info|company_id|address|barcode|exemplar|customer|buyer|phone|email' \
     api/tests/Fixtures/Marketplace/ozon/ozon-buyout-*.json
   ```

  Фиксированная матрица synthetic данных:

  | Order | SKU/posting | before → after | Return | Outcome |
  |---|---|---|---|---|
  | `TEST-MIX-1` | `100001` / `TEST-MIX-1-1` | `delivering` → `cancelled` | `Cancellation`, handover refusal | P |
  | `TEST-MIX-1` | `100002` / `TEST-MIX-1-2` | `delivering` → `delivered` | нет | D |
  | `TEST-T2-1` | `100003` / `TEST-T2-1-1` | `delivering` → `cancelled` | `Cancellation`, pickup expired | T2 |
  | `TEST-T1-1` | `100004` / `TEST-T1-1-1` | `awaiting_packaging` → `cancelled` | generic `Cancellation` | T1 |
  | `TEST-R-1` | `100005` / `TEST-R-1-1` | `delivering` → `delivered` | `ClientReturn` | R |
  | `TEST-UNKNOWN-1` | `100006` / `TEST-UNKNOWN-1-1` | только `cancelled` | неизвестная причина | `NULL` |
  | `TEST-PENDING-1` | `100007` / `TEST-PENDING-1-1` | `awaiting_deliver` | нет | `NULL` |

  Для `100002` поставить quantity 2, чтобы тесты ловили `COUNT(*)` вместо
  `SUM(quantity)`. В returns добавить два разных `id` для одной тестовой пары
  `(order_number, sku)` и один return posting number, отличающийся от sales,
  чтобы PK/join решения были закреплены тестом.

  Каждая FBO-строка также содержит обязательные для существующего sales parser
  `in_process_at`, `products[].price/currency_code` и соответствующую
  `financial_data.products[]` с fake `product_id`, `price` и
  `commission_amount`. Returns fixture содержит точную доказанную вложенность
  `product`, `visual.status`, `visual.change_moment`, `source_id`, `schema=Fbo`
  и pagination fields `has_next`.

- [ ] Снять второй FBO-срез позже и агрегатно подтвердить переходы одного
  posting между `awaiting_packaging`, `awaiting_deliver`, `delivering` и
  terminal. До этого безопасная handover boundary — `status = delivering`;
  `posting_transferring_to_delivery` не повышать до handover по одному имени.

**Критерий готовности:** живые файлы исследованы без публикации коммерческих
данных; synthetic fixtures покрывают `Cancellation`, `ClientReturn`, multi-SKU
partial buyout, T1/T2/D/R и unknown; второй снимок подтверждает handover.

**Commit:**

```bash
git add bin/capture-ozon-buyout-fixtures.sh \
  bin/tests/capture-ozon-buyout-fixtures.test.sh
git commit -m "test: add safe Ozon research capture"
```

### Задача 0.2. Записать факты и закрыть стоп-условия

**Файл:** `docs/task/ozon-buyout-rate-research.md`.

- [x] Записать агрегаты, JSON paths и выводы в
  `docs/task/ozon-buyout-rate-research.md`, не копируя order/posting/SKU,
  товары, адреса или barcodes.

- [x] Зафиксировать опровергнутые предположения: `PartialReturn` отсутствует;
  `return.id` уникален; `(order_number, sku)` — join, но не return key;
  `last_id` немонотонен; seller cancel-reason list не покрывает buyer reasons;
  один `cancel_reason_id` имеет разные `return_reason_name`.

- [ ] В кабинете Ozon проверить один из 297 локально найденных случаев
  `HANDOVER_REFUSAL + delivered sibling` и подтвердить, что это частичный выкуп
  конкретной SKU. Идентификатор не переносить в документ или чат.

**Выпускной гейт:** по указанию владельца 2026-08-30 второй status-срез и одна
UI-сверка P не блокируют реализацию. ADR-019 принимает консервативную границу
`status = delivering`, а неоднозначные случаи оставляет `NULL`. До выпуска
повторный снимок и ручная проверка обязательны; дополнительный endpoint не
требуется.

**Commit:**

```bash
git add docs/task/ozon-buyout-rate-research.md
git commit -m "docs: record Ozon buyout source research"
```

---

## ADR-019 — источник, ключи и семантика процента выкупа

### Задача ADR. Зафиксировать необратимые решения

**Файлы:**

- `docs/adr/0019-ozon-buyout-rate-sources-and-history.md` — новый ADR.
- `docs/adr/README.md` — строка 019 и связь с ADR-006/009/011/015.

ADR должен принять следующие решения:

1. История статусов имеет ключ `(company_id, marketplace_account_id,
   posting_number, raw_document_id)`, а `observed_at` — обычная колонка и
   часть effective-time индекса. Это явно исправляет две неточности исходной
   постановки: ключ с `changed_at` не даёт идемпотентности по одному raw, а
   `NOT EXISTS` не защищает два разных concurrent raw-ответа.
2. Для текущего handler вводится lock
   `ozon-postings-{accountId}-{businessDate}`. Уникальный ключ закрывает retry,
   lock — одновременность и обратный порядок разных ответов.
3. `observed_at` честно означает момент, когда приложение успешно разобрало
   наблюдение. При backfill используется `marketplace_raw_document.received_at`.
4. `sales_fact.status` остаётся текущим снимком для совместимости, но новые
   расчёты читают только историю. `posting_number` и `order_number` добавляются
   отдельными nullable-колонками: `source_row_id` не используется в join через
   `split_part` на горячем агрегате.
5. `source_row_id` return fact равен строковому `returns[].id`: 4432 строки
   дали 4432 уникальных ID, тогда как три `(order_number, sku)` повторились.
   Связь с продажей — `(company, account, order_number, marketplace_sku)`;
   observed return `posting_number` и `source_id` сохраняются только для trace.
6. Канонические outcome: `T1`, `D`, `T2`, `P`, `R`; unresolved/unknown — это
   `NULL`, а не шестая категория.
7. Главный процент: `D / (D + T2 + P)`. Conversion: `D / (T1 + D + T2 + P)`.
   `R` исключается из обоих знаменателей как состоявшийся выкуп с последующим
   возвратом.
8. Для прогноза используется та же семантика P: pre-handover ставка
   `D / (T1 + D + T2 + P)`, post-handover — `D / (D + T2 + P)`. Вариант из
   постановки без P отвергается: он систематически завысил бы прогноз.
9. Наблюдения агрегируются по quantity. Все API rates — nullable integer bps.
10. `ClientReturn` после D классифицируется R. У `Cancellation` первичен
    доказанный handover из status history; явно стадийные `return_reason_name`
    дополняют историю. `HANDOVER_REFUSAL` становится P только при delivered
    sibling того же order, иначе T2. `cancel_reason_id` остаётся диагностикой:
    seller-справочник не покрывает buyer reasons, а ID 506 неоднозначен.
11. Порог матурации — p95 от handover до terminal по кабинету только при
    достаточной выборке. До минимальной выборки когорта остаётся preliminary.
    Единой константы ПВЗ нет: официальный Ozon показывает срок по конкретному
    заказу/пункту и разрешает продление, поэтому число `14` не становится
    fallback даже если встречается у отдельного пункта.
12. Описывается backfill из raw и окно, после которого данные до внедрения
    считаются неполными, если raw не сохранился.
13. Routine returns window = 3 дня по `visual_status_change_moment`, суточный
    deep rescan = 90 дней в тот же `rescanHour = 3`. Живой 90-дневный срез
    занял 9 из допустимых 100 страниц, поэтому окно проверено объёмом. При
    потолке handler падает и требует сузить диапазон, а не сохраняет partial.

**Проверка:** два человека должны суметь вручную классифицировать одну и ту же
строку каждой synthetic fixture одинаково, пользуясь только ADR; отдельно они
одинаково получают P для mixed order и unknown для неоднозначной отмены без
handover history.

**Review:** обе роли — новая fact-схема, внешний источник, идентификация строк,
идемпотентность и формула правдоподобной бизнес-метрики.

**Commit:**

```bash
git add docs/adr/0019-ozon-buyout-rate-sources-and-history.md docs/adr/README.md
git commit -m "docs: decide Ozon buyout rate model"
```

---

## Пакет 1 — схема истории статуса и ключи связи sales fact

### Задача 1.1. Сначала закрепить Entity и writer тестами

**Новые файлы:**

- `api/src/Ingestion/Domain/MarketplacePostingStatus.php`
- `api/src/Ingestion/Domain/MarketplacePostingStatusRepository.php`
- `api/src/Ingestion/Infrastructure/Persistence/DoctrineMarketplacePostingStatusWriter.php`
- `api/tests/Support/Builder/MarketplacePostingStatusBuilder.php`
- `api/tests/Integration/Ingestion/DoctrineMarketplacePostingStatusWriterTest.php`

**Изменяемые файлы:**

- `api/src/Ingestion/Domain/SalesFact.php`
- `api/src/Ingestion/Infrastructure/Persistence/DoctrineSalesFactWriter.php`
- `api/tests/Support/Builder/SalesFactBuilder.php`
- `api/tests/Integration/Ingestion/DoctrineSalesFactWriterTest.php`

Сначала написать падающие тесты:

- один raw записывает один status на posting;
- повтор того же raw не создаёт строку;
- новый raw с теми же status/substatus/reason не создаёт строку;
- новый raw с изменившимся status создаёт ровно одну строку;
- отличие `NULL` и непустого `substatus`/`cancel_reason_id` считается изменением;
- один вызов writer принимает несколько postings и не смешивает компании;
- SalesFact upsert сохраняет и обновляет `posting_number`/`order_number`, а их
  изменение входит в `row_hash`;
- builder по умолчанию создаёт валидную строку без ручного SQL.

Запустить и увидеть RED:

```bash
docker compose exec -T php-cli php vendor/bin/phpunit \
  tests/Integration/Ingestion/DoctrineMarketplacePostingStatusWriterTest.php \
  tests/Integration/Ingestion/DoctrineSalesFactWriterTest.php
```

Реализовать Entity по правилам fact-таблиц. Writer выполняет chunked
`INSERT ... SELECT FROM (VALUES ...)`, внутри `WHERE NOT EXISTS` сравнивает с
последним по `observed_at, raw_document_id`, затем завершает
`ON CONFLICT (...) DO NOTHING`. Сравнение nullable полей — только
`IS NOT DISTINCT FROM`.

### Задача 1.2. Создать одну совместимую миграцию

**Файл:** `api/migrations/Version20260901090000.php`.

Миграция создаёт:

```text
marketplace_posting_status
  PK: company_id, marketplace_account_id, posting_number, raw_document_id
  order_number varchar(64) not null
  status varchar(32) not null
  substatus varchar(64) null
  cancel_reason_id bigint null
  observed_at timestamp(0) not null
```

Индексы:

- `(company_id, marketplace_account_id, posting_number, observed_at,
  raw_document_id)` — latest/transition lookup;
- `(company_id, marketplace_account_id, observed_at, status)` — maturity;
- `(company_id, raw_document_id)` — trace/debug.

В `sales_fact` добавляются nullable `posting_number varchar(64)` и
`order_number varchar(64)`, плюс индексы:

- `(company_id, marketplace_account_id, posting_number)`;
- `(company_id, marketplace_account_id, order_number, marketplace_sku)`.

Колонки nullable намеренно: старая версия приложения продолжит вставлять строки
во время rolling deploy, а исторические значения заполнит backfill пакета 2.
Миграция не разбирает JSON и не держит table lock ради backfill.

Проверить:

```bash
make api-migrate-test
docker compose exec -T php-cli php bin/console doctrine:schema:validate
make db-rebuild-check
```

На копии боевой базы измерить `up` и `down`; после `down` повторно выполнить
`up` и `schema:validate`. В отчёте указать длительность и размер таблиц.

**Критерий готовности:** тесты writer зелёные, схема совпадает с metadata,
повтор одного raw идемпотентен, rollback проверен.

**Review:** обе роли.

**Commit:**

```bash
git add api/migrations/Version20260901090000.php api/src/Ingestion \
  api/tests/Support/Builder api/tests/Integration/Ingestion
git commit -m "feat: add Ozon posting status history"
```

---

## Пакет 2 — запись истории в текущую синхронизацию и backfill

### Задача 2.1. Разобрать posting один раз в status и N раз в sales facts

**Новые файлы:**

- `api/src/Ingestion/Domain/OzonPostingStatusParser.php`
- `api/tests/Unit/Ingestion/Domain/OzonPostingStatusParserTest.php`

**Изменяемые файлы:**

- `api/src/Ingestion/Domain/OzonPostingFboListParser.php`
- `api/tests/Unit/Ingestion/Domain/OzonPostingFboListParserTest.php`
- `api/tests/Fixtures/Marketplace/ozon/posting-fbo-list-buyout-*.json`

Тестами зафиксировать:

- status parser выдаёт одну строку на posting независимо от числа products;
- обязательные `posting_number`, `order_number`, `status` fail loudly;
- optional `substatus` и `cancel_reason_id` сохраняют `NULL`, а не `''/0`;
- sales parser копирует `posting_number`/`order_number` во все SKU-строки;
- смешанное отправление с двумя SKU не теряет quantity;
- malformed shape бросает `UnexpectedValueException`, raw при этом уже может
  быть сохранён handler’ом.

Запустить unit test в RED, реализовать чистые parser’ы, повторить до GREEN.

### Задача 2.2. Подключить writer и lock к handler

**Изменяемые файлы:**

- `api/src/Ingestion/Application/MessageHandler/FetchOzonPostingsHandler.php`
- `api/src/Ingestion/Ui/Command/ImportOzonFixtureCommand.php`
- `api/tests/Integration/Ingestion/FetchOzonPostingsHandlerTest.php`
- `api/tests/Integration/Ingestion/ImportOzonFixtureCommandTest.php` — новый.

Сначала добавить integration tests:

- два последовательных ответа одного posting со сменой статуса дают две строки;
- два одинаковых ответа дают одну строку;
- retry после сохранённого raw не дублирует status/fact;
- два вызова для одного account/date не выполняются одновременно;
- сообщения одного account для **разных дат** имеют разные lock keys;
- fixture import проходит путь raw → status → sales fact без HTTP.

Handler берёт lock до запроса Ozon, освобождает в `finally`, использует TTL
900 секунд. После raw capture он вызывает status parser/writer и sales
parser/writer. Частичный сбой допустим: retry добирает отсутствующую часть, а
уже записанная упирается в уникальные ключи.

### Задача 2.3. Восстановить историю из существующего raw

**Новые файлы:**

- `api/src/Ingestion/Infrastructure/Query/OzonPostingRawHistoryQuery.php`
- `api/src/Ingestion/Infrastructure/Query/OzonPostingRawHistoryRow.php`
- `api/src/Ingestion/Ui/Command/BackfillOzonPostingStatusesCommand.php`
- `api/tests/Integration/Ingestion/BackfillOzonPostingStatusesCommandTest.php`

Query выбирает только `report_type = ozon_posting_fbo_list`, только одну
company/account за запуск, keyset’ом по `(received_at, id)`, не загружает всю
историю в память. Command:

1. принимает обязательные company/account, `--from`, `--to`, `--dry-run`;
2. идёт от старого raw к новому;
3. передаёт `received_at` как historical `observed_at`;
4. записывает status history;
5. повторно прогоняет sales parser/upsert, заполняя новые link columns;
6. печатает documents/statuses/facts, но не raw body.

Тест доказывает порядок переходов, повторяемость и отсутствие чтения чужой
компании.

Проверка пакета:

```bash
docker compose exec -T php-cli php vendor/bin/phpunit \
  tests/Unit/Ingestion/Domain/OzonPostingStatusParserTest.php \
  tests/Unit/Ingestion/Domain/OzonPostingFboListParserTest.php \
  tests/Integration/Ingestion/FetchOzonPostingsHandlerTest.php \
  tests/Integration/Ingestion/ImportOzonFixtureCommandTest.php \
  tests/Integration/Ingestion/BackfillOzonPostingStatusesCommandTest.php
```

На production-like копии сначала выполнить `--dry-run`, затем backfill и
повторить его. Второй прогон обязан записать 0 новых status rows.

**Review:** обе роли — ingestion, retry, lock и backfill.

**Commit:**

```bash
git add api/src/Ingestion api/tests
git commit -m "feat: record Ozon posting status transitions"
```

---

## Пакет 3 — raw и факты возвратов

### Задача 3.1. Зафиксировать fact schema тестом и миграцией

**Новые файлы:**

- `api/src/Ingestion/Domain/MarketplaceReturnFact.php`
- `api/src/Ingestion/Domain/MarketplaceReturnFactRepository.php`
- `api/src/Ingestion/Infrastructure/Persistence/DoctrineMarketplaceReturnFactWriter.php`
- `api/tests/Support/Builder/MarketplaceReturnFactBuilder.php`
- `api/tests/Integration/Ingestion/DoctrineMarketplaceReturnFactWriterTest.php`

**Изменяемый файл схемы:**

- `api/migrations/Version20260901090000.php` — единая миграция задачи;
  таблица возвратов добавляется в неё, чтобы релиз не оставлял промежуточную
  несовместимую схему.

Таблица `marketplace_return_fact`:

```text
PK: company_id, marketplace_account_id, source_row_id
order_number varchar(64) not null
marketplace_sku varchar(64) not null
return_type varchar(64) not null
return_reason_name text not null
posting_number varchar(64) not null
source_id bigint not null
quantity integer not null check (quantity > 0)
visual_status_id integer not null
visual_status varchar(64) not null
visual_status_changed_at timestamp(0) not null
raw_document_id uuid not null
row_hash char(64) not null
first_loaded_at / last_updated_at timestamp(0) not null
```

Индексы: `(company_id, marketplace_account_id, order_number,
marketplace_sku)` и `(company_id, visual_status_changed_at)`. `source_row_id` —
точное строковое представление `returns[].id`; `(order_number, sku)` не годится
для PK, потому что три такие пары имели несколько независимых return IDs.

`visual_status_changed_at` не называется business/outcome date: поле отражает
последнее изменение обработки возврата, а cohort date берётся из связанной
`sales_fact.business_date`. `posting_number` не называется child posting — API
не даёт такой семантики и в трёх живых строках он расходится с исходным.

Writer — bulk upsert с `WHERE row_hash IS DISTINCT FROM`; row hash включает
type, reason, link fields, quantity и visual status/timestamp. Тесты покрывают
повтор, изменение visual status, повторную `(order, sku)` с другим return ID,
mixed tenant и trace на raw.

### Задача 3.2. Реализовать paginated connector и parser

**Новые файлы:**

- `api/src/Ingestion/Domain/OzonReturnsFetcher.php`
- `api/src/Ingestion/Domain/OzonReturnsListParser.php`
- `api/src/Ingestion/Domain/OzonReturnsPage.php`
- `api/src/Ingestion/Infrastructure/Connector/Ozon/OzonReturnsListClient.php`
- `api/tests/Unit/Ingestion/Domain/OzonReturnsListParserTest.php`
- `api/tests/Unit/Ingestion/Infrastructure/Connector/Ozon/OzonReturnsListClientTest.php`
- `api/tests/Support/Fake/FakeOzonReturnsFetcher.php`

**Изменяемый файл:**

- `api/src/Ingestion/Domain/MarketplaceReportType.php` — добавить
  `OzonReturnsList`.

Client делает один POST одной страницы. Он не знает БД и handler. Parser
возвращает facts, `hasNext` и следующий `lastId`; проверяет, что при
`has_next=true` список непуст и cursor изменился. Cursor считается opaque:
численно он может уменьшаться между страницами, сортировать или требовать
монотонного роста нельзя. MAX pages = 100; достижение потолка — exception,
никакой частичной «успешной» выгрузки.

Контрактные тесты используют отдельную synthetic fixture и проверяют точное
тело запроса, заголовки, 200, auth failure, 4xx/5xx, невалидный JSON и
немонотонный, но изменившийся `last_id`. Логи не содержат Api-Key/raw.

### Задача 3.3. Добавить message/handler, расписание и ручной backfill

**Новые файлы:**

- `api/src/Ingestion/Application/Message/FetchOzonReturnsMessage.php`
- `api/src/Ingestion/Application/MessageHandler/FetchOzonReturnsHandler.php`
- `api/src/Ingestion/Ui/Command/BackfillOzonReturnsCommand.php`
- `api/tests/Integration/Ingestion/FetchOzonReturnsHandlerTest.php`
- `api/tests/Integration/Ingestion/BackfillOzonReturnsCommandTest.php`

**Изменяемые файлы:**

- `api/config/packages/messenger.yaml`
- `api/src/Ingestion/Application/DispatchActiveOzonSyncsAction.php`
- `api/tests/Integration/Ingestion/ScheduleOzonSyncCommandTest.php`
- `api/tests/Integration/Ingestion/SyncOzonAccountCommandTest.php`

Handler получает lock на account, листает pages, каждую страницу сохраняет
отдельным raw до parse, затем upsert facts. Authorization failure проходит тот
же жизненный цикл broken account, что остальные Ozon handlers. Routine tick
передаёт последние 3 дня `visual_status_change_moment`, суточный deep tick —
90 дней; одно сообщение обслуживает всё окно, а не создаёт N конкурентных
сообщений по дням.

Backfill принимает `--from/--to`, отказывается от диапазона свыше 365 дней и
режет допустимый диапазон на последовательные окна максимум по 90 дней.
Scheduler test проверяет ровно одно returns message на active account за тик,
диапазоны 3/90 дней, rescan hour и отсутствие сообщений для broken account.

Проверить migration up/down, schema validate, parser/client/handler/writer,
повторную загрузку synthetic fixture и tenant isolation.

**Review:** обе роли.

**Commit:**

```bash
git add api/migrations/Version20260901090000.php api/config/packages/messenger.yaml \
  api/src/Ingestion api/tests
git commit -m "feat: ingest Ozon return facts"
```

---

## Пакет 4 — единая классификация T1/D/T2/P/R

### Задача 4.1. Создать конфигурацию причин и вычисляемую view

**Новые файлы:**

- `api/src/Ingestion/Domain/BuyoutOutcome.php`
- `api/src/Ingestion/Domain/OzonReturnEventStage.php`
- `api/src/Ingestion/Domain/OzonReturnReasonClassification.php`
- `api/migrations/Version20260901090000.php` — дополнить view и справочником
  классификации в той же миграции задачи
- `api/src/Ingestion/Infrastructure/Query/BuyoutOutcomeQuery.php`
- `api/src/Ingestion/Infrastructure/Query/BuyoutOutcomeRow.php`
- `api/src/Ingestion/Infrastructure/Query/UnclassifiedOzonBuyoutReasonsQuery.php`
- `api/tests/Integration/Ingestion/BuyoutOutcomeQueryTest.php`

Миграция создаёт глобальный справочник
`ozon_return_reason_classification(return_type, return_reason_name,
event_stage, verified_at)` с составным PK и CHECK для стадий
`HANDOVER_REFUSAL/PICKUP_EXPIRED/DELIVERY_FAILED/CANCELLED`. Он заполняется
**только доказанными research/ADR-019** строками. `cancel_reason_id` не является
ключом: seller endpoint не покрыл ни одного buyer ID, а ID 506 оказался
неоднозначным. Та же миграция создаёт view `buyout_outcome`.

Начальный seed фиксирован живым срезом:

| event_stage | `return_reason_name` |
|---|---|
| `HANDOVER_REFUSAL` | `Покупатель отказался при вручении: товар не подошел` |
| `HANDOVER_REFUSAL` | `Покупатель отказался при вручении: в заказе не тот товар` |
| `HANDOVER_REFUSAL` | `Покупатель отказался при вручении: недоволен качеством товара` |
| `HANDOVER_REFUSAL` | `Покупатель отказался при вручении: неполная комплектация` |
| `HANDOVER_REFUSAL` | `Изменил решение о покупке/Товар не подошёл` |
| `PICKUP_EXPIRED` | `Покупатель не забрал заказ` |
| `DELIVERY_FAILED` | `Не удалось доставить заказ` |
| `CANCELLED` | `Покупатель отменил заказ` |
| `CANCELLED` | `Покупатель отменил заказ: нашел дешевле` |
| `CANCELLED` | `Покупатель отменил заказ: не устроил срок доставки` |
| `CANCELLED` | `Покупатель отменил заказ: перенос сроков доставки` |
| `CANCELLED` | `Нашлось дешевле` |

Все строки относятся к `return_type = Cancellation`. `ClientReturn`
классифицируется R по type и не зависит от локализованной причины.

View выдаёт одну или несколько количественных аллокаций строки `sales_fact`;
сумма `quantity` аллокаций всегда равна исходному количеству:

```text
company_id, marketplace_account_id, source_row_id,
posting_number, order_number, marketplace_sku, quantity, business_date,
outcome nullable, handed_over_at nullable, resolved_at nullable
```

Алгоритм SQL:

1. `DISTINCT ON` выбирает latest posting status по
   `(observed_at DESC, raw_document_id DESC)`.
2. Отдельные агрегаты находят первый handover и первый terminal timestamp.
3. `delivered` без return → D; `ClientReturn` связанной delivered SKU → R.
   Return evidence сначала агрегируется по quantity. Частичный возврат или
   отмена классифицирует только подтверждённое количество, остаток сохраняет
   D либо `NULL`; вся sales-строка не перекрашивается по одному return event.
4. Terminal cancellation получает T1, только если история содержит хотя бы
   одно pre-handover наблюдение и до terminal не содержит handover. Наличие
   handover, `PICKUP_EXPIRED` или `DELIVERY_FAILED` даёт T2. Одна terminal
   строка с общей причиной `CANCELLED` не доказывает T1 и остаётся unknown.
5. `Cancellation + HANDOVER_REFUSAL` по точному `(company, account,
   order_number, sku)` получает P, если sibling SKU того же order уже имеет
   D или R. Если все siblings разрешились и D/R нет — это T2; пока sibling
   unresolved, outcome остаётся `NULL`, потому что тот ещё может стать D.
6. `cancel_reason_id` сохраняется в outcome/query для диагностики, но не
   переопределяет противоречащий `return_reason_name` или handover history.
7. Unresolved, отсутствующая link column, неизвестный status/substatus/reason
   дают `NULL`, не D и не T2.

Synthetic integration fixtures обязаны покрыть: multi-SKU order с
`HANDOVER_REFUSAL` одной позиции и delivered sibling; такой же отказ без
delivered sibling; sibling, который пока unresolved; `ClientReturn` после D;
T1 до handover; T2 после handover; delivery; pending; ambiguous reason без
history; одинаковый order number в чужой компании. Тест также сверяет quantity
и timestamps.

`UnclassifiedOzonBuyoutReasonsQuery` возвращает distinct
type/reason/status/substatus/cancel reason ID, число затронутых строк и
первую/последнюю дату для операционного контроля.

Проверка:

```bash
docker compose exec -T php-cli php vendor/bin/phpunit \
  tests/Integration/Ingestion/BuyoutOutcomeQueryTest.php
docker compose exec -T php-cli php bin/console doctrine:schema:validate
make db-rebuild-check
```

**Критерий готовности:** mixed order даёт P только отказавшейся SKU, sibling
остаётся D/R; generic cancellation без handover не влияет на метрику и видна
диагностикой.

**Review:** обе роли, потому что пакет меняет схему и определяет бизнес-цифру.
Исходная оценка «одно ревью» повышена из-за SQL view/config migration.

**Commit:**

```bash
git add api/migrations/Version20260901090000.php api/src/Ingestion api/tests
git commit -m "feat: classify Ozon buyout outcomes"
```

---

## Пакет 5 — матурация и фактическая метрика

### Задача 5.1. Рассчитать p50/p90/p95 без материализации

**Новые файлы:**

- `api/src/Ingestion/Infrastructure/Query/BuyoutMaturityQuery.php`
- `api/src/Ingestion/Infrastructure/Query/BuyoutMaturityRow.php`
- `api/tests/Integration/Ingestion/BuyoutMaturityQueryTest.php`

Query использует `percentile_disc` по `resolved_at - handed_over_at`, отдельно
по company/account. В выборку входят только валидные non-negative интервалы и
доказанные terminal outcomes. Возвращаются sample size и p50/p90/p95 в целых
секундах.

Минимум для measured p95 — 30 resolved postings. При меньшем sample p95 =
`NULL`, зрелых когорт нет. Тесты покрывают границу 29/30, strict условие
`age > p95`, разные accounts и future/negative timestamps.

### Задача 5.2. Построить quantity-weighted отчёт

**Новые файлы:**

- `api/src/Ingestion/Infrastructure/Query/BuyoutRateQuery.php`
- `api/src/Ingestion/Infrastructure/Query/BuyoutRateRow.php`
- `api/src/Ingestion/Application/BuildBuyoutRateReportAction.php`
- `api/src/Ingestion/Application/BuyoutRateReport.php`
- `api/src/Ingestion/Application/BuyoutRateSku.php`
- `api/tests/Integration/Ingestion/BuildBuyoutRateReportActionTest.php`

Query агрегирует в PostgreSQL по company и SKU:

- `orderedQuantity = SUM(quantity)` всех строк периода;
- количества T1/D/T2/P/R и unresolved;
- `conversionRateBps = D / (T1+D+T2+P)`;
- `actualBuyoutRateBps = D / (D+T2+P)`;
- `resolutionRateBps = (T1+D+T2+P+R) / ordered`;
- `t1RateBps`, `t2RateBps`, `partialReturnRateBps` имеют один явно
  документированный denominator `orderedQuantity`;
- `maturityStatus = mature`, только если весь запрошенный cohort старше p95;
  иначе `preliminary`.

Деление выполняется decimal/numeric, затем единообразно округляется в bps;
PHP не складывает сырые факты. Пустой знаменатель возвращает `NULL`.

Тесты используют quantity 1/2/10, чтобы случайный `COUNT(*)` не прошёл;
проверяют R, unresolved, нулевые denominators, boundary dates и tenant isolation.

**Review:** одно для чисто счётной версии; обе роли, если по ходу пакет начнёт
читать денежные поля/`marketplace_expense_fact` — это расширение scope.

**Commit:**

```bash
git add api/src/Ingestion api/tests/Integration/Ingestion
git commit -m "feat: calculate matured Ozon buyout rates"
```

---

## Пакет 6 — накопительный прогноз свежих когорт

### Задача 6.1. Зафиксировать baseline и fallback integration-тестом

**Новые файлы:**

- `api/src/Ingestion/Infrastructure/Query/BuyoutForecastQuery.php`
- `api/src/Ingestion/Infrastructure/Query/BuyoutForecastRow.php`
- `api/src/Ingestion/Infrastructure/Query/BuyoutDailyQuery.php`
- `api/src/Ingestion/Infrastructure/Query/BuyoutDailyRow.php`
- `api/tests/Integration/Ingestion/BuyoutForecastQueryTest.php`
- `api/tests/Integration/Ingestion/BuyoutDailyQueryTest.php`

Фиксированные правила первой версии:

- training window — последние 30 календарных дней **дозревших** когорт до
  текущей cohort boundary;
- SKU-rate применяется при ≥30 resolved quantity в training window;
- иначе fallback на marketplace account rate;
- если account sample тоже <30, forecast rate = `NULL`, а UI показывает
  «недостаточно данных»; global/category fallback не добавляется;
- до handover применяется `D/(T1+D+T2+P)`, после handover —
  `D/(D+T2+P)`;
- confidence interval в первый релиз не входит: в исходной постановке он
  optional, а контракту и экрану он не нужен.

`BuyoutForecastQuery` суммирует уже resolved D и expected quantity unresolved
строк по их текущему lifecycle. Округление делается один раз после суммы, а не
по строке, иначе множество малых заказов накопит bias.

`BuyoutDailyQuery` возвращает по business date:

- actual bps только для mature date, иначе `NULL`;
- projected bps;
- resolution bps;
- ordered/resolved/projected quantities для tooltip и проверки.

Главный integration test создаёт сегодняшнюю когорту, вызывает query, затем
добавляет status transition pre-handover → handed-over → terminal и после
каждого шага доказывает изменение прогноза без отдельного job/materialized row.
Другие тесты проверяют SKU threshold, account fallback, no-data, P и R.

**Критерий готовности:** прогноз детерминирован на frozen clock и меняется
только от истории/returns, не от порядка вставки.

**Review:** одна роль — расчётная логика; принятые замечания о формуле требуют
повторного integration test, а не только правки SQL.

**Commit:**

```bash
git add api/src/Ingestion/Infrastructure/Query api/tests/Integration/Ingestion
git commit -m "feat: forecast Ozon buyout rates"
```

---

## Пакет 7A — read API и сгенерированный контракт

### Задача 7A.1. Реализовать список и дневной ряд

**Новые файлы:**

- `api/src/Ingestion/Ui/Controller/ListBuyoutRatesController.php`
- `api/src/Ingestion/Ui/Controller/ShowSkuBuyoutDailyController.php`
- `api/src/Ingestion/Ui/Response/BuyoutRateListResponse.php`
- `api/src/Ingestion/Ui/Response/BuyoutRateSummaryResponse.php`
- `api/src/Ingestion/Ui/Response/BuyoutRateItemResponse.php`
- `api/src/Ingestion/Ui/Response/BuyoutDailyResponse.php`
- `api/src/Ingestion/Ui/Response/BuyoutDailyPointResponse.php`
- `api/tests/Functional/Ingestion/ListBuyoutRatesControllerTest.php`
- `api/tests/Functional/Ingestion/ShowSkuBuyoutDailyControllerTest.php`

Маршруты:

```text
GET /api/companies/{companyId}/buyout-rate?days=7|30|90&cursor&limit
GET /api/companies/{companyId}/buyout-rate/{sku}/daily?days=7|30|90
```

List response:

```text
summary:
  orderedQuantity, resolvedQuantity, projectedBuyoutQuantity,
  projectedBuyoutRateBps nullable, resolutionRateBps
items[]:
  marketplaceSku, offerId nullable, name nullable,
  orderedQuantity, resolvedQuantity, projectedBuyoutQuantity,
  projectedBuyoutRateBps nullable,
  t1RateBps nullable, t2RateBps nullable, partialReturnRateBps nullable,
  maturityStatus (mature|preliminary), resolutionRateBps nullable
nextCursor nullable
```

Daily point содержит `date`, nullable `actualBuyoutRateBps`, nullable
`projectedBuyoutRateBps`, `resolutionRateBps`, quantities.

List keyset сортируется по `marketplace_sku ASC`, cursor — последний SKU;
список агрегирует SKU по компании так же, как существующая unit economics
витрина. Summary считается по полному периоду отдельным bounded aggregate, а не
по странице. SKU path parameter URL-decode/validate; отсутствие строк даёт
200 + пустой series, не 404.

Functional tests до реализации:

- чужая company не читается и данные другой company не попадают в ответ;
- days вне 7/30/90, limit 0/201, malformed cursor → 422 stable error;
- две страницы не пересекаются и не пропускают SKU;
- summary не меняется между страницами;
- rates/quantities сериализуются числами, unknown rate — JSON `null`;
- daily series сортируется по дате и не выдаёт точки другого SKU/company.

### Задача 7A.2. Обновить OpenAPI/types

**Генерируемые файлы:**

- `packages/api-schema/openapi.json`
- `packages/api-schema/src/schema.d.ts`
- `apps/seller/src/api/schema.ts`

Выполнить:

```bash
make api-doc-export
make api-types
make api-types-check
```

Ни одного handwritten interface, дублирующего response DTO, во фронтенде не
добавлять.

**Review:** одна роль — новый read contract. Если SQL меняет семантику пакетов
5/6, вернуть изменение в соответствующий пакет и повторить его review.

**Commit:**

```bash
git add api/src/Ingestion/Ui api/tests/Functional/Ingestion \
  packages/api-schema apps/seller/src/api/schema.ts
git commit -m "feat: expose Ozon buyout rate API"
```

---

## Пакет 7B — экран «Выкуп»

### Задача 7B.1. Сначала протестировать URL, query keys и presentation

**Новые файлы:**

- `apps/seller/src/features/buyout-rate/lib/buyoutParams.ts`
- `apps/seller/src/features/buyout-rate/lib/buyoutParams.test.ts`
- `apps/seller/src/features/buyout-rate/lib/buyoutStatusPresentation.ts`
- `apps/seller/src/features/buyout-rate/lib/buyoutStatusPresentation.test.ts`
- `apps/seller/src/features/buyout-rate/model/useBuyoutRates.ts`
- `apps/seller/src/features/buyout-rate/model/useBuyoutRates.test.ts`
- `apps/seller/src/features/buyout-rate/model/useBuyoutDaily.ts`
- `apps/seller/src/features/buyout-rate/model/useBuyoutDaily.test.ts`

Pure tests фиксируют:

- допустимые URL days только 7/30/90, default 30;
- смена days сохраняется в URL и обнуляет cursor;
- query key начинается с `companyQueryKey(companyId, ...)` и включает days,
  cursor и SKU для daily;
- путь кодирует SKU через `encodeURIComponent`;
- bps форматируются до 0–2 знаков без float-арифметики бизнес-метрики;
- mature → neutral Badge, preliminary → warning с текстом процента;
- nullable rate → «Недостаточно данных», не `0%`.

Hooks используют `createCompanyApiClient`, generated schema types и сохраняют
предыдущую страницу только там, где это не показывает цифры другого периода.

Запустить RED, затем GREEN:

```bash
npm --workspace apps/seller test -- --run
```

### Задача 7B.2. Собрать страницу только из существующих примитивов

**Новые файлы:**

- `apps/seller/src/features/buyout-rate/ui/BuyoutRatePage.tsx`
- `apps/seller/src/features/buyout-rate/ui/BuyoutRateTable.tsx`
- `apps/seller/src/features/buyout-rate/ui/SkuBuyoutDaily.tsx`

**Изменяемые файлы:**

- `apps/seller/src/app/Root.tsx` — child route `redemption`.
- `apps/seller/src/app/Sidebar.tsx` — «Выкуп» сразу после «Продажи».

Page:

- читает `companyId` из route и days/cursor из URL;
- показывает три `Button` 7/30/90;
- summary: «84% выкуп · 92% заказов разрешилось · 1 240 из 1 480 шт»;
- имеет отдельные loading/empty/error/data состояния, без fake zero.

Table:

- обычный semantic `<table>`, не новая dependency;
- колонки «Артикул / Заказано / Выкуп / Статус»;
- под прогнозом всегда T1/T2/P с denominator, закреплённым API;
- button внутри строки управляет `expandedSku`, `aria-expanded` и доступен с
  клавиатуры;
- раскрытие добавляет соседний `<tr><td colSpan>` и **не** меняет URL cursor;
- предыдущая/следующая страница keyset’ом; смена периода закрывает accordion.

Daily:

- `Card` + имеющийся `recharts` `ResponsiveContainer/LineChart`;
- actual line имеет gaps для `null`, projected line остаётся видимой;
- tooltip показывает дату, rates и quantities;
- loading/empty/error/data независимы от состояния списка;
- график имеет текстовый accessible summary, цвет не единственный различитель.

Не добавлять `Table`, `Dialog`, `Tabs`, `Select` в UI kit и не менять package
dependencies: Recharts уже установлен.

### Задача 7B.3. E2E пользовательского пути

**Новый файл:** `apps/seller/tests/e2e/buyout-rate.spec.ts`.

Использовать существующий fixture import/backfill путь, без реального Ozon.
Сценарий:

1. логин и вход в компанию;
2. пункт «Выкуп» расположен после «Продажи» и открывает `/redemption?days=30`;
3. summary и T1/T2/P содержат ожидаемые значения;
4. выбор 7 дней меняет URL и данные;
5. раскрытие SKU показывает две серии и не сбрасывает страницу;
6. после перехода next/previous нет дублей;
7. отдельные prepared cases показывают preliminary и no-data тексты.

Проверка:

```bash
make front-test
make front-typecheck
make front-lint
make front-knip
make front-build
make test-e2e
```

**Review:** одна роль — экран и read integration.

**Commit:**

```bash
git add apps/seller/src apps/seller/tests/e2e
git commit -m "feat: add Ozon buyout rate screen"
```

---

## Финальная проверка и выпуск

1. Выполнить полный локальный pipeline:

   ```bash
   make ci-local
   git diff --check
   ```

2. На production-like копии:

   - применить единую миграцию `Version20260901090000`;
   - выполнить posting и returns backfill;
   - повторить backfill и получить 0 новых history rows;
   - выполнить `EXPLAIN (ANALYZE, BUFFERS)` list/daily для 90 дней;
   - убедиться, что plans начинают tenant indexes с `company_id`, не делают
     sequential scan всей fact-таблицы и не содержат N+1;
   - проверить unclassified diagnostics — перед релизом список пуст либо каждый
     оставшийся ID описан как известное ограничение.

3. Сверить вручную минимум 10 разнотипных SKU-строк с локальными evidence и
   synthetic fixtures:
   source → history → outcome → aggregate → API → UI. Отдельно проверить один
   synthetic quantity > 1 и mixed order с `HANDOVER_REFUSAL + delivered
   sibling`.

4. Выполнить package review по таблице ниже; все принятые замечания исправить и
   повторить затронутые тесты/review до отсутствия принятых замечаний.

| Этап | Ревью |
|---|---|
| Package 0 | не требуется; live evidence остаётся untracked, synthetic fixtures проходят privacy check |
| ADR-019 | обе роли |
| Packages 1–3 | обе роли |
| Package 4 | обе роли |
| Package 5 | одна; обе при появлении денег/expenses |
| Package 6 | одна |
| Package 7A | одна |
| Package 7B | одна |

5. Выпускать backend schema/code до UI. Порядок совместим: nullable link columns
   позволяют старому writer работать; новые API не вызываются старым UI. После
   backfill измерить coverage:

   ```text
   sales rows total
   sales rows with posting_number
   sales rows with order_number
   posting rows with classified outcome
   unclassified rows grouped by status/substatus/reason
   accounts with maturity sample < 30
   ```

6. Критерий завершения всей задачи:

   - повторная загрузка и параллельный retry не дублируют данные;
   - P затрагивает только точную SKU внутри заказа;
   - unknown не превращается в правдоподобную цифру;
   - API соблюдает tenant isolation и keyset contract;
   - UI не показывает `0%` вместо loading/no-data;
   - прогноз меняется после status transition без фонового пересчёта;
   - `make ci-local` и проверки production-like базы зелёные.
