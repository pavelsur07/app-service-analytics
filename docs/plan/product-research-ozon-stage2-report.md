# product-research-ozon — отчёт этапа 2

Статус: **этап 2 завершён 01.11.2026** — реализация, приёмка, независимое
ревью, CI, merge, production-миграция и smoke подтверждены. G01–G12 закрыты.
Основание: [план и критерии](product-research-ozon-stage2.md).

## Реализовано

ProductResearchOzon добавлен как модуль Symfony со слоями ADR-050. Реализованы
preview/create/get/list/add/copy: авторство из seller-сессии, company scope,
FBS=true/RUB, именованные сезоны, до 24 диапазонов/месяцев, часы PSR-20 и
календарь Europe/Moscow. Дубликаты месяцев объединяются, текущий месяц явный
и до вчера. PreviewCalendarDate защищает от устаревшего предпросмотра.

Сохранение шапки, периодов и аудита атомарно через ORM. Периоды защищены
уникальным ограничением и составным FK (company_id, project_id), поэтому
шапка имеет составной PK (company_id, id); оба UUID заданы приложением,
id — UUIDv7. Обычные человеческие таблицы, факты не менялись. Это реализация
согласованной tenant-связи, новых межмодульных связей не вводится.

Список — bounded DBAL/keyset с limit+1; деталь — один SELECT с периодами:
шапка и строки видны в одном MVCC-снимке, без ORM-гидрации/N+1. Mutation-ответ
использует уже изменённый агрегат и рассчитанный в том же сценарии preview;
сущность напрямую не сериализуется, ответ собирается через DTO.

Реальные изменения/копирование блокируют шапку, проверяют expectedVersion.
ORM version участвует в записи; period_count/flag меняют шапку даже при
неизменившихся контролируемых часах. В Private mapped-полях шапки PHP readonly
не используется: Doctrine refresh под блокировкой создаёт новые UUID/DateTime
объекты. Инкапсуляция сохраняется приватными полями и явными методами.

CategoryLookup различает подтверждённую категорию, unknown_category и
catalog_not_ready. В production до этапа 3 работает только unavailable adapter;
фикстуры/Fake подключены исключительно в test. Feature flag
PRODUCT_RESEARCH_OZON_ENABLED имеет server default false: авторизованный seller
получает 404. Без сессии и без членства сначала работает действующая auth-граница.
Никаких обращений к Python/MPStats, фоновых заданий, UI или фиктивных готовых данных.

## Уточнения реализации

- Предпросмотр без категории возвращает suggestedName=null; если категория
  выбрана, она проверяется в company-scoped справочнике и предлагается название.
- Add допускает явную смену includeCurrentMonth вместе с периодами. Полный no-op
  при одинаковых периодах и флаге не меняет version/аудит. Copy читает источник
  под FOR SHARE, изменения берут FOR UPDATE; копия не повышает версию источника.
- Целые JSON-числа 1.0/3.0 принимаются как integer согласно OpenAPI, строки,
  bool и дробные значения отвергаются. Денежных вычислений нет.
- Down миграции разрешён на пустых новых таблицах; при наличии черновиков
  abort, поскольку эти человеческие данные не восстанавливаются из MPStats.
  Откат с данными — сохранение текущей БД/восстановление согласованной резервной
  копии, а не удаление проектов. Production flag на этом этапе не включается.
- Первый fresh rebuild выявил ложную готовность initdb: db-wait проверял
  временный Unix-сокет до TCP-start. Проверка переведена на TCP 127.0.0.1;
  повторный полный rebuild выполняется в изолированном Compose project.

## Локальные результаты

- Red/Green календаря: без классов тесты падали; затем 15 unit/35 assertions
  подтвердили периоды, пересечения, високосность, shortcuts и Moscow-boundary.
- `phpunit tests/Unit/ProductResearchOzon tests/Integration/ProductResearchOzon
  tests/Functional/ProductResearchOzon`: **57 тестов / 576 assertions**, успешно.
  Есть реальные процессы/соединения с подтверждённым pg_blocking_pids ожиданием,
  оба порядка copy/add, rollback после flush, valid extension token, CSRF,
  blocked/чужие компании, flag=false, body/cursor limits и ошибки категорий.
  Лог: var/product-research-ozon-stage2/final-green.log.
- `make lint stan deptrac structure-check api-types-check`: успешно.
  PHPStan baseline не расширен. OpenAPI/TS изменены только генераторами.
