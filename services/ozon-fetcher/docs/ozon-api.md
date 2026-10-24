# Ozon Seller API — ресурсы и лимиты

> Перенесено из ВашФинДир (`app-service-finance`, коммит `a9eb74206`).
> Ссылки `docs/tasks/…`, PHP-код и прод-отчёты ниже — репозитория источника;
> сверка с загрузкой Conwix — этап 3 `docs/plan/ozon-fetcher.md`.

Сверка этапа 0 (`docs/tasks/ozon-fetcher-ingestion/`), 2026-10-17. Источники:
рабочий PHP-код Symfony (прод), git-история удалённых клиентов, прод-отчёт
`app:marketplace:perf-report`, документация и публикации Ozon. Значения без
подтверждения на проде помечены **не подтверждено**.

Общее для всех методов: `POST https://api-seller.ozon.ru<endpoint>`, заголовки
`Client-Id`, `Api-Key`, `Content-Type: application/json`. Ответ ошибки —
конверт `{"code": <int>, "message": "..."}`.

## Ресурсы

### `ozon.finance.accrual-by-day` — `POST /v1/finance/accrual/by-day`

- **Запрос:** `{"date": "YYYY-MM-DD"}`; со второй страницы + `"last_id": "<курсор>"`.
  Ровно один день.
- **Ответ:** `{"accruals": [...], "last_id": "..."}`. Пустой `last_id` — конец.
  Отсутствующий или не-массивный `accruals` — **ошибка формы**, не пустой день.
- **Пагинация:** курсорная. Повтор курсора — ошибка. На проде день укладывается в
  одну страницу (выгрузка июня 2026); защитный предел — 100 страниц.
- **Структура:** `accrued_category` ∈ `POSTING | ITEM | NON_ITEM`; `accrual_id`
  — идентификатор начисления.
- **Задержка данных:** операции появляются до ~3 дней после события; Ozon
  дописывает начисления задним числом (`ozon_cpc`, переоформления «сторно +
  новое начисление», 01.10.2026).
- **Источник:** `site/src/Marketplace/Ozon/Infrastructure/Api/OzonAccrualByDayClient.php`.

### `ozon.finance.accrual-types` — `POST /v1/finance/accrual/types`

- **Запрос:** `{}`.
- **Ответ:** `{"accrual_types": [{"id": <int|string>, "name": "<код>"}]}`;
  имена — английские коды (`Acquiring`, `Logistic`, `LastMileCourier`), по ним
  Symfony разбирает категории затрат.
- **Пагинация:** нет. Справочник, раз в сутки.
- **Источник:** тот же клиент, `fetchServiceTypes`.

### `ozon.finance.realization` — `POST /v2/finance/realization`

- **Запрос:** `{"month": <1..12>, "year": <YYYY>}`; только закрытый месяц.
- **Ответ:** `{"result": {"header": {...}, "rows": [...], ...}}`; строка — SKU в
  заказе.
- **Готовность:** не раньше 5–8 числа следующего месяца. «Не готов» приходит
  как 4xx **или** пустой `rows` — оба случая — исход `not_ready`, не ошибка
  (так же работает `SyncOzonRealizationHandler`).
- **Пагинация:** нет.
- **Источник:** `site/src/Marketplace/Ozon/Infrastructure/Api/OzonRealizationFetcher.php`,
  `.../MessageHandler/SyncOzonRealizationHandler.php`.

### `ozon.finance.mutual-settlement` — `POST /v1/finance/mutual-settlement`

**Асинхронный отчёт** — другая механика, чем у остальных ресурсов:

1. `POST /v1/finance/mutual-settlement` `{"date": "YYYY-MM", "language": "DEFAULT"}`
   → `code` отчёта (`result.code`).
2. `POST /v1/report/info` `{"code": "<code>"}` → `result.status`
   (`success` → `result.file` — ссылка на файл; `failed` → ошибка; иначе — ждать).
3. `GET <file>` без заголовков авторизации (pre-signed) → файл (XLSX).

Raw этого ресурса — байты файла отчёта, не JSON.

