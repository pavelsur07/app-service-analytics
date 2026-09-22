# Передача контекста: планирование продаж, этап 0.3

## Текущая точка

- Ветка: `feat/sales-planning-stage-0`.
- Draft PR: `#154`.
- Базовый commit до последней серии исправлений: `6d092be02552766c1401ef95d5362c97d32fa379`.
- Commit с текущей реализацией: `5580c29`.
- Merge и production-операции не выполнялись.

## Цель текущей доработки

Завершить безопасное применение Excel-импорта дневных планов:

- сохранить атомарность и идемпотентность apply;
- ограничить время SQL и ожидание блокировок;
- корректно работать внутри DBAL-транзакции через savepoint;
- не допустить cross-tenant чтения планов;
- не оставлять частичные строки после конкурентного конфликта.

## Файлы реализации

- `api/src/Ingestion/Application/Facade/IngestionPlanningFacade.php`
- `api/src/Ingestion/Infrastructure/Query/PlanningOutcomeQueryGuard.php`
- `api/src/Planning/Application/ApplyPlanImportAction.php`
- `api/tests/Integration/Planning/PlanImportConcurrencyTest.php`

## Принятые решения

- Собственная транзакция apply использует `statement_timeout = 25s` и `lock_timeout = 1s`.
- Внешняя DBAL-транзакция изолируется savepoint-ом; исходные GUC восстанавливаются.
- `23505`, `40001`, `40P01`, `55P03`, `57014` и optimistic lock считаются конкурентными ошибками.
- Если ошибка `flush` закрыла общий EntityManager во внешней транзакции, исключение пробрасывается владельцу для полного rollback. Возврат обычного `Conflict` в этом состоянии небезопасен.
- Нативная PDO-транзакция, открытая вне DBAL при нулевом DBAL nesting, не принимается как поддерживаемая внешняя ORM-транзакция: безопасно присоединить к ней EntityManager нельзя.
- Query guard разрешает только два внутренних значения таймаута: `5s` и `25s`.
- Импортная проверка списка SKU использует изоляцию и timeout guard, но сохраняет стандартные настройки планировщика PostgreSQL; аналитическое отключение JIT/nested loop для неё не применяется.

## Выполненные проверки

- `make lint`
- `make stan`
- `make deptrac`
- `make structure-check`
- `composer test:functional -- --filter PlanImportControllerTest` — 6 тестов, 84 утверждения.
- `composer test:integration -- --filter PlanImportConcurrencyTest` — 4 теста, 23 утверждения.
- Ранее на этом этапе успешно выполнен `make db-rebuild-check` на чистой БД.
- `make ci-local` — все проверки пройдены, включая backend 239 unit / 375 integration / 210 functional, frontend tests/builds, audit и 7 e2e.

## Актуальный остаток

1. Повторить high-risk Codex review в окружении, где CLI может создать свои PATH aliases/app-server state. Текущий CLI завершается до анализа пакета с `Read-only file system`.
2. После успешного Codex review обновить описание PR и перевести его из draft.

## Ревью

- Полный Claude-пакет `a2b75c3605fd4292eab799d4914d7e0650eef30a045e344ae079dc07b2068b2f` принял транзакционный тест и нашёл неверный адресат 25s guard; исправлено.
- Финальный focused Claude-пакет `542d1fa4fc89d8622b2bc35aa0ea5841789684195c30cb1682f4060383b0a4ea` завершён без замечаний.
- High-risk Codex не выполнен: `codex exec` завершается до чтения пакета ошибкой `failed to initialize in-process app-server client: Read-only file system`.

## Контекст для следующего ревью

Передавать только четыре файла реализации выше, этот handoff и непосредственно используемые DTO/enum. Старые заключения нужны лишь как история; актуальным считается заключение по последнему commit.
