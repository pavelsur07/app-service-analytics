# product-research-ozon — отчёт этапа 3

Статус: завершён 01.11.2026; код объединён, миграция Symfony применена,
production-выкладка и smoke успешны. Модуль остаётся за закрытым флагом. Основание: [план/матрица](product-research-ozon-stage3.md).

## Результат

Справочник не загружается при каждом поиске. Python хранит полный суточный
кэш в собственной PostgreSQL, Symfony — полную локальную проекцию. Поиск
seller и CategoryLookup не вызывают Python/MPStats. Namespace каждой стороны:
company_id + source_connection_id, TTL 24 часа от исходного generated_at.
Чтение/повторная синхронизация не сдвигает TTL. Неполученный или просроченный
каталог возвращает 503, подтверждённый пустой результат — 200 items=[].

Обновление выполняет `app:research:sync-categories COMPANY_UUID_V7 --once`
или отдельный фоновый цикл этой команды, без новых очередей/Messenger routing.
Компания задана явно; нет межарендаторного поиска задач или новых Facade.
До TTL команда пропускает даже вызов Python. Все страницы проверяются по
revision/generated_at, уникальности, cursor cycles, ограничению 20000 путей/
16 MiB; только после полного обхода происходит транзакционная публикация.
DBAL-пакетная запись; в Entity описана схема, Entity для списков не загружаются.
Списочные Query возвращают QueryBuilder, вызывающий слой выполняет/мапит DTO.

Поиск от трёх букв, лимит 50/200, полный путь, литеральные %/_. Create/copy
принимают только путь из актуальной проекции. Обновление/переименование не
переписывает старые проекты, а copy проверяет категорию повторно. Новые
значения лежат в Domain/ValueObject; календарь этапа 2 не менялся.

## Python и безопасность

Существующий GET /internal/v1/companies/{company_id}/categories реализован на
кэше. JSON schema/corpus v1 неизменны. Успешный ответ дополнен несекретным
X-Research-Source-Id, который PHP проверяет до публикации; ошибка конфигурации
не позволяет перепутать подключения. Signed keyset cursor связан с компанией,
подключением, revision и точным сроком до микросекунд. Смена поколения — conflict.

Lease/fencing каждой компании предотвращает двойное заполнение и публикацию
старого процесса. Отдельный provider gate общий для серверного подключения:
одновременность, RPM, Retry-After и пауза поколения после 401/403. Кэш между
компаниями не используется повторно, несмотря на общий токен.

HTTP-клиент имеет общий deadline 15 секунд (в том числе медленно поступающее
тело), timeout и лимит 16 MiB, без redirects/proxy env. Синхронный порт из
FastAPI worker-thread использует внутри async HTTP для общего deadline;
Application/DTO не получают HTTP-объекты. Денежные числа category/list читаются
через Decimal, но метрики не сохраняются: в PostgreSQL только пути/provider ID
и технические поля. Токен только в заголовке MPStats и только в Python.
Логи — безопасные статусы/идентификаторы/классы, без тела/секретов. Сбой logging
не ломает ответ. Bootstrap catalog_enabled=false; без явных секретов/поколения/
source ID/квоты source не включается.

Одна итоговая миграция в каждой собственной БД: Doctrine
Version20261101093709 и Alembic 0002_catalog_cache. Down удаляет только
производный каталог, не проекты/периоды/месячное сырьё. В проекции Symfony
TIMESTAMP(6) сохраняет точное generated_at для TTL и сравнения поколений;
публикация DBAL, FK составной по компании/подключению. Path hash ограничен
64 ASCII вместо индекса потенциально длинного Unicode-пути.

## Проверки

- Red/Green: отсутствующие классы каталога/клиента → реализация; fixture/parser/
  TTL/cursor; отдельно Retry-After от момента ответа (первый тест выявил отсчёт
  от начала запроса), telemetry failure, дедупликация страниц.
- PHP: `docker compose exec -T php-cli php vendor/bin/phpunit
  tests/Unit/ProductResearchOzon tests/Integration/ProductResearchOzon
  tests/Functional/ProductResearchOzon`: **76 тестов / 800 assertions, PASS**.
  Лог: var/product-research-ozon-stage3/php-all.log.
- PHP `make lint stan deptrac structure-check api-types-check`: PASS, без изменения
  baseline/ослабления правил. `make api-doc-export api-types` выполнен; настоящий
  JSON ответа проверяется против OpenAPI, а не только freshness генератора.
- Python `make product-research-ozon-lint product-research-ozon-vet
  product-research-ozon-architecture product-research-ozon-test`: Ruff, mypy strict,
  import-linter и полный набор unit/contract/integration: **61 тест, PASS**. Лог: var/product-research-ozon-stage3/python-generation-gates.log.
- Реальные параллельные соединения: Python ThreadPoolExecutor + PostgreSQL и
  PHP два отдельных процесса с наблюдением pg_blocking_pids. По одному owner,
  проигравший не перезаписывает данные. Source/tenant scope и lost lease проверены.
- Полный `make db-rebuild-check TEST_WORKERS=2 COMPOSE='docker compose -p
  research-stage3-rebuild -f .../docker-compose.yml -f .../rebuild.override.yml'`:
  PASS — **807 unit / 9134 assertions, 889 integration / 23318, 419 functional /
  4129**, затем Go-гейты и **61 Python-тест**. Отдельные PostgreSQL/S3/Go тома,
  сброшены только host ports отдельного проекта; рабочие dev-тома сохранены.
  Повтор выполнен после итогового исправления generation fencing и изменения
  новой Alembic-миграции до merge. Лог:
  var/product-research-ozon-stage3/rebuild-generation.log.
