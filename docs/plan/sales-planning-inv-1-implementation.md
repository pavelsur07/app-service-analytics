# План INV-1: доступный остаток в отдельном модуле Inventory

**Результат:** Inventory переводит доказанный полный снимок I-2 в доступный к продаже остаток по SKU/кабинету и сохраняет историю. Нужны [I-2](sales-planning-i-2-implementation.md), новый ADR о выбранном источнике и [0.1](sales-planning-stage-0-1-implementation.md). Товар в пути относится к INV-2 после Этапа 0.

## Карта реализации

| Место | Изменение |
| --- | --- |
| `api/src/Inventory/Domain/AvailableStockSnapshot.php` и типы качества | Entity схемы, неотрицательные целые количества и состояния `known_zero`, `known_positive`, `missing`, `incomplete`, `stale` |
| `api/src/Inventory/Application/Facade/InventoryFacade.php`, `AvailableStockSet.php` | `availableStocksAt(companyId, accountId, skus, at)` из ADR-024; readonly DTO с количеством, качеством, временем, `sourceSnapshotKey`, версией формулы |
| `api/src/Inventory/Application/Message/RebuildAvailableStocksMessage.php`, `MessageHandler/RebuildAvailableStocksHandler.php` | Идемпотентная обработка полного ключа источника; сообщение в существующем `async_calc`, не обратный вызов Ingestion → Inventory |
| `api/src/Inventory/Application/ScheduleAvailableStocksAction.php`, `Ui/Command/ScheduleAvailableStocksCommand.php`, `docker-compose.prod.yml` | Собственный цикл по шаблону существующего планировщика; перечисление активных аккаунтов через узкий `IdentityScheduleFacade`, затем поиск нового полного ключа у Ingestion |
| `api/src/Inventory/Infrastructure/Repository/AvailableStockSnapshotRepository.php`, `Query/AvailableStocksAtQuery.php` | DBAL upsert по естественному ключу, выбор последнего снимка не позже `at`, SQL-фильтр компании и кабинета |
| `api/migrations/Version<UTC>.php` | Одна итоговая миграция `inventory_available_stock_snapshot` и индексов; ORM mapping в Entity; рабочий `down()`/восстановление |
| `api/deptrac.php`, `api/bin/check-src-structure.sh`, `Makefile`, `docs/structure.md` | Ввести Inventory; общий Application получает только `IngestionStockSourceFacade`, а отдельный `InventoryOperationalAction` — ещё `IdentityScheduleFacade` по ADR-025; UI и обычные действия не получают межарендаторный доступ |
| `api/tests/Unit/Inventory/`, `api/tests/Integration/Inventory/` | Формула, качество, as-of, повторы, конкурентность, изоляция |

## Данные и шаги

1. Взять **ровно** полный `sourceSnapshotKey` через `stockRelevantSkusAt`; обойти сохранённый состав keyset-страницами и для пакетных SKU обойти **все** страницы строк `fboStockSourceAt` с тем же ключом и временем. Если ключ сменился в другом запуске, текущий обход продолжает закреплённую версию; если страница/состав повреждены, никакой доступный остаток по этой версии не публикуется.
2. Агрегировать только разрешённые ADR-025 FBO-склады и поля. Использовать целые числа и доказанную формулу. Для SKU без строки выдавать подтверждённый ноль **только** если ADR-025 доказал полноту и смысл отсутствия; иначе `missing`. Отрицательный результат или несогласованные строки означают ошибку снимка, не ноль. Не прибавлять резерв, товары в пути, возвраты до приёмки и FBS.
3. Сохранять append-only `(company_id, marketplace_account_id, marketplace_sku, source_snapshot_key, formula_version)` с суммой, складскими исходными ссылками/хэшем, временем источника и расчёта, состоянием качества. По каждому SKU существует максимум одна строка данного ключа/формулы; повтор сообщения конфликтует по БД и даёт тот же результат. Версию формулы менять при исправлении определения, старые строки не переписывать.
4. `availableStocksAt` принимает максимум 200 SKU; `[]` возвращает пустое множество. Для `at` выбирает последнюю **опубликованную полную** версию не позже момента и последний успешный момент её проверки. `missing` — среза либо достоверной строки нет; `incomplete` — последняя попытка загрузки провалилась и прежний срез не может выдаваться за текущий; `stale` — последний успешный опрос старше настроенного порога. Состояние последней попытки и проверка as-of приходят через DTO I-2, без прямого SQL в Ingestion. В ответе хранить и возраст источника, и время расчёта. Деньги и прогноз сюда не входят.
5. Планировщик Inventory через отдельный узкий операционный слой читает активные `(companyId, accountId)` из `IdentityScheduleFacade` и для каждого через `IngestionStockSourceFacade` ищет новый полный ключ, отправляя сообщение с этим ключом. Этот точечный дополнительный доступ закрепить новым ADR I-2 **до** изменения Deptrac; без него схема планировщика противоречила бы ADR-024. Существующий `IdentityScheduleFacade` имеет защитный потолок 200 аккаунтов и громко падает при превышении; запуск не должен тихо терять часть кабинетов. Никакого импорта классов Inventory в Ingestion. Planning позже читает только `InventoryFacade`.

## Проверки и завершение

Примеры с резервом и несколькими складами сверить с контрольным остатком ADR-025. Тестировать полный нулевой срез, отсутствующий SKU, неполную страницу, старый/новый `as-of`, просрочку, повтор и гонку двух воркеров, одинаковый SKU разных кабинетов, изменение версии формулы. Выполнить `make db-rebuild-check`, `make lint stan deptrac structure-check`, затронутые backend-тесты и `make audit`; схеме и изоляции нужны Claude и оба прохода Codex.

**Готовность:** доступное количество имеет доказанную формулу, исходный ключ и качество; вчерашний остаток не выдаётся за сегодняшний; данные кабинетов не пересекаются.
