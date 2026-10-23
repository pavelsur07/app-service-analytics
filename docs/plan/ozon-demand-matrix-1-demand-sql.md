# Матрица спроса, этап 1 — общий CTE спроса `DemandSql`

Общий план — `ozon-demand-matrix-report.md`, решение 4.

## Задача

Спрос по кластеру покупателя сейчас определён внутри
`StockPlacementSql::placementCte()` дважды: в `sku_accounts` (какие
подключения продают SKU) и в `demand_sales` (штуки по кластеру). Матрица
спроса должна считать ровно то же. Выносим определение в один класс,
и «Остатки» начинают читать спрос из него.

## Результат

- `api/src/Ingestion/Infrastructure/Query/Demand/DemandSql.php`:
  - константы `EXCLUDED_STATUSES = ['cancelled']`,
    `REGION_BASIS = 'buyer_cluster'`, `PERIOD_BASIS = 'order_date_europe_moscow'`;
  - `linesCte()` — CTE `demand_lines`: строки `sales_fact` компании
    за `[:demandFrom, :windowEnd]` без исключённых статусов, колонки
    `marketplace_account_id, marketplace_sku, cluster (= cluster_to),
    business_date, quantity`. Кластер склада (`cluster_from`) в CTE
    не выбирается — чтобы его нельзя было взять по ошибке.
- `StockPlacementSql::placementCte()` строит `sku_accounts` и
  `demand_sales` поверх `demand_lines`; собственного обращения
  к `sales_fact` в нём не остаётся.
- Ответ «Остатков» не меняется ни в одной цифре; контракт API, схема БД,
  фронтенд не затрагиваются.

## Чек-лист приёмки

- [ ] в `StockPlacementSql` нет `FROM sales_fact`;
- [ ] `status <> 'cancelled'` и `cluster_to` для спроса встречаются
      только в `DemandSql`;
- [ ] `BuildStockPlacementReportActionTest` и
      `ShowStockPlacementReportControllerTest` зелёные без правки
      ожиданий;
- [ ] интеграционный тест `DemandSql`: отменённые не входят, кластер —
      покупателя (заказ из кластера A в B даёт строку B), границы окна
      включительны, чужая компания не видна;
- [ ] `make stan`, `make lint`, `make deptrac`, `make test-unit`,
      `make test-int`, `make test-func` зелёные;
- [ ] `docs/structure.md` — упомянут каталог `Query/Demand`, если там
      перечислены каталоги запросов.

## Проверка

Тесты выше; дополнительно — `EXPLAIN` запроса «Остатков» до и после:
`sales_fact` по-прежнему читается по
`idx_sales_fact_company_business_date`.

## Ревью

Ingestion, расчёт спроса — риск high: Claude + оба прохода Codex,
единым пакетом с этапами 2–3 или отдельным, если этап уйдёт отдельным PR.