**Не подтверждено:** на проде клиент не работал: добавлен в #1544 (16.04.2026),
удалён как мёртвый код 27.09.2026 (`117887d77`); разбор ответа в нём
«на всякий случай» проверял несколько мест. Форму ответа, длительность
формирования и TTL ссылки подтвердить на тестовом кабинете в этапе 4.
Последняя версия клиента: `git show 117887d77^:site/src/Marketplace/Ozon/Infrastructure/Api/OzonMutualSettlementClient.php`.

### `ozon.orders.fbo`, `ozon.orders.fbs` — `POST /v3/posting/fbo/list`, `POST /v4/posting/fbs/list`

Замена снятых `/v2/posting/fbo/list` (400 с 19.10.2026) и `/v3/posting/fbs/list`
(400 с 21.10.2026) — `docs/tasks/ozon-orders-posting-list/problem.md`.

- **Запрос:** `{"filter": {"since": "<RFC3339 UTC>", "to": "<RFC3339 UTC>"}, "limit": 100,
  "sort_dir": "ASC", "with": {"analytics_data": true, "financial_data": true}}`; со второй
  страницы + `"cursor": "<курсор>"`. Окно — день МСК `[00:00; 00:00 следующего дня)`.
  `offset` и `dir` **не отправляются**: новые методы их молча игнорируют.
- **Ответ** (живой, 21.10.2026): `{"postings": [...], "has_next": <bool>, "cursor": "<40 символов>"}`
  на верхнем уровне, без `result`. У последней страницы `has_next: false`, `cursor: ""`.
  Отправление: `posting_number`, `order_id`, `order_number`, `status`, `substatus`,
  `created_at`, `in_process_at`, `products`, `analytics_data`, `financial_data`,
  `cancellation`, `legal_info` (у FBO — `null`), `additional_data` и др.
- **Пагинация:** курсорная, `limit` ≤ 100 (`limit: 101` → 400 `value must be inside range (0, 100]`).
  `has_next: true` без курсора, повтор курсора, полная страница без `has_next` и `cursor`,
  больше 1 000 страниц за окно (по номеру страницы окна, не попытки) — `invalid_response`
  (не обрезка окна). 400 на продолжении сохранённого курсора (после паузы или рестарта) —
  повтор окна с первой страницы (`stale_cursor`): срок жизни курсора Ozon не проверен.
- **Граница окна:** включает ли Ozon `to` — не проверено; отправление ровно в полночь может
  попасть в два дня — потребитель различает отправления по `posting_number`.
- **Смены статусов:** `filter.last_changed_status_date` без `since/to` — 400
  (`date since or date to must not be empty`; FBS — `filter.since and filter.to or
  filter.order_numbers must not be empty`); вместе с `since/to` FBO v3 его **молча
  игнорирует** (за 30 дней 2 348 отправлений с фильтром и без, те же статусы). Статус
  приходит в каждом отправлении — смены видны перечитыванием окон: сегодня — каждый проход
  планировщика, предыдущие 30 дней — раз в сутки (`ORDERS_TAIL_DAYS`, минимум 1 — вчерашний день
  дочитывается всегда), изменение — новая ревизия. Статус отправления старше 30 дней (поздний
  возврат, отмена) в raw не обновляется.
- **Персональные данные:** `addressee`, `customer`, `legal_info` не запрашиваются.
- **Включение:** ресурсы выдаются кабинетам по списку компаний в Symfony
  (`ozon_fetcher.orders_companies`, `site/config/services.yaml`): `[]` — никому, `['*']` — всем.
  Задача кабинета, с которого ресурс снят, в Ozon не ходит (`resource_disabled`, повтор через час).
- **Приоритет:** сегодня и хвост — обычный (5), догон — низкий (1): заказы не опережают
  финансовые отчёты в общей очереди кабинета (одна задача кабинета одновременно).

### `ozon.finance.accrual-postings` — `POST /v1/finance/accrual/postings`

Начисления по каждому отправлению дня. Symfony этот метод регулярно не грузит (коннектор —
`LogicException`, только ручная проба) — это новые данные
(`docs/tasks/marketplace-data-ingestion/stages/ozon-3-accrual-postings.md`).

- **Запрос:** `{"posting_numbers": [...]}` — 1…200 номеров; 201 → 400 `value must contain between 1
  and 200 items, inclusive`. Даты нет, пагинации нет.