- Fixture: полный исходный ответ этапа 0, 6567 строк, sha256 gzip/оригинала и
  provenance в tests/fixtures/mpstats/ozon/categories/manifest.json. Новых live
  запросов не было; ID категорий в проверенном ответе отсутствует.

## Дополнительные smoke и миграционный цикл

- Runtime smoke `make product-research-ozon-smoke`: health/readiness/auth/unavailable
  PASS; runtime UID10001 и отсутствие pytest/Ruff/mypy проверены.
- Реальный HTTP PHP→Python на изолированном fixture-only сервере: **6567 путей**
  загружены/проверены/опубликованы; второй запуск команды **fresh** без повторной
  синхронизации, поиск «хидж» вернул полный целевой путь, revision=1. Проверено
  количество строк локальной проекции. Лог: var/product-research-ozon-stage3/graph-smoke.log.
  Сервер отсутствует в runtime image, принимает только собственную test-БД;
  egress не включался, live MPStats не вызывался. При первом повторе после
  rebuild тестовый вызов опередил запуск Uvicorn и получил unavailable.
  Локальный orchestration-скрипт дополнен `docker compose up --wait`; повтор
  после готовности API успешен. Продуктовый код после итогового ревью не менялся.
- На той же локальной app_test создан синтетический проект. Cache migration
  down оставил проект, up восстановил кэш, schema validation PASS; synthetic
  cleanup после up. Лог: var/product-research-ozon-stage3/migration-check.log.
  Python up/down/up/revision validation уже пройдены в полном rebuild.

## Приёмка и доставка

G01–G06 подтверждены тестами, rebuild, совместным smoke, миграционным циклом
и завершённым независимым ревью. CI итоговой ветки
[41300037206](https://github.com/pavelsur07/app-service-analytics/actions/runs/41300037206)
успешен по всему diff: backend/frontend/services, contract/audit и E2E.
Код объединён через [PR #307](https://github.com/pavelsur07/app-service-analytics/pull/307),
merge commit `da8eb2ac137536df02d18dd4e126402ef086d318`.
Штатный deploy gate остановил первую попытку на одной новой миграции.
Через `conwix-prod`, `/opt/conwix`, в новом образе сначала проверен точный
APP_SOURCE_COMMIT, затем отдельным шагом выполнена только
`doctrine:migrations:execute --up --no-interaction
DoctrineMigrations\Version20261101093709`. Результат: одна миграция / шесть SQL,
успех. `doctrine:migrations:up-to-date` и `doctrine:schema:validate` успешны;
существующие Doctrine deprecation notices не являются ошибкой проверки.
Лог: var/product-research-ozon-stage3/production-migration.log.
Перезапущен только failed deploy job, успешные проверки не заменены новым прогоном.
[Master CI / deploy 41301054303](https://github.com/pavelsur07/app-service-analytics/actions/runs/41301054303)
после отдельной миграции и повторного deploy успешен, образ **751-da8eb2a**.
Production smoke:

- APP_SOURCE_COMMIT точно совпал с `da8eb2ac137536df02d18dd4e126402ef086d318`.
- У всех семи реально разрешённых контроллеров флаг false; прямой вызов поиска
  даёт 404 feature_disabled до чтения данных, без записей.
- Публичный `/api/seller/ping`: версия 751-da8eb2a; новый categories endpoint
  без seller-сессии возвращает 401 (проверен валидный UUIDv7 маршрута).
- Все 12 контейнеров running; у заданных healthcheck состояние healthy.
  Первая проверка увидела ozon-fetcher в starting; повтор после готовности
  прошёл. Отсутствующий healthcheck у traefik/worker-freshness не объявлен healthy.

Лог: var/product-research-ozon-stage3/production-smoke-da8eb2a.log.
G01–G08 закрыты; постоянные результаты сохранены в этом отчёте и отчёте ревью.
Финальная правка только Markdown не требует нового независимого ревью
по порогу AGENTS.md; кодовый пакет и проверки не меняются.

Claude CLI отсутствует. Выполнены четыре новые независимые сессии Codex CLI
(5A/5B исходного и итогового пакетов). Первые проходы: 5A — 0; 5B — 1 уникальное
принятое замечание о защите поколения ключа. Исправлены монотонная активация,
claim/publication/release; отдельный Red воспроизведён на старом коде, Green —
на новом. Итоговые 5A и 5B complete, дополнительно принятых — 0 и 0,
отклонённых — 0. Полные JSON, hash пакетов и разбор сохранены в
[отчёте ревью](product-research-ozon-stage3-review.md), а не только в var/review.

В production поставляется Symfony за закрытым PRODUCT_RESEARCH_OZON_ENABLED.
Python production compose/включение, реальная квота/тариф/egress и отдельные S3-права
остаются в этапе 8. В этом отчёте не заявляется live-приёмка MPStats или готовность
пользовательского UI этапа 7. Dev-сеть остаётся internal без живого egress;
дополнительный HTTP smoke использует fixture-only сервер вне runtime image.
Чужие B17/B18, securety_chek, temp и удаление onboarding-спецификации исключены.
