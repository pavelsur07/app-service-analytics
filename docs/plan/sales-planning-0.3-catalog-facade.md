# Этап 0.3 — каталог SKU подключения через IngestionFacade

Общий план — `sales-planning-stage0.md`, решения — ADR-040 п. 2, 4.

## Результат

`IngestionFacade` отдаёт SalesPlanning каталог SKU одного подключения
компании двумя методами (ADR-040 п. 4):

1. **Поиск для выбора** — страница с лимитом и курсором: артикул площадки,
   артикул продавца, название; поиск по любому из трёх.
2. **Проверка набора** — какие SKU из переданного списка есть в каталоге
   подключения; один запрос на весь список (создание плана, импорт CSV).

Потребитель — сценарии SalesPlanning этапа 0.4; грант Deptrac на Facade
выдаётся там же, вместе с первым вызовом.

## Шаги

**1. Запросы (Ingestion/Infrastructure/Query/Listings)**
- `AccountCatalogSkuSearchQuery::build(companyId, accountId, search, cursor, limit)`:
  только `marketplace_listing` этого подключения; keyset по `marketplace_sku`,
  `limit + 1` для признака следующей страницы; `ILIKE` с экранированием
  `%`, `_`, `!`; `QueryBuilder` наружу (§5).
- `AccountCatalogSkusPresentQuery::build(companyId, accountId, skus)`:
  `marketplace_sku IN (:skus)` по первичному ключу каталога.
- Оба фильтруют `company_id` **и** `marketplace_account_id`: подключение
  чужой компании даёт пустой ответ, а не чужие артикулы.

**2. Facade**
- `searchAccountCatalogSkus(companyId, accountId, search, cursor, limit): CatalogSkuPage`
  — `CatalogSku { marketplaceSku, offerId, name }`, `nextCursor`.
  Лимит 1–200 проверяется (§5), иначе исключение.
- `presentAccountCatalogSkus(companyId, accountId, list<string> skus): list<string>`
  — пустой вход — пустой выход без запроса; дубли во входе схлопываются;
  больше 1000 SKU — исключение (лимит плана, ADR-040 п. 13).

**3. Тесты (integration)**
- Поиск: страницы по курсору без пропусков и повторов; поиск по артикулу,
  артикулу продавца и названию; `%` и `_` ищутся буквально.
- Изоляция: тот же `accountId` с чужим `companyId` — пусто в обоих методах;
  SKU другого подключения той же компании не попадают.
- Проверка набора: возвращается ровно пересечение; пустой вход; дубли.

**4. Документация**
- `docs/structure.md` — у Ingestion два потребителя Facade.

## Чек-лист готовности

- [ ] Оба метода принимают `companyId` первым, фильтруют компанию и подключение
- [ ] Список не отдаётся без лимита; COUNT нет
- [ ] Запросов в цикле нет: проверка набора — один SQL
- [ ] DTO Facade — `final readonly`, без сущностей Ingestion наружу
- [ ] Тесты изоляции и поиска зелёные
- [ ] `make stan`, `lint`, `deptrac`, `test-int` зелёные
- [ ] Ревью Claude + 5A + 5B (Ingestion, изоляция) завершено, принятые замечания исправлены
- [ ] CI зелёный, PR смёржен, выкладка прошла

## Как проверяем

- Integration-тесты на реальной PostgreSQL доказывают изоляцию и курсор.
- `EXPLAIN` поиска и проверки набора — индекс каталога
  `(company_id, marketplace_account_id, marketplace_sku)`, без полного чтения.
- Deptrac: Facade не тянет ничего, кроме Ingestion и Shared.
