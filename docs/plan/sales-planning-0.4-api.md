# Этап 0.4 — API: создание черновика, ввод SKU, просмотр

**Статус:** реализовано в PR #235. Сохранено как обоснование лимитов
и замера пакетной записи, на которое ссылается `SalesPlan`.
Шаги и чек-листы ниже — исходный план завершённого этапа.

Общий план — `sales-planning-stage0.md`, решения — ADR-040.

## Результат

Сценарии и HTTP-контракт плана продаж. Экрана ещё нет (0.5), правки
отдельного дня — тоже (0.6). Все маршруты — `/api/companies/{companyId}/sales-plans…`
(CLAUDE.md §1), членство проверяет `CompanyAccessSubscriber`.

| Метод | Путь | Что делает |
|---|---|---|
| GET | `…/sales-plans/catalog-skus?marketplaceAccountId&search&cursor&limit` | выбор SKU: каталог подключения через `IngestionFacade` |
| POST | `…/sales-plans` | черновик: подключение, название, период, валюта → `201 {id, version}` |
| PUT | `…/sales-plans/{planId}/skus` | ввод SKU за период: `{version, items[≤100]: {marketplaceSku, totalQuantity, priceMinor}}` → раскладка по дням; SKU уже в плане — перераскладка |
| GET | `…/sales-plans?marketplaceAccountId&cursor&limit` | список планов: шапка, число SKU, штуки, выручка |
| GET | `…/sales-plans/{planId}?groupBy=day\|week\|month\|period&cursor&limit` | шапка с версией и итогами + страница SKU с итогом и корзинами периода |

## Решения внутри этапа

- **Подключение компании** проверяется через `IdentityFacade::listConnections`:
  чужое или `revoked` — 422.
- **SKU — только из каталога подключения**: `IngestionFacade::presentAccountCatalogSkus`
  одним запросом на весь запрос; отсутствующие — 422 со списком.
- **Лимиты** (ADR-040 п. 13, ADR-041): SKU в плане ≤ 1000, период ≤ 184 дней
  (до 184 000 строк в версии), позиций в запросе ≤ 100; превышение — 422.
- **Версия** (ADR-008): в ответе на чтение и обязательна в PUT; несовпадение
  или `OptimisticLockException` — 409.
- **Аудит** (ADR-040 п. 10) — `IdentityFacade::recordAuditEntry` тем же flush:
  `sales_plan.created` (шапка); `sales_plan.sku_set` — по записи на SKU,
  «было» и «стало» — точные значения всех дней SKU `[[дата, штуки, цена]]`;
  у нового SKU «было» — `null`.
- **Запись строк — пакетом через DBAL** (ADR-041): дни SKU — значения,
  а не сущности; вставка с обновлением по естественному ключу, повторный
  ввод SKU перераскладывает дни на тех же строках. Шапка, версия и журнал —
  ORM, первыми в транзакции.
- **Чтение** — DBAL в readonly DTO (§5): агрегации в PostgreSQL,
  выручка — `SUM(quantity * price_minor)` в `bigint`, без float.
  Корзины `week` — ISO-неделя, `month` — календарный месяц; обе обрезаны
  границами периода плана. Keyset: план — по `id` (UUIDv7) убыванию,
  SKU — по `marketplace_sku`.
- **Замер** (песочница, `APP_DEBUG=0`).
  ORM с порционным flush: ~3 200 строк/с; 50 SKU × 184 дня — 2,2 с, копия
  30 000 строк — 9,4 с. Отсюда решение владельца — ADR-041.
  Пакетная запись: 100 SKU × 184 дня — 0,8–1,1 с, перераскладка — 1,3 с;
  200 SKU — 1,5–1,9 с и 2,6 с. Позиций в запросе — 100; план на 1 000 SKU
  × 184 дня (184 000 строк) — десятью запросами.

## Шаги

1. Deptrac: `SalesPlanningApplication → identityFacade, ingestionFacade`.
2. Domain: `SalesPlanAuditAction`, исходы сценариев.
3. Application: `CreateSalesPlanAction`, `SetSalesPlanSkusAction`,
   `SearchPlanCatalogSkusAction`.
4. Infrastructure: `SalesPlansQuery` (список), `SalesPlanSkuTotalsQuery`
   (страница SKU с корзинами), `SalesPlanHeaderQuery` (шапка и итоги),
   загрузка строк SKU одним запросом в репозитории.
5. Ui: запросы, ответы, контроллеры, атрибуты OpenAPI.
6. Контракт: `make api-doc-export`, `make api-types`.
7. Тесты — ниже.
8. Документация: `structure.md`.

## Тесты

- **Integration**: создание с проверкой подключения; ввод SKU — раскладка,
  перераскладка на месте, аудит «было/стало» по дням; неизвестные SKU;
  лимиты; версия — 409; корзины day/week/month/period и итоги; изоляция
  чтений по `companyId`.
- **Functional (HTTP)**: изоляция — пользователь компании A не читает
  и не меняет план компании B ни через её путь (403), ни подставив
  `planId` B в путь A (404); разбор тела на границе — невалидный JSON,
  дробная цена, отсутствующая версия — 422.

## Чек-лист готовности

- [ ] Все чтения с `companyId` первым; план чужой компании — 404
- [ ] Списки с лимитом, keyset, превышение лимита — 422; COUNT по фактам нет
- [ ] Запросов в цикле нет: SKU каталога, строки SKU — одним запросом
- [ ] Деньги — минорные единицы и валюта; float нет
- [ ] Версия в ответе и обязательна в PUT; 409 на конфликт
- [ ] Аудит на создание и ввод SKU с точными значениями дней
- [ ] Deptrac: SalesPlanning входит в Ingestion и Identity только через Facade
- [ ] OpenAPI и TS-типы в синхроне (`make api-types-check`)
- [ ] `make stan`, `lint`, `deptrac`, `test` зелёные
- [ ] Замер строкового лимита записан
- [ ] Ревью Claude + 5A + 5B (изоляция арендаторов, контракт API) — без принятых замечаний
- [ ] CI зелёный, PR смёржен, выкладка прошла, прод отвечает на новые маршруты

## Как проверяем

- Functional-тесты изоляции и разбора тела через HTTP.
- Integration-тесты сценариев и чтений на PostgreSQL.
- На проде после выкладки: `debug:router | grep sales_plan` — пять маршрутов;
  `GET …/sales-plans` без сессии — 401.
