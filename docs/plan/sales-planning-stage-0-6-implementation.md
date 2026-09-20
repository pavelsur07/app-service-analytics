# План 0.6: скорость фактических продаж и дни покрытия

**Результат:** Planning дополняет расчёт 0.5 скоростью подтверждённого выкупа и оценкой дней доступного остатка. Зависимости — [I-1](sales-planning-i-1-implementation.md), [INV-1](sales-planning-inv-1-implementation.md), [0.5](sales-planning-stage-0-5-implementation.md). Товар в пути не входит в покрытие.

## Карта реализации

| Место | Изменение |
| --- | --- |
| `api/src/Planning/Domain/SalesVelocity.php`, `StockCover.php` | Чистые правила 14 полных дней, нулей и неизвестных значений; decimal без `float` |
| `api/src/Planning/Application/RecalculatePlanningSkuAction.php` и DTO снимка | `planningResolutionTotals` с осью `FIRST_REGULAR_OBSERVATION` плюс `InventoryFacade::availableStocksAt`; новый `algorithm_version` |
| `api/src/Planning/Infrastructure/Repository/CalculationSnapshotRepository.php`, `Query/PlanningCalculationsQuery.php` | Заполнение подготовленных в 0.5 полей без изменения старых snapshot |
| `api/src/Planning/Ui/Response/` и `ShowPlanningDailyController.php` | Количество остатка, качество/время, скорость и дни покрытия с отдельными причинами отсутствия |
| `api/tests/Unit/Planning/`, `api/tests/Integration/Planning/`, `api/tests/Functional/Planning/` | Нулевые дни, backfill, as-of остатка, качество и изоляция |

## Расчёт

Окно скорости — 14 **завершённых** календарных дней до `asOfBusinessDate` в `Europe/Moscow`. `planningResolutionTotals` считает D+R по `firstRegularlyObservedAt`; `R` после D не создаёт второй выкуп. В знаменатель всегда 14 при полном окне, включая дни без покупок. Если первое регулярное наблюдение неизвестно или хотя бы один день/источник не подтверждён полным опросом, скорость имеет состояние `insufficient_data`/`incomplete`, а не искусственный ноль. Backfill не добавляет вчерашнюю продажу в сегодняшний день. Показать, что это оценка по дате **наблюдения**, если API не доказывает дату самого события.

`availableStocksAt(companyId, accountId, [sku], at)` выбирает последний снимок Inventory не позже момента оценки. При `known_positive` и положительной скорости считать `coverDays = stock / unitsPerDay` с фиксированной decimal-точностью и явным округлением для показа. При `known_zero` вернуть `0` и «Нет доступного товара», даже если скорость ноль. При положительном остатке и подтверждённо нулевой скорости вернуть `null` и «Нет продаж за период». `missing/incomplete/stale` и неполное окно дают `null` с конкретной причиной; прошлый полный остаток не копируется в сегодняшний день как новый. Остаток и скорость не меняют коэффициент выкупа или факт.

## Публикация и проверки

С новым `algorithm_version` включить в fingerprint точные 14 дней, версию наблюдений, `sourceSnapshotKey` и `formulaVersion` Inventory. Сохранить числа, состояния качества и ссылки на источники в новом append-only snapshot; `current` обновить транзакцией 0.5. Старый снимок без остатка остаётся доступен и показывает «данных не было», а не текущий остаток. Если колонки 0.5 уже предусмотрены, отдельная миграция 0.6 не нужна; при доказанном новом поле допускается одна итоговая миграция задачи.

Тестировать полный нулевой день, неполный 14-дневный период, 14 полных нулей, backfill, D→R, нулевой и неизвестный остаток, поздний полный снимок, несовпадающие моменты источников и разные кабинеты. Выполнить `make lint stan deptrac structure-check`, затронутые тесты, API-генерацию/типы, `make audit`; если появится миграция — `make db-rebuild-check`. Коду/расчёту нужно обязательное ревью по `AGENTS.md`.

**Готовность:** у скорости и покрытия есть календарное окно, время, источник и качество; деление на ноль исключено.
