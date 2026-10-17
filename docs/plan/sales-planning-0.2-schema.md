# Этап 0.2 — модуль SalesPlanning, схема и домен

Общий план — `sales-planning-stage0.md`, решения — ADR-040.

## Результат

Модуль `SalesPlanning` существует в коде: таблицы шапки и строк плана,
сущности, репозиторий шапки, правило раскладки периода по дням.
API и экрана ещё нет — сценарии появляются в 0.4. Таблица занятых дней —
в 0.7, вместе с утверждением, которое её пишет.

## Шаги

**1. Каркас модуля**
- `api/src/SalesPlanning/{Domain,Infrastructure}` — только слои, в которых
  появляется код.
- `api/deptrac.php`: четыре слоя `SalesPlanning*` и правила; доступа
  к Ingestion и Identity на этом этапе нет — он появится с первым вызовом.
- `api/config/packages/doctrine.yaml`: маппинг `SalesPlanning`.
- `api/bin/check-src-structure.sh`, описание `structure-check` в `Makefile`.
- `docs/structure.md`, «Модули» в `CLAUDE.md`.

**2. Домен**
- `SalesPlanStatus` — enum `draft | approved | superseded | discarded | cancelled`.
- `SalesPlan` — шапка (ADR-040 п. 3, 6, 8, 13): именованный конструктор
  черновика; инварианты — `date_from ≤ date_to`, период ≤ 184 дней,
  валюта обязательна; `lineage_id = id` у первой версии;
  `#[ORM\Version]`; метод явного повышения версии при правке строк.
- `SalesPlanLine` — строка SKU × день: `quantity ≥ 0`, цена `Money > 0`
  в валюте плана; цена и штуки правятся только у черновика.
- `EvenDayDistribution` — раскладка штук по дням периода (п. 7):
  `floor(total / days)`, остаток — первым дням, сумма равна введённому.
- `SalesPlanRepository` (интерфейс в Domain): `add`, `get(companyId, planId)`.

**3. Схема — одна миграция**
- `sales_plan`: PK `id`; индекс `(company_id, marketplace_account_id)`;
  частичные уникальные `(company_id, lineage_id) WHERE status = 'draft'`
  и `… WHERE status = 'approved'`; `CHECK` на статус, порядок дат, форму
  кода валюты. Длины периода в CHECK нет: это обратимая константа (ADR-040
  п. 13), её смена не должна требовать миграции.
- `sales_plan_line`: PK `id` (UUIDv7); уникальный
  `(company_id, sales_plan_id, sku, plan_date)`;
  `CHECK quantity >= 0`, `price_minor > 0`.
- Канонические `where` частичных индексов — из `pg_indexes`
  (`patterns.md`, «Частичный уникальный индекс»).
- `down()` рабочий.

**4. Инфраструктура**
- `DoctrineSalesPlanRepository`: чтение только по `companyId` и id.

**5. Тесты**
- `SalesPlanBuilder`, `SalesPlanLineBuilder` — неизменяемые.
- Unit: раскладка (ровное деление; остаток первым дням; итог меньше дней;
  ноль; один день; сумма всегда равна итогу); инварианты шапки и строки;
  валюта строки равна валюте плана.
- Integration: план компании A не читается по `companyId` компании B;
  второй черновик в той же линии — нарушение уникального индекса.

## Чек-лист готовности

- [ ] Модуль виден Deptrac, `make deptrac` зелёный
- [ ] `make structure-check` знает `SalesPlanning`
- [ ] Одна миграция; `make db-rebuild-check` зелёный; `down()` работает
- [ ] `doctrine:schema:validate` зелёный, `migrations:diff` пуст
- [ ] Каждый метод репозитория принимает `companyId` первым
- [ ] `company_id` первым в каждом ключе и индексе
- [ ] Денег без `Money`, float нет
- [ ] Builder на каждую сущность
- [ ] `make stan`, `make lint`, `make test-unit`, `make test-int` зелёные
- [ ] `structure.md`, `CLAUDE.md` обновлены
- [ ] Ревью Claude + 5A + 5B (схема данных) завершено, принятые замечания исправлены
- [ ] CI зелёный, PR смёржен; миграция применена на проде и проверена

## Как проверяем

- `make db-rebuild-check` — схема с пустой базы и все тесты.
- `make api-console CMD="doctrine:schema:validate"` и
  `CMD="doctrine:migrations:diff"` — расхождений нет.
- Unit-тесты раскладки доказывают сумму и порядок остатка.
- Integration-тесты доказывают изоляцию компаний и единственный
  черновик линии на уровне базы.
- На проде после применения: `\d sales_plan`, `\d sales_plan_line`
  совпадают с миграцией, `doctrine:migrations:up-to-date` зелёный.