- **Ответ** (живой, 21.10.2026): `{"posting_accruals": [{"posting_number": "...", "accruals": [...]}]}` —
  элемент на **каждый** запрошенный номер, в том числе с пустым `accruals`. Начисление: `type_id`,
  `accrued` (объект), `accrual_date`, `sku`, `quantity`, `seller_price`; до 8 на отправление.
  Начисления появляются после доставки или отмены и копятся неделями.
- **Проверка:** ответ должен покрыть запрос — недостающий, лишний или повторный номер, нет
  `posting_accruals` или `accruals` — `invalid_response`.
- **Номера:** из текущей ревизии заказов `ozon.orders.fbo` / `ozon.orders.fbs` того же дня
  (`fetch_task.content_pages` → `raw_object` → S3, sha256 сверяется); FBO+FBS без дублей по
  возрастанию, порции по 200; курсор — `отпечаток списка:последний номер` сохранённой порции: если
  заказы дня перечитаны и список изменился, день начинается заново (`orders_revised`, повтор). Заказы
  дня не загружены или их ревизия начата до конца дня (первая страница записана раньше полуночи МСК
  следующего дня, `content_started_at`) —
  `not_ready` (повтор через час); ревизия заказов без списка страниц (загружена до миграции 00004) —
  заказы дня ставятся на перезагрузку. Отправлений нет — служебная страница
  `{"posting_accruals": []}` без запроса.
- **Ошибки чтения raw заказов:** временные сбои S3 — повтор (`store`); объект повреждён (sha256, gzip),
  пропал или страница ревизии без `raw_object` — `orders_raw_invalid`, `failed_permanent` + ERROR
  (суточное перечитывание повторит задачу после новой ревизии заказов).
- **`period_key`:** день МСК создания отправлений. Сегодня не ставится; дни с `ORDERS_FROM` — догон;
  перечитываются 45 дней (`ACCRUAL_POSTINGS_DAYS`): 14 последних (`ACCRUAL_POSTINGS_DAILY_DAYS`) — раз в
  сутки, старшие — раз в неделю (в дни с возрастом 21, 28, 35, 42), потому что квота метода общая; приоритет
  перечитывания — низкий (1): задача, получившая 429, уходит в конец очереди кабинета по `next_run_at`. Заказы перечитываются 30 дней: на днях
  31–45 начисления перечитываются по последнему списку номеров (новых отправлений у старого дня нет).
- **Включение:** Symfony `ozon_fetcher.accrual_postings_companies` (`['*']`) — только среди кабинетов с заказами.

### `ozon.products.list` — `POST /v3/product/list`

Список всех товаров кабинета — снимок дня. Symfony грузит то же в 03:40
(`RefreshOzonListingCatalogAction`); план —
`docs/tasks/marketplace-data-ingestion/stages/ozon-4-products.md`.

- **Запрос:** `{"filter": {"visibility": "ALL"}, "last_id": "<курсор>", "limit": 1000}`; `limit` в
  `(0, 1000]`, 1001 → 400 `code 3`.
- **Ответ** (живой, 21.10.2026): `{"result": {"items": [...], "total": N, "last_id": "...",
  "total_items": N}}`; товар — `product_id`, `offer_id`, `sku`, `archived`, `has_fbo_stocks`,
  `has_fbs_stocks`, `is_discounted`, `quants`.
- **Пагинация:** `last_id` (keyset, base64). Последняя полная страница отдаёт **непустой** `last_id`;
  конец — следующая страница `items: []`, `last_id: ""`. Сохраняются все страницы, включая концевую
  пустую; пустая страница завершает обход и при непустом `last_id`.
- **Проверка:** нет `result`, `items`, строки `last_id` или неотрицательного `total` —
  `invalid_response`; непустая страница с уже виденным `last_id` — `invalid_response`; больше 1000
  страниц — `invalid_response`. 400 при продолжении с курсором — `stale_cursor`, снимок заново.
- **`period_key`:** день МСК снимка. Задача ставится только на сегодня, без перечитывания; задача за
  прошедший день в Ozon не ходит — `snapshot_missed`, `failed_permanent`, WARNING (пропуск виден в
  покрытии).