- `make db-schema-validate`: dev/test mapping и схемы совпадают.
- Полный isolated `db-rebuild-check`: **OK** — 801 unit / 9115 assertions,
  883 integration / 23231 assertions, 409 functional / 3952 assertions; затем
  гейты Go и 27 тестов Python. Проект research-stage2-rebuild, порты reset, собственные PG/S3/Go
  volumes; текущие dev-тома не удаляются. Лог: var/product-research-ozon-stage2/rebuild-review.log.

После замечаний ревью добавлены индекс автора, отдельный Builder периода,
QueryBuilder-контракт и before/after аудит. Реальный MonthResponse проверяется
против OpenAPI, файлы схемы доступны тестам через read-only volume.
Повторный полный rebuild обновлённой миграции завершён успешно.
После второго ревью запрещён NUL в текстах; три HTTP-регрессии проверяют
отказ до записи. Замечание о base32/base58 cursor отклонено по установленному
коду Symfony и фактическому HTTP 422. Оба финальных прохода завершены без замечаний.

## Матрица приёмки

Команда для всех перечисленных PHP-тестов:
`docker compose exec -T php-cli php vendor/bin/phpunit tests/Unit/ProductResearchOzon tests/Integration/ProductResearchOzon tests/Functional/ProductResearchOzon`.
Итог: 57 тестов / 576 assertions, PASS; полный backend также подтверждён CI.

| Критерии | Проверка | Результат |
|---|---|---|
| U01–U02 | ResearchCalendarTest::testSeasonsDeduplicateMonthsAndKeepNames, testOverlapsYearBoundaryAndLeapDays | PASS: 6/5 уникальных месяцев |
| U03–U04 | ResearchCalendarTest::testOverlapsYearBoundaryAndLeapDays | PASS: переход года, 29/28 дней |
| U05 | ResearchCalendarTest::testInvalidSelectionsAreRejected, testStrictRangeInput | PASS: лимиты и неверные месяцы |
| U06–U07 | ResearchCalendarTest::testCurrentMonthAndMoscowBoundary | PASS: явный partial, day 1/2, Moscow/UTC |
| U08–U09 | ResearchCalendarTest::testShortcutsAndLongSuggestedName; ControllerTest::testWhitespaceNameUsesSuggestedName, testFullApiPathKeepsPeriodsAuthorAndNoopVersion | PASS: shortcuts, автоимя/ручное имя |
| I01 | ResearchProjectsTest::testFailureAfterFlushRollsBackProjectPeriodsAndAudit | PASS: rollback проекта, периодов, аудита |
| I02 | ResearchProjectsTest::testForeignKeyRejectsCrossCompanyPeriod, testDatabaseRejectsDuplicateRange | PASS: ограничения PostgreSQL |
| I03–I05 | ResearchConcurrencyTest::testTwoAddsReallyWaitAndOnlyOneVersionWins; ResearchProjectsTest::testCreateAddNoopCopyAndAuditAreAtomic, testFlagOnlyChangesHaveBeforeAndAfterAuditValues | PASS: одна версия выигрывает, no-op без записи |
| I06–I07 | ResearchProjectsTest::testListDetailAndCursorStayWithinCompany; QueryBuilder с одним JOIN detail проверен независимым ревью | PASS: scope/keyset, без N+1 |
| I08 | ResearchConcurrencyTest::testCopyAndAddUseOneCoherentSourceInBothOrders; ResearchProjectsTest::testCreateAddNoopCopyAndAuditAreAtomic | PASS: coherent copy / conflict, новая категория |
| F01–F02 | ControllerTest: CompanyAndProjectIsolation, AnonymousAndBearer, BlockedCompanyAndCsrf, AdminSession, ValidExtensionToken, BoundaryRejectsMalformedAndPrivilegedFields | PASS: seller/company/author guards |
| F03 | ControllerTest::testCategoryAndCatalogReadinessAreDifferent, testCatalogUnavailableIs503AndNoWrite | PASS: 422 / 503 без записи |
| F04 | ControllerTest::testFullApiPathKeepsPeriodsAuthorAndNoopVersion | PASS: preview→create→get→list→add→copy |
| F05 | ControllerTest::testNewDayAndOldVersionDoNotChangeProject; ResearchProjectsTest::testStalePreviewAndInvalidCategoryDoNotWrite | PASS: 409 без записи |
| F06 | ControllerTest::testRawJsonLimitsShortcutsAndCursorBoundary, testBoundaryRejectsMalformedAndPrivilegedFields, testNullBytesReturn422WithoutWrites | PASS: JSON/limits/CSRF/FBS/RUB/NUL |
| F07 | ControllerTest::testDisabledFlagProtectsDirectCalls; production smoke ниже | PASS: закрытый сценарий, без fake-каталога |
| C01 | ResearchContractTest: v1 month и настоящий JSON против OpenAPI; make api-types-check | PASS |
| M01 | Полный isolated make db-rebuild-check; guard на синтетическом draft; empty down/up + doctrine:schema:validate | PASS |

