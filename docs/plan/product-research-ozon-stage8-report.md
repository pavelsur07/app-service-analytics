# product-research-ozon — подготовка этапа 8

Дата: 02.11.2026. **Этап8 целиком не закрыт; production research выключен.**
[План и открытые критерии](product-research-ozon-stage8.md).
Код подготовки — [PR #317](https://github.com/pavelsur07/app-service-analytics/pull/317).
[Полные независимые заключения](product-research-ozon-stage8-review.md).

## Что реализовано

PHP company allowlist закрывает research availability и все data routes при
пустом/невалидном списке. Предшествующие отказы auth/membership сохраняются;
общий false флаг продолжает закрывать все компании. Python RunService сохраняет
свой отдельный allowlist. Контракты и payload frontend не менялись.

Diagnostics CLI возвращает ограниченный sample последних <=200 jobs одной
company/source: ready API/cache, очереди, ошибки, overdue и возраст активных.
truncated виден явно; это не полный счётчик API/quota или физического объёма S3.
Snapshot audit проверяет scope, manifest, страницы, SHA-256, размеры и totals;
сырьё читается постранично, без MPStats, SQL в цикле, записи или удаления.
Ошибки конфигурации/SDK не выводят токены/DSN/тела.

Recovery drill создаёт отдельный случайный local Compose project с source и
restore PostgreSQL/S3, делает501 synthetic rows/2pages и cache reuse, настоящий
pg_dump/pg_restore и exact audit с отказом чужой компании. Проверяется namespace
всех volume names, включая Go cache; основные volumes не используются.
Это доказательство local service recovery, а не production backup/RPO/RTO
и не согласованного восстановления обеих БД Conwix.

Fresh rebuild нашёл прежнюю гонку admission: INSERT с конфликтом только по
(company_id,run_id) иногда получал unique violation по второму индексу
(company_id,source_id,run_id). Теперь DO NOTHING охватывает оба ограничения;
после конфликта проверяется прежний digest. Разные параметры не принимаются.
Сценарии2/8 одновременных отправителей сохраняют один run и набор месячных jobs.

Standalone production-заготовка имеет0 сервисов без profile research; с явным
профилем описывает API/worker без host ports, с UID10001/read-only/cap_drop,
healthchecks и internal+egress. Runtime image публикуется только master CI с
immutable version/source SHA. Основной deploy этот стек не включает/не обновляет;
интеграция release/migrations/alert gates остаётся условием до активации.
PHP production topology получает только switch/allowlist/source/service token;
MPStats и отдельные S3 реквизиты туда не копируются. Новых зависимостей,
миграций, межмодульных Facade, API schemas и очередей нет.

## Проверки

| Проверка | Результат |
|---|---|
| Затронутые PHP unit/integration/functional |98 tests /1261 assertions PASS |
| PHP lint/PHPStan/Deptrac/structure/API freshness |PASS, baseline/правила не ослаблены |
| Ruff/mypy strict/import-linter/runtime smoke |PASS |
| Полный Python после исправления admission |173 tests PASS |
| Final isolated fresh db-rebuild-check |813 unit/901 integration/423 functional PHP,173 Python,Go PASS |
| Assertions PHP fresh |9170/23668/4257 |
| PHP→Python HTTP→PostgreSQL/S3→PHP |3 ready месяца PASS, fixture source |
| Реальный local pg_dump/pg_restore + raw audit |PASS, source/target изолированы;501rows/2pages/cache/foreign denial |
| Offline captured CSV/JSON |2tests PASS; февраль62148SKU/1080978sales/596919032RUB; январь50702SKU |
| Benchmark102 страниц |8966590 bytes Python heap peak при133878270 bytes raw;32s collection local |
| E2E seller/admin |12scenarios +extension real Chrome storage PASS |
| Topology/classifier |PASS, default0services; opt-in nonroot/readonly/no ports |

Benchmark измеряет Python heap, не RSS/всю память контейнера, не latency MPStats
и не максимальную категорию. Граница теста запрещает удержание всего raw месяца;
бюджеты production и квота по этим числам не придумываются.

TDD: новый rollout и ops audit сначала Red; corrupt metadata отвергается.
Конкурентный admission воспроизведён Red в fresh rebuild, ошибка вторичного
unique подтверждена PostgreSQL log, исправление/2-8senders/final rebuild Green.
Recovery сначала выявил ожидаемый запрет local socket для service role (исправлено
TCP), затем readiness S3 (добавлено ограниченное ожидание), итоговый drill Green.
Линтеры не ослаблялись. Унаследованное предупреждение Starlette/httpx зафиксировано,
новую зависимость ради него не добавляли.

В первом запуске fresh rebuild env COMPOSE был перекрыт присваиванием Makefile:
пересоздан основной **локальный** dev. Ошибка сообщена пользователю. Dev test
seed восстановлен и E2E прошёл; production не затронут. Финальный прогон передал
make COMPOSE=... явно, проверил dry-run down -v target, все volume namespaces
и отсутствие host ports; прошёл в отдельном research-stage8-rebuild.
Первый benchmark, пересёкшийся с reset, не засчитан; повтор32s/8966590bytes PASS.

Локальные материалы: var/product-research-ozon-stage8/: php-allowlist.log,
php-gates-second.log, python-gates-final.log, fresh-rebuild-final.log,
recovery-final.log, resources-102-pages-fixed.log, offline-acceptance.log,
e2e-final.log, production-config-check.log. Неуспешные прогоны сохранены отдельно.

## Независимое ревью и CI

Claude CLI отсутствует; по решению владельца выполнены 2 новые независимые
Codex CLI5A/5B с read-only sandbox и полным пакетом 29 файлов.
Оба complete, замечаний 0; уникальных принятых5A0/5B0, отклонённых 0.
Авторская находка admission отдельно от внешних замечаний.
Полные ответы, metadata, stderr model/effort и hashes сохранены в архиве.

Первый CI backend/E2E остановился до тестов: Docker Hub502 при pull образов.
Frontend/services/contract-audit прошли; повторяются только failed jobs,
без изменения кода/версий/требований. [Итоговый CI 41409789938](https://github.com/pavelsur07/app-service-analytics/actions/runs/41409789938)
полностью зелёный для fdc3365907556caf8007481b1bc024dc5299bfe3 до merge;
backend/frontend/services/contract-audit/E2E PASS после повторного запуска
только failed jobs из-за Docker Hub502.

## Что остаётся до live-включения

- Pilot компания подтверждена владельцем, allowlist сохранён приватно на сервере
  при выключенном общем флаге; реальные идентификатор/название в Git не сохранены.
  Остаётся условие ADR-021: у компании должно быть активное подключение Ozon.
- Действующий MPStats secret, тариф/RPM/доступный API объём; старый GitHub test
  secret сам по себе не подтверждает валидность и квоту.
- Отдельные S3 credentials на research prefix, собственные DB role/HBA,
  directional secrets, согласованные rows/pages/bytes/storage budget.
- Production lifecycle/согласованный внешний backup и restore обеих БД,
  проверенный канал оповещений и обновление отдельного стека/миграционный gate.
- Операторская новая сверка MPStats/CSV и production pilot start→ready→cache,
  отказ остальным компаниям и проверенное выключение.

Текущие изменения не заменяют перечисленные гейты фикстурами. Автономных живых
MPStats/Ozon запросов и включения Python production не было. Этап8 остаётся
открытым; следующего аналитического этапа эти изменения не начинают.

## Поставка подготовки

Merge `48fcb8dee5056e07aefe6797c1c341e715942618`,
[master build/deploy 41411507612](https://github.com/pavelsur07/app-service-analytics/actions/runs/41411507612)
зелёный. Symfony guard выложен, runtime image Python опубликован с immutable
version/source; отдельный research stack не запущен. Миграций этой задачи нет.
Подтверждённый pilot сохранён в защищённой серверной конфигурации без изменения
общего false флага. Его реальные данные не добавлены в Git.

```text
PASS: deployed source 48fcb8dee5056e07aefe6797c1c341e715942618; one private pilot configured, global flag=false; ten data controller flags=false; availability=false; categories/start/get run/project progress guards=404 feature_disabled; no writes
PASS: company-scoped reconciliation command registered (--after/--limit); no execution
api: running / healthy
nginx: running / healthy
ozon-fetcher: running / healthy
postgres: running / healthy
redis: running / healthy
traefik: running / no healthcheck
worker-backfill: running / healthy
worker-calc: running / healthy
worker-freshness: running / no healthcheck
worker-ingestion: running / healthy
worker-scheduler: running / healthy
worker-service-coverage: running / healthy
PASS: public seller ping version=785-48fcb8d
PASS: anonymous categories request=401
PASS: anonymous availability=401
PASS: anonymous projects/019b0000-0000-7000-8000-000000000002/progress=401
PASS: optional research stack has no running containers; live collector not activated
PASS: public service-contour request=404
```

Архив и этот отчёт публикуются отдельным Markdown PR: код не меняется,
ADR не добавляется, поэтому дополнительное ревью не требуется.
Финальная документальная выкладка фиксируется в PR без цикла новых коммитов.
Этап8 не отмечен полностью завершённым; для live-включения нужны оставшиеся
условия плана и операционные доказательства.