### `ozon.products.info` — `POST /v3/product/info/list`

Подробности по каждому товару списка того же дня.

- **Запрос:** `{"product_id": [...]}` — 1…1000; 1001 → 400 `no more than 1000 item(s)`; пустой
  массив → 400 `use either offer_id or product_id or sku`.
- **Ответ** (живой, 21.10.2026): `{"items": [...]}` без `result`; товар — `id`, `offer_id`, `sku`,
  `sources`, `barcodes`, `name`, `description_category_id`, `type_id`, цены, `stocks`, `statuses`,
  `commissions` и др. (39 полей), ≈ 3 КБ на товар. **Неизвестные `product_id` Ozon молча
  пропускает.**
- **Проверка:** нет `items` — `invalid_response`; `id` не из запроса, повторный или без `id` —
  `invalid_response`; недостающие (удалённые между списком и подробностями) — WARNING с числом.
- **id:** из текущей ревизии `ozon.products.list` того же дня (`content_pages` → S3), без дублей по
  возрастанию, порции по 1000; курсор — `отпечаток списка:последний id`, список изменился —
  `catalog_revised`, заново. Число товаров списка ≠ `total` — WARNING. Список дня не загружен —
  `catalog_not_loaded`, `not_ready` через 10 мин. Пустой каталог — служебная страница
  `{"items": []}` без запроса. Повреждённый raw списка — `catalog_raw_invalid` + ERROR.
- **Включение:** Symfony `ozon_fetcher.products_companies` (оба ресурса, ключ seller); подробности
  ставятся только кабинету со списком.

### `ozon.stocks` — `POST /v4/product/info/stocks`

Снимок остатков всех товаров кабинета
(`docs/tasks/marketplace-data-ingestion/stages/ozon-5-stocks.md`).

- **Запрос:** `{"filter": {"visibility": "ALL"}, "limit": 1000}`, со второй страницы — `"cursor"`;
  `limit` в `(0, 1000]`, 1001 → 400 `code 3`.
- **Ответ** (живой, 22.10.2026): `{"items": [...], "total": N, "cursor": "...", "total_items": N}` —
  без `result`; товар — `product_id`, `offer_id`, `stocks[]` (`type` fbo/fbs/rfbs, `present`,
  `reserved`, `sku`, `shipment_type`, `warehouse_ids`). Разбивки по складам нет.
- **Пагинация:** `cursor` до пустой страницы — последняя полная страница отдаёт непустой курсор;
  каждая страница, включая концевую пустую, — в raw. Непустая страница без курсора, повтор курсора,
  нет `items` / `cursor` / `total`, товар без `stocks` — `invalid_response`; потолок 1000 страниц.
  Битый курсор → 400 `invalid last_id ...` → `stale_cursor`, снимок с первой страницы.
- **`period_key`:** день МСК снимка, окно с 05:00; раньше окна — `snapshot_early`, ожидание его
  начала; после полуночи — `snapshot_missed` (`failed_permanent`, WARNING), загрузка, пересекающая
  полночь, прерывается. Перечитывания нет. Конец недели — снимок понедельника («неделя готова» в
  понедельник 06:00, решение Владельца 22.10); отдельного снимка в воскресенье нет.
- **Журнал:** INFO «stocks snapshot read» — страниц, товаров, разных SKU, `total`; товаров ≠ `total`
  — WARNING.
- **Включение:** Symfony `ozon_fetcher.stocks_companies` (ключ seller).

## Ozon Performance API (реклама)

Хост `https://api-performance.ozon.ru` (`OZON_PERFORMANCE_BASE_URL`), пакет `internal/ozonperf`
(`docs/tasks/marketplace-data-ingestion/stages/ozon-6-ads-sync.md`).

- **Токен:** `POST /api/client/token`, `{"client_id","client_secret","grant_type":"client_credentials"}`
  → `access_token`, `expires_in`; кэш по `client_id` в памяти на `expires_in − 5 мин` (не меньше 1 мин),
  параллельные задачи ждут один запрос; 400/401/403 на токене — `auth`, 429 — `rate_limit`, 5xx —
  `transient`. Запросы — `Authorization: Bearer`; 401 — новый токен и один повтор.
