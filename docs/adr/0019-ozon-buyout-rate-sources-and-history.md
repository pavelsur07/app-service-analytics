## ADR-019: Ozon — источники и семантика процента выкупа

**Дата:** 2026-08-30
**Статус:** Accepted
**Модуль:** Ingestion
**Уточняет:** ADR-006, ADR-009, ADR-011 и ADR-015

### Контекст

Процент выкупа нельзя корректно восстановить из текущего `sales_fact.status`:
одна и та же FBO-отгрузка проходит несколько стадий, а Ozon задним числом
меняет текущий статус. Для различения отмены до передачи в доставку (T1),
невыкупа после передачи (T2), доставки (D), частичного выкупа внутри заказа
(P) и клиентского возврата после доставки (R) нужны история наблюдений
отгрузки и `/v1/returns/list`.

Живой срез за 2026-06-01 — 2026-08-30 подтвердил фактическую форму данных:

- `/v2/posting/fbo/list` содержит `order_number`, `posting_number`, `status`,
  `substatus`, `cancel_reason_id`, `in_process_at` и товары;
- 7617 postings объединены в 6671 orders, у 790 orders несколько postings;
- `/v1/returns/list` дал 4432 уникальных `id`, но три повторные пары
  `(order_number, sku)`;
- встречены только `Cancellation` и `ClientReturn`; `PartialReturn` нет;
- return `posting_number` в трёх строках расходится с posting продажи;
- seller cancel-reason endpoint не покрывает buyer reasons, а один
  `cancel_reason_id` соответствует разным текстовым причинам.

Источники и агрегаты зафиксированы без коммерческих идентификаторов в
`docs/task/ozon-buyout-rate-research.md`. Живые ответы остаются локальными;
тесты используют отдельные synthetic JSON.

Единственный снимок не доказывает, что
`awaiting_deliver/posting_transferring_to_delivery` уже означает handover.
Поэтому решение принимает только более позднюю и безопасную границу
`status = delivering`. Второй снимок и ручная сверка P остаются обязательной
проверкой перед выпуском, но не блокируют реализацию: неподтверждённая ранняя
стадия не используется для повышения исхода до T2.

### Решение

#### История статусов

Создаётся append-only `marketplace_posting_status` с естественным ключом:

```text
(company_id, marketplace_account_id, posting_number, raw_document_id)
```

`observed_at` — момент успешного разбора наблюдения, а не заявленное Ozon
время смены статуса. Для backfill используется `marketplace_raw_document.received_at`.
Поле входит в effective-time индекс, но не в PK: один raw-документ должен
оставлять не более одного наблюдения каждого posting независимо от retry.

Writer сравнивает новый статус с ближайшим хронологическим предшественником и
не пишет одинаковое значение повторно. Именно предшественник, а не абсолютная
последняя строка, нужен для backfill: более раннее наблюдение нельзя отбросить
из-за уже записанного будущего состояния. Это уменьшает историю, но не является
защитой от гонки. Идемпотентность обеспечивает PK и `ON CONFLICT DO NOTHING`.

Одновременные загрузки одного дня сериализуются Symfony Lock с ключом:

```text
ozon-postings-{marketplaceAccountId}-{businessDate}
```

Account-only lock запрещён: суточный deep rescan запускает несколько
business dates одного кабинета, и такая блокировка потеряла бы часть дней.

`sales_fact.status` остаётся текущим снимком для совместимости. Для joins
добавляются nullable `posting_number` и `order_number`; вычислять их через
`split_part(source_row_id)` в аналитическом запросе запрещено. Nullable нужен
для совместимого rolling deploy и заполняется backfill. Новые факты принимают
только `quantity > 0`; одноимённый DB constraint сначала создаётся `NOT VALID`,
чтобы не сканировать legacy-таблицу во время rolling migration, и валидируется
после отдельного аудита старых строк.

#### Факты возвратов

`marketplace_return_fact.source_row_id` равен точному строковому представлению
`returns[].id`. Естественный ключ:

```text
(company_id, marketplace_account_id, source_row_id)
```

Join с продажей выполняется только по:

