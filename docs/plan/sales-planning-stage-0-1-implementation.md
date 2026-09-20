# План задачи 0.1: контракты и архитектурные решения Planning

**Цель:** зафиксировать границы модулей, расчётные определения, интерфейсы и схему до начала кода Этапа 0.

**Основание:** [Этап 0](sales-planning-stage-0.md), [программа Этапа 00](sales-planning-stage-00.md), ADR-006/008/011/016/019/020 и [проверка источника остатков](../task/ozon-fbo-stock-source-research.md). Утверждённые пользователем решения от 2026-09-20: отдельная методика выкупа Planning, собственные таблицы, существующая очередь и OpenSpout для будущего импорта.

**Граница:** задача 0.1 меняет документацию и ADR. Создание PHP-классов, миграций, API, UI и установка OpenSpout относятся к следующим задачам. Код следующих задач начинается с чтения этого контракта и проверки актуального состояния репозитория.

## Карта файлов и ответственность

| Файл | Действие в 0.1 | Ответственность |
| --- | --- | --- |
| `docs/adr/0024-sales-planning-and-inventory-boundaries.md` | создать | Единственный принятый ADR для границ, методики, качества данных, ключей и связей Deptrac |
| `docs/plan/sales-planning-stage-0.md` | сверить и при расхождении исправить | Пользовательские показатели и критерии результата |
| `docs/plan/sales-planning-stage-00.md` | сверить и при расхождении исправить | Порядок I-1, I-2, INV-1, 0.2–0.8 и гейты |
| `docs/structure.md` | поправить карту модулей после принятия ADR | Добавить Planning и Inventory; уточнить устаревшую фразу о PriceMonitoring через ADR-016 |
| `docs/adr/README.md` | добавить ссылку | Навигация по ADR |
| `CLAUDE.md` | добавить ADR в перечень решений | Навигация для следующих задач |
| `docs/task/ozon-fbo-stock-source-research.md` | использовать как вход | Учесть подтверждённый разрез по складам и не объявлять непроверенную формулу остатка фактом |

Файлы будущих изменений, чьи **контракты** фиксирует 0.1: `api/src/Ingestion/Application/Facade/IngestionFacade.php`, DTO рядом с ним, отдельный `IdentityAccountScopeFacade` без доступа к ключам API, `api/src/Inventory/Application/Facade/InventoryFacade.php`, `api/src/Planning/`, `api/deptrac.php`, миграции в `api/migrations/`, seller API и `apps/seller/src/features/planning/`. Физически эти файлы сейчас не меняются.

## Шаги документационного изменения

