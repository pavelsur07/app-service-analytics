# product-research-ozon — отчёт этапа 6

Дата: 02.11.2026. Статус: завершён.
Код — [PR #313](https://github.com/pavelsur07/app-service-analytics/pull/313),
merge `dbfcf38341072022489d47d1867e2c048c1bbefa`. Symfony migration применена отдельным шагом после
штатного отказа deploy gate; выкладка и production smoke подтверждены.
[План/TDD](product-research-ozon-stage6.md),
[полные независимые заключения и контрольные суммы](product-research-ozon-stage6-review.md).

## Результат

Закрытый месяц повторно используется внутри company/source по полному semantic
ключу (категория, даты, FBS/RUB, версия raw/profile и семантика запроса).
UNIQUE cache slot и row lock координируют конкурирующие проекты; active owner
сохраняет владение, ожидающий job освобождает worker. Error owner допускает
новую попытку, restart/rotation следуют текущему snapshot owner job.
Ready история этапа 5 принимается при первом slot без повторного API.
Строгие бюджеты потребителя не обходятся сменой credentials; его deadline не
вытесняет общий готовый результат. Partial всегда новый, старая история сохранена.

Read-only progress v2 сообщает origin api/cache, collected_at и used_at.
Дата использования legacy API jobs неизвестна и остаётся null: этап 5 её не
записывал. Новые API/cache jobs сохраняют точное время, cache-null запрещён.
v1 schema/examples не менялись; admission/catalog/snapshot metadata остаются v1.

Symfony хранит provenance и версию формата проекции; same-revision legacy
обогащается один раз, stale revision/foreign source/lease не перезаписывают её.
Ready результат не регрессирует, terminal run не открывается queued/running,
повторная сверка сохраняет исходный finished_at.
`app:research:reconcile-runs COMPANY --limit=20 [--after=CURSOR]` сканирует
доставленные, включая завершённые, runs одной компании keyset-порциями.
Failed>0 даёт ненулевой exit; после исправления причины повторить полный проход.

Требуемые MonthSlice (до 24) фиксируются ORM вместе с человеческим намерением.
Статус draft/loading/loaded/partially_loaded/error вычисляется одним MVCC SQL
по требованиям и локальной проекции latest attempt каждого месяца/current source.
List/detail/mutation согласованы; новый период не попадает в уже принятый run;
неудачный partial не закрывается прежним снимком. Copy — новый draft без истории.
Категория/FBS в MVP неизменны с создания; блокировка старта атомарна.

## Проверки и приёмка

| Проверка | Результат |
|---|---|
| Финальный full fresh db-rebuild-check, TEST_WORKERS=4 | PASS: 812 unit / 899 integration / 420 functional PHP, 169 Python, Go |
| Assertions PHP | 9162 / 23583 / 4192 |
| PHP lint / PHPStan / Deptrac / structure / schema / api-types | PASS; baseline/правила не ослаблены |
| Ruff / mypy strict / import-linter | PASS |
| Реальный PHP → Python HTTP → PostgreSQL/S3 → PHP | PASS: 3 ready месяца; fixture/synthetic provider |
| Up/down/up схем, изоляция own DB/HBA и scoped raw | PASS; для отката журналов требуется backup |
| Runtime health/readiness/auth/disabled и UID10001 | PASS, dev tools отсутствуют |
| U01, I01–I05 | 14 cache integration cases + semantic key unit; параллельные claim, crash, rotation, полнота, deadline/budget, legacy reuse, partial history |
| C01, P01–P02 | v2 corpus/настоящие сериализаторы, v1 bytes unchanged; provenance, tenant/source/revision/legacy/finished/keyset |
| F01–F04 | SQL/list/detail/mutation, добавление периода во время run, failed partial/late old progress, copy draft; cache разных project ID |
| G01–G04 | Проверки, fresh rebuild, независимое ревью, CI, merge/migration/deploy/smoke подтверждены |

Финальный [CI 41388320069](https://github.com/pavelsur07/app-service-analytics/actions/runs/41388320069)
зелёный до merge: backend/frontend/services/contract-audit/E2E.
TDD: key module и adoption старого ready snapshot сначала Red → Green;
оба замечания 5B воспроизведены Red → Green, finished_at также Red → Green.
14 cache cases и полный итоговый набор прошли после исправлений.
Один промежуточный CI остановился на типе тестового массива после замены элемента;
явное array_values сохраняет list без ослабления PHPStan, итоговый CI зелёный.
Проверка формата date-time в Python corpus использует stdlib-aware checker:
опциональный RFC3339 validator в lock не установлен, зависимости не добавлялись.

Локальные логи: `var/product-research-ozon-stage6/`, финальный
`rebuild-review3-final.log`. Все fresh rebuild выполнялись в отдельном local
Compose research-stage6-rebuild: scoped тома, без host ports, основной dev сохранён.
Ревью — 3 раунда, 6 новых Codex CLI сессий; 5A принял 0, первый 5B — 2 уникальных, второй 5B — ещё 1,
третий 5B и все 5A — 0. Полные ответы, metadata и hashes сохранены в Markdown.

## Production и ограничения

[Deploy 41388926483](https://github.com/pavelsur07/app-service-analytics/actions/runs/41388926483)
сначала остановился на `doctrine:migrations:up-to-date`.
Через conwix-prod, одноразовый контейнер api нового образа и проверку
APP_SOURCE_COMMIT применена только `DoctrineMigrations\Version20261102033959`.
Up-to-date/schema validate прошли, failed deploy перезапущен штатно.

Образ `775-dbfcf38`: все 12 сервисов running, заданные healthchecks healthy;
9 research controller flags=false, direct categories/start/get=404 feature_disabled,
anonymous categories=401, seller ping версии нового образа. Команда reconciliation
зарегистрирована (--after/--limit), без запуска/изменения пользовательских данных.
Python production, egress и реальные marketplace вызовы не включались — этап 8.
Следующий этап 7 — seller UI. Новых зависимостей/Facade/брокеров/транспортов нет.

Alembic 0005 down удаляет slot/provenance, сохраняет snapshot/raw; для точной
истории нужны backups журналов. Doctrine down удаляет только новые поля.
Legacy требования восстанавливаются из periods и updated_at в Europe/Moscow;
для точного исторического partial после rollback нужен backup.
Изначальные чужие изменения в specs/docs/temp сохранены и не вошли в PR/ревью.