```text
(company_id, marketplace_account_id, order_number, marketplace_sku)
```

Observed `posting_number` и `source_id` сохраняются для trace, но не называются
дочерней отгрузкой и не участвуют в основном join. Факт также хранит type,
точный `return_reason_name`, quantity, visual status/change moment, raw link,
row hash и времена первой/последней загрузки. Upsert обновляет mutable поля
только при изменении row hash и более свежем evidence; повтор того же raw ID
может исправить результат после обновления parser.

Returns cursor считается opaque: проверяется только, что при `has_next=true`
он непуст и отличается от предыдущего. Численное сравнение и сортировка cursor
запрещены.

Routine returns window — последние 3 дня по `visual_status_change_moment`.
Ежедневно в 03:00 выполняется deep rescan за 90 дней. Ручной backfill принимает
не более 365 дней и режет диапазон на окна максимум по 90 дней. Достижение
100 страниц является ошибкой неполной выгрузки, а не успешным результатом.
Facts становятся видимыми только после получения последней страницы окна;
при ошибке cursor/page cap сохранённые raw остаются для диагностики, но
частичная выборка не попадает в расчётную витрину. Занятый account lock приводит
к retry сообщения, а не к успешному ACK и потере окна backfill.

#### Классификация

Канонические outcomes: `T1`, `D`, `T2`, `P`, `R`. Pending и unknown имеют
SQL `NULL`; шестой категории для них нет.

Handover доказан первым наблюдением `status = delivering`. Значение
`awaiting_deliver`, включая `posting_transferring_to_delivery`, handover не
доказывает. Terminal — `delivered` или `cancelled`.

Правила применяются в следующем порядке:

1. Delivered SKU без `ClientReturn` получает D.
2. Delivered SKU со связанным `ClientReturn` получает R.
3. Cancellation до любого handover получает T1 только при наличии реального
   pre-handover наблюдения. Одна terminal-строка без истории остаётся `NULL`.
4. Cancellation после handover, `PICKUP_EXPIRED` или `DELIVERY_FAILED`
   получает T2.
5. `Cancellation + HANDOVER_REFUSAL` получает P только для точной SKU, если
   sibling SKU того же order уже имеет D или R. Если все siblings разрешились
   и выкупленного sibling нет — T2. Пока sibling unresolved — `NULL`.
6. Неизвестные status/substatus/reason остаются `NULL` и попадают в отдельный
   диагностический запрос.

`ClientReturn` классифицируется по type и не зависит от локализованной причины.
Для Cancellation применяется справочник точных пар
`(return_type, return_reason_name) → event_stage`. Начальный seed содержит
только строки, подтверждённые исследованием: `HANDOVER_REFUSAL`,
`PICKUP_EXPIRED`, `DELIVERY_FAILED`, `CANCELLED`. `cancel_reason_id` остаётся
диагностикой и не переопределяет history или return reason.

Return quantity агрегируется по `(company, account, order, sku)`, после чего
единожды распределяется по упорядоченным sales-строкам этой группы. Поэтому
один return не умножается на число posting-строк, а разные причины для
quantity > 1 могут породить несколько outcome-аллокаций одной sales-строки.
Сумма аллокаций всегда равна исходному `sales_fact.quantity`. Prefix quantity
вычисляется коррелированным чтением малой order/SKU-группы по tenant index;
оконная функция по всей истории кабинета запрещена, чтобы фильтр периода
оставался pushdown-safe.

Общая причина `CANCELLED` не связывается с конкретным posting. Если duplicate
sales-строки одной `(order, sku)` имеют разный lifecycle (часть имеет реальное
pre-handover наблюдение, часть — handover), общий event и не покрытый точным
event stage остаток классифицируются `NULL`. Это консервативно и не позволяет
порядку posting менять знаменатель actual buyout. Unmapped причины и overflow
return quantity диагностируются независимо от того, остался ли в outcome
`NULL` после количественного cap. Любой return evidence у строки, чей latest
posting status ещё active/pending, выключает forecast eligibility и остаётся
видимым диагностике: это допустимый ingestion race, но не основание строить
правдоподобный прогноз на противоречивом snapshot.