- [x] Прочитать фактические классы `BuyoutOutcomeQuery`, `BuyoutForecastQuery`, `FetchOzonPostingsHandler`, `IngestionFacade`, Identity Facade и действующие границы `api/deptrac.php`. В ADR перечислить, какие данные доступны сейчас, какие появятся в I-1 и I-2.
- [x] Записать таблицу состояний единицы: `D` и `R` — подтверждённый выкуп; `T1`, `T2`, `P` — известный невыкуп; `NULL` — неизвестный исход. При частичном заказе сумма количеств всех состояний равна заказанному. Исход `R` остаётся выкупом и никогда повторно не попадает в открытый прогноз.
- [x] Записать три разные даты: дата создания заказа в `Europe/Moscow` для строки плана; дата события Ozon, если доказана источником; дата первого регулярного наблюдения подтверждения для оценки скорости. Backfill старого исхода не переносит его в день импортирования. В ADR привести пример заказа 10 сентября и подтверждения 14 сентября.
- [x] Зафиксировать интерфейсы из раздела ниже как **проектируемые сигнатуры**. Читать данные другого модуля только через Facade; запретить прямой SQL cross-module. Каждое предметное чтение принимает `companyId` первым аргументом, затем обязательный `marketplaceAccountId` и ограничение набора SKU/периода.
- [x] Зафиксировать таблицы, естественные ключи и владельцев из раздела ниже. Для I-2 учесть доказанный `warehouse_id` одного кандидата, но оставить ключ строки, состав DTO и формулу доступного остатка зависимыми от сверки источника и отдельного ADR. Для каждого человеческого изменения сохранить версию либо append-only след с актором и временем согласно ADR-008/011.
- [x] Зафиксировать seller API и коды состояний из раздела ниже. Включить `companyId` и `accountId` в ключ кэша; один SKU в разных кабинетах остаётся двумя независимыми рядами.
- [x] В ADR описать публикацию согласованного расчётного снимка: входной отпечаток, версии источников, версии настроек и алгоритма, полный/неполный срез; при ошибке не смешивать новые исходные строки со старым сигналом.
- [x] Добавить новые направленные связи в **план** `api/deptrac.php`: `Planning → IngestionPlanningFacade`, `Planning → InventoryFacade`, `Inventory → IngestionStockSourceFacade`, `Planning → IdentityAccountScopeFacade`. Новые Facade получают отдельные узкие слои Deptrac и исключаются из широких слоёв; `IdentityAccountScopeFacade` не открывает ключи API, а Planning не получает исходный снимок остатков. Фактический Deptrac меняется вместе с первым кодом нового модуля. Обратные связи и импорт Entity запрещены.
- [x] Сверить ADR с Этапом 0 и Этапом 00 по всем основным показателям и состояниям. Исправить противоречия в документах 0.1, а новый предметный выбор вынести в список согласования по правилам `CLAUDE.md`.
- [x] Выполнить документальные проверки: `git diff --check`, проверить только свои файлы через `git status --short`, найти все ссылки на ADR и термины через `rg`. Зафиксировать проверку и коммит документации в ветке задачи.

## Межмодульные интерфейсы, которые должен закрепить ADR

Это сигнатуры **будущего** кода, с которыми согласуются I-1, I-2, INV-1 и Planning. Типы readonly DTO создаются в модуле владельца данных. `DateTimeImmutable` для `from`/`to` означает включительный календарный период после приведения к `Europe/Moscow`, не произвольный UTC-интервал. Выборка SKU пакетная; `[]` не означает все SKU кабинета. Классы `IngestionPlanningFacade` и `IngestionStockSourceFacade` получают разные узкие слои Deptrac; существующий `IngestionFacade` остаётся для PriceMonitoring.

```php
IngestionPlanningFacade::planningOrderCohorts(
    string $companyId,
    string $marketplaceAccountId,
    array $marketplaceSkus,
    DateTimeImmutable $from,
    DateTimeImmutable $to,
    int $limit,
    ?string $cursor,
): PlanningOrderCohortPage;

IngestionPlanningFacade::planningResolutionTotals(
    string $companyId,
    string $marketplaceAccountId,
    array $marketplaceSkus,
    DateTimeImmutable $from,
    DateTimeImmutable $to,
    PlanningObservationDateAxis $dateAxis,
): PlanningResolutionTotals;

IngestionPlanningFacade::planningResolutionObservations(
    string $companyId,
    string $marketplaceAccountId,
    array $marketplaceSkus,
    DateTimeImmutable $from,
    DateTimeImmutable $to,
    PlanningObservationDateAxis $dateAxis,
    int $limit,
    ?string $cursor,
): PlanningResolutionObservationPage;

IngestionPlanningFacade::knownMarketplaceSkus(
    string $companyId,
    string $marketplaceAccountId,
    array $marketplaceSkus,
): array; // list<string>, только существующие SKU этого кабинета

IngestionPlanningFacade::searchMarketplaceSkus(
    string $companyId,
    string $marketplaceAccountId,
    string $search,
    int $limit,
    ?string $cursor,
): MarketplaceSkuPage; // SKU, артикул, подпись, nextCursor; keyset и лимит

IdentityAccountScopeFacade::ownsMarketplaceAccount(
    string $companyId,
    string $marketplaceAccountId,
): bool; // отдельный узкий класс без учётных данных; проверка пустого плана

IngestionStockSourceFacade::fboStockSourceAt(
    string $companyId,
    string $marketplaceAccountId,
    array $marketplaceSkus,
    DateTimeImmutable $at,
    string $sourceSnapshotKey,
    int $limit,
    ?string $cursor,
): FboStockSourcePage;

IngestionStockSourceFacade::stockRelevantSkusAt(
    string $companyId,
    string $marketplaceAccountId,
    DateTimeImmutable $at,
    int $limit,
    ?string $cursor,
): FboStockSkuPage;

InventoryFacade::availableStocksAt(
    string $companyId,
    string $marketplaceAccountId,
    array $marketplaceSkus,
    DateTimeImmutable $at,
): AvailableStockSet;
```

