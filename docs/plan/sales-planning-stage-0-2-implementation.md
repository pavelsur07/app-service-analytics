# План 0.2: ручной дневной план продаж

**Результат:** в отдельном модуле Planning можно прочитать, создать, изменить и снять план `(компания, кабинет, SKU, дата)` с защитой версии и аудитом. Зависимости — [0.1](sales-planning-stage-0-1-implementation.md) и I-1 для проверки SKU; остатки не участвуют.

## Карта реализации

| Место | Изменение |
| --- | --- |
| `api/src/Identity/Application/Facade/IdentityAccountScopeFacade.php` | `ownsMarketplaceAccount(companyId, accountId)` без ключей API; отдельный узкий Deptrac-слой |
| `api/src/Planning/Domain/DailyPlan.php`, `PlanChange.php`, `DailyPlanRepository.php` | ORM Entity, версия, правила количества и снятия; интерфейс записи в Domain |
| `api/src/Planning/Application/ReadDailyPlanAction.php`, `SaveDailyPlanAction.php`, `RemoveDailyPlanAction.php` | Один сценарий проверки области и SKU, optimistic lock, транзакция плана с аудитом |
| `api/src/Planning/Infrastructure/Repository/DoctrineDailyPlanRepository.php`, `DoctrinePlanChangeRepository.php`, `Query/DailyPlansQuery.php` | Человеческая запись через ORM, дневное чтение через DBAL DTO с нужными колонками |
| `api/src/Planning/Ui/Controller/` и `Ui/Response/` | `GET|PUT|DELETE /api/companies/{companyId}/planning/accounts/{accountId}/skus/{sku}/plan...`; seller OpenAPI, явные 422/404/409 |
| `apps/seller/src/api/client.ts`, `companyClient.ts` | Расширить существующий DELETE на необязательное JSON body для `expectedVersion`, сохранив старые вызовы без тела; проверить всех потребителей |
| `api/migrations/Version<UTC>.php` | Одна итоговая миграция `planning_daily_plan`, `planning_plan_change`, FK/индексы/ограничения и `down()` |
| `api/deptrac.php`, `api/bin/check-src-structure.sh`, `Makefile`, `docs/structure.md` | Зарегистрировать Planning и узкие разрешённые связи к Identity/Ingestion, не открывая чужой Infrastructure |
| `api/tests/Unit/Planning/`, `api/tests/Integration/Planning/`, `api/tests/Functional/Planning/` | Правила количества, версии, гонка, аудит, изоляция и ошибки HTTP |

## Схема и контракт

`planning_daily_plan`: PK `(company_id, marketplace_account_id, marketplace_sku, business_date)`, `quantity INTEGER NULL CHECK (quantity >= 0)`, `version BIGINT NOT NULL CHECK (version > 0)`, `created_at`, `updated_at`, `updated_by`. Строка после снятия остаётся с `quantity = NULL`; ранее не существовавший план читается как `{quantity:null, version:0}`. Ноль — установленный план. `planning_plan_change`: UUIDv7 PK, тот же составной ключ, `old_quantity`, `new_quantity`, `old_version`, `new_version`, `actor_id`, `changed_at`; только добавление. Индексы начинаются с `company_id`, `marketplace_account_id`; `business_date` — дата заказа в `Europe/Moscow`.

`GET /.../plan?from&to`: включительные дни, последние 30 по умолчанию, максимум 90; вернуть каждый день периода, включая `null` и версию. `PUT /.../plan/{date}` принимает только `{quantity: целое >=0, expectedVersion: целое >=0}`. `DELETE /.../plan/{date}` принимает `{expectedVersion}`. Создание нового дня требует `0`; восстановление снятого — сохранённую версию. Конфликт `409` возвращает актуальную версию/значение для UI. Дата/SKU/количество/период с ошибкой — `422`; чужой кабинет внутри компании — `404`; чужая компания — текущая политика членства. Проверка принадлежности кабинета через `IdentityAccountScopeFacade` проводится даже для пустого ряда, проверка известного SKU — через `IngestionPlanningFacade::knownMarketplaceSkus`.

## Последовательность и проверки

1. Сначала добавить тесты двух кабинетов с одинаковым SKU, чужой компании и изменения старой версии. Создать Entity и миграцию; запись плана и append-only аудит сделать **одной ORM-транзакцией**. Защита гонки — условное изменение по ожидаемой версии/optimistic lock и ограничение PK, не предварительный `SELECT` как единственная защита.
2. Добавить DBAL-чтение периода и HTTP-слой с OpenAPI response DTO. Проверить повторное снятие, смену `1 → 2 → NULL → 3`, ноль, Unicode SKU, ошибку даты и отсутствие SKU. При отказе прав/версии журнал не меняется. План не создаёт продажи и не влияет на чужой кабинет.
3. Обновить источник OpenAPI и сгенерировать типы `make api-doc-export api-types`; проверить `make api-types-check`. Для расширенного seller API-клиента выполнить `make front-typecheck front-lint front-test APP=seller`, включая существующее удаление подключения без тела. Выполнить `make db-rebuild-check`, `make lint stan deptrac structure-check`, затронутые backend-тесты, `make audit`; коду/схеме/изоляции нужны Claude и оба прохода Codex.

**Готовность:** ручная правка и снятие воспроизводимы по журналу, устаревшая вкладка не перезаписывает план, сохранённый ноль не теряется.