- **Журнал:** «ozon performance request» — endpoint без query, статус, время; токен, секрет и тело —
  никогда.
- **Слот:** одна `running`-задача на (кабинет, семейство `ozon.ads.*`) — параллельно с задачей seller
  (миграция 00006); пауза кабинета по отказу ключа seller рекламу не останавливает, отказ ключа
  Performance — пауза только задачи рекламы (`AuthRetryPause`, со второго подряд — `AuthSuspend` + ERROR).

### `ozon.ads.campaigns` — `GET /api/client/campaign`

Список всех кампаний аккаунта (все типы и состояния). Снимок дня: `period_key` — день МСК загрузки,
с 01:00, прошедший день — `snapshot_missed`. Проверка — объект с массивом `list`.

### `ozon.ads.expense`, `ozon.ads.daily` — `GET /api/client/statistics/expense/json`, `/api/client/statistics/daily/json`

Расход аккаунта и дневная статистика по кампаниям за день: `dateFrom = dateTo = period_key`.
Дни с 01.10.2026 (догон), вчера — с 01:00, перечитывание 14 дней раз в сутки (`ads_tail`).
Проверяется только JSON-объект или массив.

**Живая форма** (22.10.2026, журнал «ads page shape» — имена полей без значений):
- кампании — `{list, total}`, кампания: `id`, `title`, `state`, `advObjectType`, `fromDate`, `toDate`,
  `budget`, `dailyBudget`, `weeklyBudget`, `budgetType`, `expenseStrategy`, `placement`,
  `PaymentType`, `ProductAdvPlacements`, `autopilot`, `createdAt`, `updatedAt`, …; JSON;
- расход — `{rows}`, строка: `id`, `title`, `date`, `moneySpent`, `bonusSpent`, `prepaymentSpent`;
  `application/json; charset=utf-8`;
- дневная статистика — `{rows}`, строка: `id`, `title`, `date`, `views`, `clicks`, `moneySpent`,
  `orders`, `ordersMoney`; `application/json; charset=utf-8`.

### `ozon.ads.statistics`, `ozon.ads.statistics.tail` — асинхронный отчёт `POST /api/client/statistics`

Отчёт по товарам кампаний (`docs/tasks/marketplace-data-ingestion/stages/ozon-7-ads-reports.md`).

- **Заказ:** `{"campaigns": [≤ 10 id], "from": "D−1T21:00:00Z", "to": "DT20:59:59Z", "groupBy": "DATE"}`
  (дни МСК → UTC) → `UUID`. `ozon.ads.statistics` — день `period_key` (вчера), `ozon.ads.statistics.tail`
  — 14 дней до `period_key` (день прохода).
- **Статус:** `GET /api/client/statistics/{UUID}` → `state` (`NOT_STARTED`/`IN_PROGRESS` — ждать, `OK`/`READY`
  — готов, `ERROR`/`CANCELLED`/`NOT_FOUND` — ошибка), `link`.
- **Файл:** `GET link` (только хост Performance — Bearer на чужой хост не уходит) или
  `/api/client/statistics/report?UUID=`; ZIP (по сигнатуре `PK`) или CSV — в raw как есть; страница = отчёт порции.
- **Кампании:** SKU из снимка `ozon.ads.campaigns` дня исполнения; только с показами, кликами или
  расходом в днях отчёта по raw `ozon.ads.daily` (строки `rows`/`list` с `id`); форма незнакома или
  день не загружен — все SKU (как Symfony). Нет кампаний — служебная страница `{"reports":[]}`.
- **Курсор:** `отпечаток порций:порция:UUID:время заказа:ошибок подряд` — пишется сразу после заказа;
  рестарт опрашивает тот же UUID.
- **Окно заказа:** 00:30–04:00 МСК (задачи ставятся с 01:30 — после расхода и статистики дня); вне
  окна — `report_wait` до следующего окна; опрос и скачивание — в любое время. Пока у кабинета есть
  задача отчётов с UUID — другая не заказывает (`report_busy`, 5 мин).