`PlanningOrderCohortPage` содержит строки `(sku, orderBusinessDate, ordered, bought, terminalNoBuy, openEligible, unknown)` с целыми количествами, признаком полноты периода, временем последнего полного опроса, ссылками на источники и версией выборки. Дневные строки читаются keyset-страницами по SKU и дате; `nextCursor` закрепляет единую версию источника. Для обоих контрактов наблюдений ось `PlanningObservationDateAxis::FIRST_KNOWN_OUTCOME` фильтрует по `firstKnownOutcomeAt` для процента выкупа, ось `FIRST_REGULAR_OBSERVATION` — по `firstRegularlyObservedAt` для скорости; неизвестные даты не попадают в окно. `PlanningResolutionTotals` вычисляет `SUM(quantity)` по исходам D/R/T1/T2/P **в PostgreSQL** и отдаёт не более одной агрегированной строки на SKU, полноту календарных дней и версию источника; запрос принимает не более 200 SKU. Planning использует этот агрегат для коэффициента и скорости, не суммирует построчные аллокации в PHP. `PlanningResolutionObservationPage` служит проверке происхождения: непрозрачный keyset `nextCursor` закрепляет ось и версию выборки. Строка количественной аллокации содержит `(sku, sourceRowId, allocationKey, outcome, quantity, firstKnownOutcomeAt?, firstRegularlyObservedAt?, sourceEventAt?, backfill)`; `outcome` различает D/R/T1/T2/P, а стабильный `allocationKey` не даёт задвоить частичный исход между страницами. Заказы с неизвестным первым регулярным подтверждением не превращаются в сегодняшнюю скорость. Все ответы Planning из одного расчёта сверяются по общей версии источника одного кабинета, включая отдельные пакеты более 200 SKU; при изменении версии обход запускается заново и смешанный результат не публикуется. `MarketplaceSkuPage` содержит SKU, артикул продавца, подпись и следующий курсор. `FboStockSkuPage` перечисляет **сохранённый на момент полного среза** состав SKU кабинета для обработки Inventory вместе с ключом среза, признаками охвата и keyset-курсором, закреплённым за этим срезом. Этот состав фиксируется при загрузке из карточек и строк источника, поэтому сегодняшний каталог не подменяет исторический. Отсутствие SKU в срезе означает подтверждённый ноль только при доказанной семантике источника, иначе `missing`. `FboStockSourcePage` читает нормализованные строки **строго по переданному** `sourceSnapshotKey`, а не по новому последнему срезу, keyset-страницами с закреплённым ключом, временем, raw-ссылками и `nextCursor`; ключ должен принадлежать этому кабинету и полному срезу не позже `at`, иначе запрос отклоняется. При отсутствии полного среза `stockRelevantSkusAt` возвращает `missing`, а не сегодняшний остаток. Для всех пяти страниц (`PlanningOrderCohortPage`, `PlanningResolutionObservationPage`, `MarketplaceSkuPage`, `FboStockSkuPage`, `FboStockSourcePage`) лимит по умолчанию 50, максимум 200, превышение — `422`. Пакетные методы без постраничного списка также принимают не более 200 SKU. `AvailableStockSet` выбирает последний доступный снимок не позже `at`; точные поля количества источника и складской разрез определит I-2. Он различает `known_zero`, `known_positive`, `missing`, `incomplete`, `stale` и не подставляет ноль при отсутствии строки.