#### Метрики и матурация

Все количества взвешиваются через `SUM(sales_fact.quantity)`. Проценты
передаются nullable integer basis points; нулевой знаменатель даёт `NULL`.

```text
actual buyout = D / (D + T2 + P)
conversion    = D / (T1 + D + T2 + P)
resolution    = (T1 + D + T2 + P + R) / ordered
```

R исключается из знаменателей buyout/conversion: выкуп состоялся, возврат
произошёл позже. P входит как невыкуп конкретной SKU.

Зрелость кабинета определяется `percentile_disc` по неотрицательным интервалам
`handed_over_at → resolved_at`. Измеренный p95 существует только при sample
не меньше 30 resolved postings; до этого когорты `preliminary`. Единой
константы срока ПВЗ нет и fallback `14 дней` не используется.

#### Прогноз

Training window — 30 календарных дней дозревших когорт перед текущей
границей. SKU-rate применяется при resolved quantity не меньше 30, иначе
используется account rate. Если и account sample меньше 30, прогноз `NULL`.

```text
до handover:    D / (T1 + D + T2 + P)
после handover: D / (D + T2 + P)
```

P входит в обе формулы. Округление выполняется один раз после суммирования,
а не для каждой строки.

#### Backfill и предел истории

История статусов восстанавливается из сохранённых raw-документов от старых
к новым. Raw `received_at` становится historical `observed_at`; тем же
прогоном заполняются link columns sales facts. Повторный backfill должен
добавить 0 status rows.

До самого раннего сохранённого raw полная последовательность статусов
неизвестна. Terminal cancellation без доказанного предыдущего состояния
поэтому остаётся unknown, а не правдоподобным T1/T2.

### Альтернативы, которые отвергли

- **Считать только текущий `sales_fact.status`** — невозможно различить
  отмену до/после handover; задним числом теряется переход.
- **Ключ истории с `observed_at` или `changed_at`** — retry одного raw может
  получить другое application timestamp и создать дубль.
- **Только `NOT EXISTS` без уникального ключа** — два конкурентных INSERT
  проходят проверку одновременно.
- **Join return по `posting_number`** — живой срез содержит расхождения;
  каноническая связь подтверждена через order + SKU.
- **Ключ return по `(order_number, sku)`** — три пары имеют разные return IDs.
- **Классификация по `cancel_reason_id`** — seller-справочник не покрывает
  buyer IDs, а один ID оказался неоднозначным.
- **Считать любой handover refusal как P** — без delivered sibling это полный
  невыкуп T2, а не частичный выкуп заказа.
- **Относить unknown к T1 или T2** — создаёт убедительную, но недоказанную
  бизнес-цифру.
- **Материализовать outcome/forecast** — история и returns уже являются
  источником истины; материализация потребовала бы отдельной инвалидации.
- **Универсальный срок ПВЗ 14 дней** — срок зависит от заказа/пункта и может
  продлеваться.

### Последствия

**Получаем:**

- воспроизводимую классификацию из immutable raw;
- идемпотентную историю и returns facts;
- P только для точной SKU mixed order;
- честный `NULL` для данных, которых пока недостаточно;
- прогноз, автоматически меняющийся после status transition.

**Платим:**

- больше fact/history данных и три новых схемных объекта;
- тяжёлые SQL views/aggregates, требующие проверки plans на production-like
  объёме;
- старая история ограничена глубиной сохранённого raw;
- до 30 resolved наблюдений экран показывает preliminary/no-data.

**На что обратить внимание потом:**

- второй snapshot докажет, что `awaiting_deliver` уже после handover — новое
  решение расширяет boundary; этот ADR после Accepted не редактируется;
- ручная UI-сверка опровергнет P для delivered sibling — выпуск блокируется и
  семантика пересматривается новым ADR;
- unclassified diagnostics растёт — расширять справочник только после
  проверки новых точных строк, не по частичному совпадению текста;
- 90-дневное окно достигает 100 pages — уменьшить chunk/window до выпуска
  partial данных.