- **Опрос:** каждые 30 с до 20 мин в попытке, затем `report_pending` (5 мин, UUID сохранён); не готов 3 ч
  — UUID брошен, следующее окно (WARNING). Ошибка Ozon — новый заказ через 30 мин; 3 подряд —
  `report_failed` (`failed_permanent`, ERROR). 429 на заказ — следующее окно, без повтора подряд.

### Вторая волна (не в первой)

- Прочие финансовые отчёты (компенсации, cash-flow statement и т. п.) — сверка
  перед второй волной.

## Лимиты

### Что известно

- Ozon **не публикует** лимит для финансовых методов в найденных источниках
  (документация Seller API, dev.ozon.ru, публикации о замене v3). Единый
  опубликованный лимит есть только для операций с товарами (30 000 операций в
  минуту, с 24.02.2026) — к финансам не относится.
- На 429 Ozon присылает `Retry-After` (секунды) — использует и Symfony.
- Symfony **не ограничивает** скорость заранее: один воркер `async_sync`
  шлёт запросы подряд и только реагирует на 429/5xx (ретрай транспорта:
  3 попытки, 10 с × 2; для by-day — задержка из `Retry-After`).

### Факт с прода (perf-report 2026-09-17…2026-10-17)

Лог покрывает 10 из 31 дня (файлов за 21 день нет — ротация).

| Показатель | Значение |
|---|---|
| `SyncOzonAccrualByDayMessage` | 339 сообщений, **167 ушли в повтор** (49 %), 0 провалов |
| `api_fetch` Ozon | 339 событий, 785 вызовов, 167 ошибок; p50 70 мс, p95 821 мс |
| Параллельность | 1 воркер `async_sync` на все кабинеты |

Деление 167 повторов на 429 и 5xx в read-only инструментах недоступно
(perf-report не различает статус; warning-логи в GlitchTip не попадают). Вывод
для дефолтов: даже **один последовательный поток на все кабинеты** почти в
половине случаев упирается во временные отказы — лимит финансовых методов
низкий, сервису нужен лимит до запроса, а не только реакция на 429. Разбивку
по статусам даст собственная телеметрия сервиса (этап 12) — первая точка
пересмотра дефолтов.

### Дефолты сервиса (вариант A)

Консервативные стартовые значения на пару `(подключение, endpoint)`;
уточняются по телеметрии. Адаптация на 429 (÷2, рост после серии успехов)
работает в пределах `[пол; потолок]`.

| Endpoint | Старт, запросов/с | Пол | Потолок | Пауза на 429 без `Retry-After` | Основание |
|---|---|---|---|---|---|
| `/v1/finance/accrual/by-day` | 0.3 | 0.05 | 2 | 10 с | **по замеру**: реальный кабинет 17.10.2026 — 429 уже при 0,3 запроса/с (3 из 85 запросов, лимитер дошёл до 0,1); на проде 49 % повторов при 1 потоке |
| `/v1/finance/accrual/types` | 0.2 | 0.05 | 1 | 60 с | **консервативно**; 1 вызов в сутки, скорость не важна |
| `/v2/finance/realization` | 0.2 | 0.05 | 1 | 900 с | **консервативно**; Symfony ждёт 60–3600 с, по умолчанию 900 с |
| `/v1/finance/mutual-settlement` | 0.1 | 0.02 | 0.5 | 900 с | **консервативно**; генерация отчёта, не подтверждено |
| `/v1/report/info` | 0.5 | 0.1 | 2 | 30 с | **консервативно**; опрос статуса, backoff 2→60 с |
| `/v3/product/list`, `/v3/product/info/list` | 0.2 | 0.05 | 1 | 60 с | каталог — 2–3 запроса на кабинет в сутки |
| `/v4/product/info/stocks` | 0.2 | 0.05 | 1 | 60 с | остатки — 2 запроса на снимок |
| `/api/client/campaign`, `/api/client/statistics/expense/json`, `/api/client/statistics/daily/json` (Performance) | 0.2 | 0.05 | 1 | 60 с | **консервативно**; лимиты не опубликованы, единицы запросов на кабинет в сутки; пары лимитера — свои (другие endpoint’ы) |
| `/api/client/statistics` (заказ отчёта) | 1/120 | 1/120 | 1/120 | 120 с | разнос заказов как у Symfony; 429 — следующее окно |
| `/api/client/statistics/{UUID}`, `/statistics/report` | 0.5 / 0.2 | 0.1 / 0.05 | 1 | 30 / 60 с | опрос статуса не ограничен Ozon (опыт Symfony) |
| `/v1/finance/accrual/postings` | 0.2 | 0.2 | 0.2 | 5 с | **по замеру** 22.10.2026: квота 50 запросов на окно 5 с **общая для всех клиентов метода** (`ratelimit-remaining` восстанавливается каждые 5 с и пустеет за 1–3 с), 429 с `retry-after: 1`; поэтому `SharedQuota` — 429 только пауза, темп не снижается, шторм не считается |
| `/v3/posting/fbo/list`, `/v4/posting/fbs/list` | 0.2 | 0.05 | 1 | 60 с | **консервативно**: ключ кабинета общий с Symfony, пока тот не отключён; 19.10 перед отключением старых методов — фаза 429 |