`FboStockSkuPage` возвращает `snapshotState: complete|missing`, `sourceSnapshotKey: ?string`, `items` и `nextCursor`. До первого полного среза не позже `at`: `missing`, ключ `null`, пустые список и cursor. Полный срез с нулём SKU: `complete`, непустой ключ, пустой список. По невалидному переданному ключу `fboStockSourceAt` выдаёт ошибку, а не `missing`.

Метод `fboStockSourceAt` появляется **после** выбора метода Ozon по I-2. Реальная фикстура нового метода доказала `warehouse_id` и курсорную пагинацию **только для этого кандидата**; `/v3/product/info/list` не содержит `warehouse_id`. Выбор метода, конкретный DTO строки, ключ и формула доступного количества фиксируются новым ADR, уточняющим ADR-024, после сверки с кабинетом и до миграции.

## Владение данными и ключи

| Владелец | Будущая таблица | Ключ и обязательные свойства | Задача миграции |
| --- | --- | --- | --- |
| Planning | `planning_daily_plan` | `(company_id, marketplace_account_id, marketplace_sku, business_date)` уникален; строка не удаляется, `quantity >= 0` или `NULL` после снятия, версия монотонна, автор и время | 0.2 |
| Planning | `planning_plan_change` | append-only ID; ключ плана, старое/новое количество, версия, автор, время | 0.2 |
| Planning | `planning_import_preview` | ID, компания/кабинет, автор, срок жизни, отпечаток файла и версии плана; уникальный токен подтверждения | 0.3 |
| Planning | `planning_settings_version` | уникальны компания/кабинет/версия; действующие с момента параметры, предшествующая версия, актор и время; строки не правятся и не удаляются | 0.4 |
| Planning | `planning_calculation_snapshot` | естественный PK `(company_id, marketplace_account_id, marketplace_sku, business_date, algorithm_version, input_fingerprint)`; параметры, все количества, качество, ссылки на источники и время | 0.5 |
| Planning | `planning_calculation_current` | PK компании/кабинета/SKU/даты; указывает на составной ключ опубликованного снимка, обновляется атомарно со снимком | 0.5 |
| Ingestion | источник заказов и исходов | существующие `sales_fact`, статусы и `buyout_outcome`; новые таблицы только при доказанном пробеле полноты | I-1 |
| Ingestion | полный источниковый снимок FBO | компания/кабинет, завершение загрузки, время, raw-ссылки; форма строк и ключ фиксируются после выбора метода новым ADR | I-2 |
| Inventory | `inventory_available_stock_snapshot` | естественный PK `(company_id, marketplace_account_id, marketplace_sku, source_snapshot_key, formula_version)`; количество, время и качество; повтор полного наблюдения идемпотентен | INV-1 |

Во всех растущих таблицах `company_id` первый в ключах доступа. `input_fingerprint` — детерминированный отпечаток входных версий, даты оценки `asOfBusinessDate` в `Europe/Moscow` (отдельной от даты заказа) и точных границ окон коэффициента и скорости; выходы расчёта в него не входят. Поэтому смена дня создаёт новый снимок и без нового заказа. `source_snapshot_key` — стабильный ключ полного наблюдения источника, а не новый UUID каждой строки. Его конкретную форму фиксирует новый ADR по I-2 до миграций. Нативный PostgreSQL `uuid` для прочих записей создаётся в приложении. Редактируемые планы пишутся ORM, факты через DBAL с естественным PK и upsert. Миграция отсутствует в 0.1; следующие задачи создают по одной итоговой миграции при изменении схемы. Конкретные колонки `Ingestion` и `Inventory` по остаткам уточняются только после выбора источника.

