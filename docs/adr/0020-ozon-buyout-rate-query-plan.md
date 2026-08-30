## ADR-020: Ozon — безопасный план SQL для процента выкупа

**Дата:** 2026-08-30
**Статус:** Accepted
**Модуль:** Ingestion
**Заменяет:** ADR-019

### Контекст

ADR-019 зафиксировал источники, ключи и семантику T1/D/T2/P/R. Его реализация
использовала коррелированное чтение order/SKU-группы для prefix quantity и
запрещала оконную функцию по истории кабинета. На production это допущение не
подтвердилось: раскрытие зависимых CTE повторяло чтение status evidence для
каждой продажи. Один запрос выполнял примерно 4 138 коррелированных итераций,
имел стоимость плана около 217 млн и занимал 3–4,5 минуты.

После batch import статистика PostgreSQL некоторое время также может быть
устаревшей. При оценке tenant cohort в одну строку nested-loop plan повторял
сканы status evidence до 19 434 раз и упирался в пятисекундный timeout даже
после устранения исходной корреляции. После `ANALYZE` тот же запрос занимал
6–7 мс, но доступность API не должна зависеть от момента auto-analyze.

### Решение

Все решения ADR-019 об источниках, natural keys, append-only history,
классификации, количественной аллокации, метриках, матурации, прогнозе и
backfill повторно принимаются без изменений. Меняется только техника расчёта
и обязательные свойства плана.

`buyout_outcome` вычисляет prefix quantity оконными агрегатами, partitioned по
`(company_id, marketplace_account_id, order_number, marketplace_sku)`. Для
order-level признаков применяется отдельный tenant-scoped partition. Фильтр
`company_id` и, где известен, `marketplace_account_id` обязан проталкиваться в
base scans ниже `WindowAgg`; фильтр отчётного периода остаётся выше окна,
поскольку более ранние строки той же order/SKU-группы нужны для правильного
распределения return quantity.

Rate, forecast и daily query сначала материализуют явный `tenant_outcome` с
предикатом компании и только затем строят остальные CTE. List action открывает
`REPEATABLE READ READ ONLY` transaction для согласованности двух data queries;
daily action выполняет единственный SELECT в `READ ONLY` transaction. Оба
через `SET LOCAL` устанавливают:

- `statement_timeout = '5s'`;
- `jit = off`;
- `enable_nestloop = off`.

Если action вызван внутри уже открытой transaction, настройки и возможная
ошибка timeout изолируются savepoint: в `finally` выполняются rollback к
savepoint и его release. Поэтому planner settings не утекают в вызывающий
use case, а transaction не остаётся aborted после ошибки SQL.

Миграция view задаёт transaction-local `lock_timeout = '5s'`. Если старый
долгий запрос держит lock, DDL быстро завершается ошибкой и его можно безопасно
повторить после устранения блокера; миграция не остаётся в очереди и не создаёт
новый production-инцидент блокировкой следующих чтений.

Integration regression выполняет `EXPLAIN (ANALYZE, BUFFERS)` на view и всех
трёх query-классах. Рядом с целевой компанией создаётся существенно больший
чужой cohort. Тест требует tenant predicate непосредственно в base scans
`sales_fact`, `marketplace_posting_status`, `marketplace_return_fact`: полный
`Seq Scan` запрещён, `company_id` должен находиться в `Index Cond`/`Recheck
Cond`, а для account-scoped запроса `marketplace_account_id` должен находиться
непосредственно в predicate того же scan (`Index Cond`, `Recheck Cond` или
`Filter`). Дополнительно требуются один scan `sales_fact` и `Actual Loops = 1`
для всех base relations.

### Альтернативы, которые отвергли

- **Оставить коррелированный prefix scan** — production plan доказал SQL N+1
  и многоминутное время ответа.
- **Фильтровать view по периоду до окна** — ускоряет запрос ценой неверной
  аллокации, когда order/SKU имеет продажи до начала периода.
- **Полагаться только на auto-analyze** — сразу после import остаётся окно, в
  котором API недоступен из-за ошибочной cardinality estimate.
- **Глобально отключить nested loops или JIT** — меняет планы несвязанных
  запросов; настройка нужна только короткому read-only расчёту.
- **Материализовать outcome в таблицу** — добавляет invalidation и новый
  изменяемый источник истины, хотя history и returns уже достаточны.
- **Ждать lock view без ограничения** — queued DDL способен блокировать новые
  чтения view и превратить безопасный deploy в outage.

### Последствия

**Получаем:** линейное чтение tenant cohort, предсказуемое время ответа при
устаревшей статистике, ограниченный blast radius timeout/настроек и
исполняемую регрессию tenant pushdown.

**Платим:** окно должно видеть всю историю order/SKU целевого tenant;
`enable_nestloop = off` может выбрать не самый дешёвый план на маленьком
cohort; migration lock conflict требует операционного retry.

Перед изменением формы view или границы materialization необходимо повторить
EXPLAIN-тесты с большим foreign cohort и production-shaped stale statistics.