Объём при дефолтах: первичная загрузка — 77 дней by-day по 1 странице
(замер 17.10.2026) ≈ 4–15 мин на кабинет при 0,3…0,1 запроса/с; ежедневный
хвост — 4 запроса. Низкий старт по времени почти ничего не стоит.

Отправления: самый крупный кабинет (≈ 750 FBO в день) — ≈ 8 страниц на день; сегодня
каждый час (≈ 190 запросов в сутки) + хвост 30 дней раз в сутки (≈ 240) ≈ 430 запросов,
≈ 36 мин при 0,2 запроса/с. FBS без отправлений — 1 запрос на день.

### Замер на реальном кабинете (17.10.2026, ООО «Вумджой», тестовый ключ)

| Ресурс | Результат |
|---|---|
| by-day | 77 дней `done`, каждый день — 1 страница 100–180 КБ; 3 ответа 429 при 0,3 запроса/с |
| accrual-types | `done`, 14 КБ |
| realization | M-1 и M-2 `done`, 510–580 КБ |
| mutual-settlement | M-1 и M-2 `done`: заказ → `/v1/report/info` → файл; **XLSX** (zip, `xl/workbook.xml`, 1 лист), 9–10 КБ, `Content-Type: application/octet-stream`, ~3 с |

Форма ответов, разобранная по коду Symfony, подтверждена; raw 12 МБ → 675 КБ в S3.

### Списки отправлений на реальном кабинете (21.10.2026, тестовый ключ только для чтения)

| Проверка | Результат |
|---|---|
| FBO v3, день 19.10 МСК | 2 страницы: 100 (`has_next: true`, курсор 40 символов) + 5 (`has_next: false`, `cursor: ""`) |
| FBS v4, тот же день | 200, `postings: []`, `has_next: false` (у кабинета нет FBS) |
| `limit: 101` | 400 `code 3`, `value must be inside range (0, 100]` — оба метода |
| `last_changed_status_date` без `since/to` | 400 `code 3` — оба метода |
| `last_changed_status_date` + `since/to` (FBO) | фильтр игнорируется: 2 348 = 2 348 за 30 дней |

### Классы ответов

| Ответ | Класс |
|---|---|
| 200 + ожидаемая форма | успех |
| 200 + пустой `rows` (realization) / 4xx «не готов» (realization) / `report/info` не `success` | `not_ready` |
| 429 | `rate_limit` |
| 5xx, таймаут, сеть | `transient` |
| 401, 403 | `auth` |
| 400 `{"code": 9, "message": "obsolete method cannot be used"}` | `obsolete_endpoint` |
| прочий 4xx | `invalid_request` (ошибка задачи); `message` Ozon — в тексте ошибки задачи |
| 200 + неожиданная форма, повтор курсора, лимит страниц, полная страница без признака продолжения | `invalid_response` |

## Ссылки

- [Документация Ozon Seller API](https://docs.ozon.ru/api/seller)
- [Хабр: «Ozon выключил финансовый API…»](https://habr.com/ru/articles/1086658/) — замена v3, форма by-day и postings
- [dev.ozon.ru: лимиты на операции с товарами](https://dev.ozon.ru/news/652-Novye-limity-na-operatsii-s-tovarami-v-Seller-API/)