## Seller API и состояния качества

Префикс: `/api/companies/{companyId}/planning`; `accountId` обязателен в пути и проверяется внутри компании. Контракт Этапа 00 переносится в ADR без переименования:

| Метод и путь после префикса | Назначение |
| --- | --- |
| `GET /accounts/{accountId}/skus?search&limit&cursor` | Ограниченный поиск SKU кабинета через `searchMarketplaceSkus` |
| `GET /accounts/{accountId}/skus/{sku}/plan?from&to` | Дневной план и версии; у никогда не заданного дня версия `0`, у снятого — последняя версия |
| `PUT /accounts/{accountId}/skus/{sku}/plan/{date}` | `{quantity, expectedVersion}`; `0` только для ещё не созданного дня, после снятия требуется версия tombstone |
| `DELETE /accounts/{accountId}/skus/{sku}/plan/{date}` | `{expectedVersion}`; `quantity = NULL`, версия увеличена, запись в журнал |
| `POST /accounts/{accountId}/imports/preview` | Проверка XLSX без сохранения плана |
| `POST /accounts/{accountId}/imports/{previewId}/apply` | Атомарное подтверждение просмотренного импорта |
| `GET /accounts/{accountId}/skus/{sku}/daily?from&to` | Текущий дневной ряд и качество |
| `GET /accounts/{accountId}/skus/{sku}/calculations?cursor&limit` | История с keyset-пагинацией |

Для дневного чтения — последние 30 дней по умолчанию, максимум 90 включительных дней. Неподходящий SKU, дата, количество или период — `422`; кабинет вне компании — `404`; чужая компания — действующий ответ проверки членства; конфликт версии — `409`. SKU кодируется как один сегмент URL. Ответы типизируются из генерируемой схемы API.

Цвет сигнала определяется только для заданного положительного плана, наступившей даты и полных свежих данных. Блокирующие цвет состояния: `plan_missing`, `plan_zero`, `future_date`, `source_incomplete`, `source_stale`, `outcome_unknown`. `insufficient_buyout_sample` — отдельный признак качества коэффициента: при резервных 80% расчёт и цвет доступны, но источник коэффициента и малая выборка видны пользователю. `insufficient_velocity_history` блокирует только скорость и дни покрытия. Текущие настройки — 30 календарных дней коэффициента, минимум 30 завершённых единиц, резерв 80%, 14 дней скорости, зоны 95%/80%, обновление 15 минут и устаревание 60 минут; это значения версионируемой конфигурации.

## Проверка качества самого плана 0.1

Перед закрытием сверить хотя бы эти контрольные примеры с ADR и будущими задачами:

1. SKU `A` в кабинете 1 и такой же SKU в кабинете 2 не делят план, коэффициент, остаток, снимок и ключ кэша.
2. 10 заказанных единиц: 4 D, 2 T1, 4 открытых; `ordered = 10`, `bought = 4`, `terminalNoBuy = 2`, `openEligible = 4`, прогноз на открытые при 75% равен 3, ожидаемый итог 7.
3. Возврат после D переводит исход в R для диагностики, сохраняет 1 в состоявшемся выкупе и 0 в открытом прогнозе.
4. Подтверждение старого заказа после backfill не увеличивает скорость за сегодняшний день.
5. Ошибка последней страницы остатков сохраняет прежний снимок с отметкой просрочки; отсутствие SKU не превращается в подтверждённый ноль.
6. План версии 1 снят и восстановлен: старый `expectedVersion: 1` получает `409`; устаревший preview импорта также не применяется.

**Готовность 0.1:** ADR принят, термины и сигнатуры согласованы с Этапом 0/00, в документах нет необоснованного определения доступного остатка, `git diff --check` успешен. Хотя изменение состоит только из `.md`, принятие архитектурного ADR проходит ревью по его предмету согласно `CLAUDE.md` → «Порог внешнего ревью».