ControllerTest в таблице — ResearchProjectsControllerTest. Имена, сокращённые
до смысловой части, находятся в этом классе; дополнительные worker guards и
Builder-проверки включены в тот же успешный прогон.

## Независимое ревью

Claude CLI отсутствует. По разрешённой замене выполнены три последовательных
пакета, в каждом две новые независимые сессии Codex CLI 0.166.0 / gpt-6.1-sol/high,
sandbox read-only. На итоговом пакете обе роли complete, exit_code=0,
0 findings/rule_gaps/nitpicks. Ревьюеры не запускали тесты: результаты проверок
получены отдельно исполнителем и CI.

Приняты уникальные замечания: первый 5A — 4, первый 5B дополнительно — 1;
второй 5A — 0, второй 5B дополнительно — 1; оба финальных — 0.
Повтор аудита не посчитан дважды. Единственное отклонение — предположение
о base32/base58 cursor — проверено установленным кодом Symfony и HTTP 422,
подтверждено обоими финальными проходами. Все шесть принятых исправлены.
Полные заключения, hashes, артефакты и разбор — [ревью](product-research-ozon-stage2-review.md).

## CI и доставка

Итоговый код: 4427122e0df0d34a7cb7d777309dfb11659d59ef.
[CI 41290103356](https://github.com/pavelsur07/app-service-analytics/actions/runs/41290103356)
успешен: backend, frontend, services, E2E, контракт/уязвимости.
[PR #305](https://github.com/pavelsur07/app-service-analytics/pull/305) влит
в d4ec29de23fda7db76dcd417fe5b943eedf1cadd. После squash код не менялся.
[Production CI 41290549891](https://github.com/pavelsur07/app-service-analytics/actions/runs/41290549891)
успешен после отдельного применения миграции и повторного deploy (attempt 2).
Первая попытка штатно остановилась на migrations:up-to-date: одна неприменённая
миграция. Через conwix-prod, /opt/conwix, проверен APP_SOURCE_COMMIT нового
образа; применена только `DoctrineMigrations\Version20261101080233`:

```sh
docker compose -f docker-compose.prod.yml run --rm --no-deps -T api \
  php bin/console doctrine:migrations:execute --up --no-interaction \
  'DoctrineMigrations\Version20261101080233'
```

Затем `doctrine:migrations:up-to-date` и `doctrine:schema:validate` — exit 0:
неприменённых миграций нет, mapping и схема синхронны. Существующие человеческие
данные не перезаписывались. Вне контейнеров dev использован разрешённый SSH-контур.
В CLI schema validation есть существующие deprecation timestamp/jsonb в других
mapping; команда успешна, правила и проверки ради результата не ослаблялись.

Production smoke из развернутого контейнера и снаружи:

- Публичный `/api/seller/ping` — версия **746-d4ec29d**.
- APP_SOURCE_COMMIT — **d4ec29de23fda7db76dcd417fe5b943eedf1cadd**.
- У всех шести реальных контроллеров разрешённое контейнером значение флага false.
- Прямой preview в production CLI — **404 feature_disabled**, без записи данных.
- Публичный company-scoped list с валидным UUIDv7 без сессии — **401 unauthenticated**.
  Первоначальная проба с nil UUID дала route 404: для auth-smoke она заменена
  UUIDv7, соответствующим route requirement. Защита seller отдельно покрыта тестами.
- Workflow подтвердил версии seller/admin; api/nginx/ozon-fetcher/PostgreSQL/Redis
  и воркеры работают, все настроенные healthchecks — healthy.

Локальные свидетельства: `var/product-research-ozon-stage2/production-migration.log`,
`production-feature-smoke.log`, `production-ping.json`, `production-after.json`,
`production-anonymous-code.txt`. Постоянные CI/merge receipts — ссылки выше;
результаты и полные ревью сохранены в Markdown, локальный var не объявлен архивом.
Закрывающий PR меняет только документы и чек-листы, код после ревью не меняет.

Python-сервис/загрузка и seller UI остаются вне включения. Чужие B17/B18,
securety_chek, temp и удаление спецификации онбординга не включаются в задачу.

Дополнительно M01: на изолированной app_test синтетический черновик блокирует
`down`; после cleanup проверены empty down/up и schema validation. Результат:
var/product-research-ozon-stage2/migration-check.txt. Свежий rebuild действительно
пересоздал только тома research-stage2-rebuild; исходный сбой TCP не засчитан.

Разбор всех трёх пакетов и полные заключения: [ревью этапа](product-research-ozon-stage2-review.md).
