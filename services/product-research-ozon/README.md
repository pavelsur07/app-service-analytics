# product-research-ozon

## Production-инфраструктура без MPStats

Этап 9 добавляет закрытый режим ожидания. Используется существующий бакет сырья
Conwix и префикс `conwix/svc/product-research-ozon/v1/`; новый бакет не создаётся.
По уточнению владельца от 02.11.2026 Compose передаёт существующие
`RAW_STORAGE_*` как `RESEARCH_S3_*` из защищённой конфигурации деплоя.
Отдельный S3-ключ не требуется для текущего MVP; IAM-изоляции общего ключа нет,
граница держится company-scoped портом и префиксом (ADR-050).
Собственная роль/БД PostgreSQL и межсервисные токены остаются обязательными.
`RESEARCH_WORKER_STANDBY=true` требует выключенных catalog/collection/worker;
MPStats не настраивается и не вызывается. Worker ожидает завершения без захвата jobs.
При инфраструктурном запуске PHP-флаг false. Для отдельного пилотного UI
он включается только для двух компаний; PHP source ID остаётся пустым,
поэтому запуск отклоняется до run/outbox. Research может открываться без
личного Ozon-подключения по server availability; остальные разделы сохраняют
онбординг (ADR-050 уточняет ADR-021). Это не заменяет live-приёмку MPStats.

`RESEARCH_STACK_ENABLED=true` в основном защищённом окружении подключает отдельный
стек к штатной выкладке. Workflow создаёт DB/HBA, проверяет source образа и ревизию
схемы, затем ожидает health. Миграции применяются явно. False останавливает только
этот стек без удаления DB/raw. Порядок —
[production runbook](../../docs/operations-checklist.md#product-research-ozon--этап-8).

Этапы 1–8 (разработка): внутренний FastAPI, Alembic, отдельные dev/test БД,
company-scoped S3-порт и выключенный по умолчанию worker. Основание — принятый ADR-050.
Реализованы суточный кэш категорий, локальная проекция Symfony и движок месячного raw-снимка. Диспетчер заданий и пользовательские экраны реализованы; production-активация ещё закрыта.

План: [product-research-ozon](../../docs/plan/product-research-ozon.md).
Проверка API: [отчёт REST](../../docs/plan/product-research-ozon-rest-check.md).

## Фикстуры

Полный январь 2026 для категории «Хиджабы и тюрбаны женские»:
[описание набора](tests/fixtures/mpstats/ozon/category-items/2026-01/README.md).

Секрет `MPSTATS_API_TOKEN` хранится в GitHub Actions Secrets, но для чтения
фикстур не нужен. Автоматические тесты не должны обращаться к MPStats.

Владелец 31.10.2026 разрешил публикацию проверенных рыночных фикстур MPStats
этого этапа в публичном GitHub. Секреты, данные покупателей и закрытые данные
других компаний этим разрешением не охватываются; см. CLAUDE.md §9.


## Проверка подготовки

`make product-research-ozon-stage0-check` — hash-lock, pip check, imports,
audit, затем Ruff, mypy strict и тесты эталонов в контейнере без сети.
Образ CPython 3.14.10 закреплён digest; поддержана Linux x86_64.
Все 71 зависимости проверяются с hashes. Выходные материалы — в var.

Дополнительный набор: `tests/fixtures/mpstats/ozon/category-items/insect-protection/2025-02/`: оба режима API, новый ручной ZIP, прежний
диагностический CSV. Он подтверждает FBS по всем SKU без повторного API.

`stage0/contract-examples.json` — общие искусственные примеры для проектирования
PHP/Python-контрактов: пагинация, пустой ответ, отсутствие поля, неизвестные поля,
401, даты и состояния. Это не реальные ответы MPStats и не опубликованная схема;
исполняемые тесты runtime-контрактов добавляются на соответствующих этапах.

## Запуск и гейты этапа 1

План/приёмка: [этап 1](../../docs/plan/product-research-ozon-stage1.md).
Контракт: [v1](../_contracts/product-research-ozon/v1/README.md).

- `make product-research-ozon-check` — Ruff, mypy strict, import-linter,
  unit/общий контракт/integration, сборка и HTTP smoke.
- `make product-research-ozon-audit` — hash-install и audit закреплённого графа.
- `make product-research-ozon-up` — явная миграция dev БД, bucket dev и API.
- `make product-research-ozon-migrations-check` — только проверка ревизии.
- `make product-research-ozon-test` — отдельная test БД, baseline
  up/check/down/up/check, реальные PostgreSQL/S3; live MPStats отсутствует.
- `make db-rebuild-check` — полный rebuild **только локального** окружения,
  удаляет его тома; включает миграцию и тесты Python-сервиса.

API слушает :8080 только в docker-сети internal (internal=true); host ports,
роутера и egress нет. PHP подключён к этой сети для будущего фонового клиента.
Профиль `product-research` включается явно; worker — отдельный профиль
`product-research-worker`, по умолчанию выключен. Без явной collection-конфигурации он фиксирует disabled и завершает работу;
worker_enabled=true требует валидные бюджеты и общий источник каталога.
Отдельная production-заготовка и публикация image добавлены этапом8;
этап 9 добавляет штатную выкладку по отдельному флагу и инфраструктурный standby.

/health проверяет процесс; /ready проверяет БД, точную Alembic-ревизию и
ограниченный ListObjectsV2 research-префикса, возвращая collection_enabled=false.
API не применяет миграции при старте и не создаёт bucket. Проверка S3 требует
ListBucket на префикс. Бакеты общие с монолитом: conwix-dev / conwix-test, свой только префикс.
В dev SeaweedFS не проверяет credentials: границу компаний
проверяет порт. Production использует существующие RAW_STORAGE_* по решению
владельца от 02.11.2026; издержка общего ключа описана в ADR-050.
ListObjectsV2 не доказывает права записи; запись/чтение проверяются отдельно.

Переменные RESEARCH_DATABASE_URL, INBOUND_TOKEN, OUTBOUND_TOKEN, S3_ENDPOINT,
S3_BUCKET, S3_ACCESS_KEY, S3_SECRET_KEY и WORKER_ENABLED: **каждое имя** имеет
префикс RESEARCH_. Токены направлений различны, ASCII, 32..256 символов.
Секреты из окружения, не из HTTP/UI; MPStats-токен на этом этапе не нужен.
Значения compose — исключительно синтетические dev defaults.

Структура: src/product_research_ozon/domain — чистые правила объекта;
api.py — HTTP-граница/DTO; infrastructure — конфигурация, PostgreSQL readiness,
S3 и безопасное журналирование; bootstrap — сборка; migrate — явная команда.
Миграция 0001_baseline задаёт историю Alembic, предметных таблиц нет.
requirements-runtime.lock — замыкание runtime из проверенного stage0 lock;
новых пакетов нет. dev-образ содержит инструменты, runtime — без pytest/Ruff/mypy.
Мигратор использует SQLAlchemy Core, runtime-чтения — Psycopg.

Локальные typings/ описывают только используемые SDK-методы boto3/botocore и
jsonschema, не отключая mypy. Их сигнатуры проверяются реальными integration
и контрактными тестами; при расширении SDK обновлять и декларации, и тесты.
Журнал проходит общий SafeJsonFormatter: произвольные сообщения сторонних
библиотек и traceback не выводятся, остаются severity/class и безопасные поля.
Uvicorn access log и доверие proxy headers отключены. 503 not_ready — warning,
а не ошибка трекера. Трекер ещё не подключён; stderr — JSON.


## Этап 3: каталог без повторного запроса на каждый поиск

План и приёмка: [этап 3](../../docs/plan/product-research-ozon-stage3.md).
Кэш PostgreSQL изолирован по company_id/source_connection_id, TTL — 24 часа.
Поиск seller читает только полную локальную проекцию Symfony. Фоновая команда:

```sh
docker compose exec -T php-cli php bin/console app:research:sync-categories COMPANY_UUID_V7 --once
# Без --once: отдельный фоновый цикл, --interval=60; одна явно разрешённая компания.
```

До TTL команда не вызывает Python. После TTL Python также проверяет собственный
кэш и обращается к MPStats только при необходимости. Lease/fencing защищает
одновременное обновление, общий gate ограничивает серверный токен. 429 уважает
Retry-After, 401/403 ставит паузу credential generation. Секреты/тела не логируются.
Путь — исходный name целиком, стабильного ID в проверенном ответе нет; возможный
provider ID сохраняется только в техническом кэше Python. Метрики из ответа
не записываются. Схема БД сервиса теперь 0002_catalog_cache; down удаляет только
производный каталог, не человеческие проекты/месячные снимки.

Конфигурация для будущего контролируемого включения (не реальные значения):

| Компонент | Переменные |
|---|---|
| Оба | RESEARCH_CATALOG_SOURCE_ID: одинаковый UUIDv7 подключения |
| PHP | RESEARCH_SERVICE_TOKEN: Bearer направления PHP→Python, 32..256 ASCII |
| Python | RESEARCH_CATALOG_ENABLED=true, RESEARCH_CATALOG_GENERATION (возрастающее целое 1..2147483647), RESEARCH_CATALOG_RPM |
| Python | MPSTATS_API_TOKEN: только Python; RESEARCH_CATALOG_CURSOR_SECRET: отдельный секрет подписи |

Остальные RESEARCH_* конфигурации этапа 1 сохраняются. RESEARCH_INBOUND_TOKEN
Python должен соответствовать RESEARCH_SERVICE_TOKEN PHP. Успешная выдача
проверяется по X-Research-Source-Id; несовпадение запрещает публикацию в PHP.
Смена аккаунта источника требует нового SOURCE_ID, ротация ключа того же аккаунта —
нового GENERATION. Старый процесс не снимает паузу нового поколения.

Каталог выключен по умолчанию. Dev-сеть остаётся internal без живого egress;
production-активация/реальный тариф/квота/проверка S3-доступа — этап 9
(разработка этапа 8 закрыта по решению владельца от 02.11.2026). В этом этапе
не утверждается live-приёмка MPStats. Тесты используют сохранённый ответ этапа 0;
контрольные суммы/разрешение публикации — в fixtures/mpstats/ozon/categories.
Тестовый `tests/catalog_smoke_server.py` проверяет реальный HTTP PHP→Python на
отдельной локальной БД и фикстуре, отказывается от нетестовой БД и не входит в
runtime image. Catalog metadata сохраняет microseconds, TTL не сдвигается чтениями.

Ротация: сначала увеличить GENERATION вместе с заменой секрета, затем контролируемо
перезапустить сервис. На startup активируется только равное/большее поколение;
более старый процесс не может понизить active_generation. При равном поколении
auth pause сохраняется, только строго более новое снимает её. Сохраняются RPM/
Retry-After; публикация проверяет актуальное поколение под блокировкой. Старый
in-flight запрос не публикует данные после активации нового поколения и не
ставит ему паузу результатом своего отказа. Source ID не меняется при ротации
ключа того же аккаунта; при смене аккаунта нужен новый SOURCE_ID.


## Этап 4: полный месяц и неизменяемое сырьё

[План/TDD/приёмка](../../docs/plan/product-research-ozon-stage4.md).
`collection/service.py:MonthCollector` — start/step/read. Один шаг получает
не больше одной страницы по 500 строк, сохраняет курсор и освобождает аренду.
Вызывающий будущий диспетчер соблюдает сохранённый next_attempt_at; внутри
нет sleep. Общий provider gate каталога также ограничивает items: это
консервативный общий gate серверного подключения, включая auth pause/ротацию.
Он не объявляется подтверждённой квотой MPStats. Items client и GatedItems
собираются явно; API/worker автоматически сбор не включают.

Технические бюджеты rows/pages/bytes передаются явно в CollectionLimits при
создании снимка. Production defaults не придуманы: квоту/максимальную категорию/
объём проверяют до активации на этапе 8. Source ID и generation должны совпасть
с активированным подключением каталога. Токен остаётся только в HTTP-адаптере.

Новая ревизия `0003_month_snapshots`: month_snapshot, snapshot_page и временная
snapshot_sku_check (проверка уникальности SKU; ключи удаляются после завершения).
Только технические метаданные, ни денежных значений, ни строк отчёта в БД.
S3-страницы лежат по пути ADR-050; manifest/{sha256}.json — рядом со страницами.
Путь категории используется только в манифесте/отображаемом имени.
Чтение готового сырья получает company/source/snapshot/page, ищет scoped DB-запись
и пересобирает ссылку; готовый внешний ключ не принимается.

Все страницы и манифест пишутся If-None-Match:*. Повтор проверяет существующие
байты, а успешная запись перечитывается с проверкой checksum. Страница S3
предшествует DB-регистрации; manifest S3 предшествует атомарному status=ready.
Сбой/потеря аренды или поколения не публикует неполный снимок. Потерянный ответ
после успешного commit восстанавливается чтением уже готового результата.
Неполученное сырьё не выдаётся за успешный пустой отчёт: total=0 публикуется
с отдельным предупреждением empty_category_or_renamed.

При сбое сохраняются зарегистрированные страницы; незарегистрированные S3
объекты возможны и не считаются готовыми/кэшем. Автоматического удаления нет:
GC/retention и операционный порядок восстановления должны быть готовы до этапа 8.
`down` удаляет журнал снимков/страниц, оставляя каталог и S3 неизменными;
перед production down нужен экспорт/backup журнала для сохранения связей с сырьём.
Повторные попытки и восстановление error-снимка реализует этап 5, повторное
использование закрытого месяца — этап 6. Runs/snapshots HTTP реализованы этапом 5;
worker выключен, /ready продолжает сообщать collection_enabled=false.

Тест полного месяца использует существующий архив 102 страниц/50702 SKU:
HTTP MockTransport → настоящий PostgreSQL/S3 → чтение всех bytes/SHA-256,
проверка 62 полей, нулевых продаж и точных сумм. Живых MPStats-запросов нет.
Все 41 колонки ручного CSV сопоставлены в
[карте версии 0.3](../../docs/plan/product-research-ozon-columns.md);
неэквивалентные вычисляемые поля и метаданные перечислены явно. Этап 4 не
пересчитывает их и не подменяет исходные значения форматированием CSV.


## Этап 5: запуски, очередь и восстановление

`domain/jobs.py` — frozen input и чистая retry/deadline policy;
`jobs/service.py` — приём и один шаг worker; `infrastructure/job_store.py` —
атомарный run/jobs/snapshots, аренды и справедливый выбор разрешённых компаний.
`0004_research_jobs.py` добавляет технические журналы и parent-job fencing
месячных снимков. Run statuses: queued/running/ready/error; ready соседних месяцев
сохраняется при ошибке. Опубликованный внутренний v1 не изменён.

Входные даты фиксируются при создании Symfony run и не сдвигаются при доставке
через полночь. Повтор POST с тем же company/run и параметрами возвращает receipt
202 queued/revision=1; текущее состояние получают через GET. Worker не удерживает
транзакции на I/O, ожидания хранятся в next_attempt_at. Network/5xx/временная
инфраструктура: 3 повтора страницы, 60/120/240 секунд; ошибка полноты: одна новая
попытка месяца. Дедлайн 24 часа. 401/403 завершает активные месяцы запуска без
новых API; готовые снимки сохраняются. Ротация поколения ограждает старые процессы.

Для явной dev-конфигурации используются `RESEARCH_COLLECTION_ENABLED=true`,
`RESEARCH_WORKER_ENABLED=true`, `RESEARCH_MAX_ROWS`, `RESEARCH_MAX_PAGES`,
`RESEARCH_MAX_BYTES`, `RESEARCH_ALLOWED_COMPANY_IDS` (canonical UUIDv7 через запятую).
Нужен существующий валидный catalog source/generation/rpm и секреты только из
защищённого окружения. Пустой allowlist закрыт. Эти параметры не являются квотой
MPStats. В dev доступны только фикстуры/MockTransport; живой egress и production
Python остаются этапом 8. Compose defaults и seller feature flag выключены.

Symfony хранит intent/outbox одной ORM-транзакцией. После commit отправляет
уведомление в существующий async_ingestion с TransportNamesStamp. Потерянную
доставку восстанавливает company-scoped команда
`app:research:process-runs <company-uuidv7> --once`; без --once цикл раз в 60 с.
Она же поллит прогресс, сохраняет только новую согласованную revision. Команду
нужно запускать явно для разрешённых компаний; глобальной выборки PHP нет.
В stage 5 новый seller POST projects/{id}/runs и GET projects/{id}/runs/{runId}
читают локальную проекцию; категории и user HTTP не вызывают Python.

Перед down сделать согласованную резервную копию журналов обеих БД.
Alembic down 0004 удаляет только run/job/turn и связь job_id: старые snapshots,
страницы и объекты S3 сохраняются, но автоматическое восстановление очереди без
backup невозможно. Doctrine down удаляет журналы запусков и last_run_id;
проекты/периоды сохраняются. На production down допустим только с backup журнала
и остановленными relay/worker. Up/down/up проверяются в локальном тестовом контуре.
Межпроектный кэш и сводные статусы проекта реализует этап 6; UI — этап 7.

Результат release имеет durable outcome_revision, задание хранит подтверждённую
snapshot_ack_revision. После crash сохранённый отказ применяется один раз до
нового I/O; 202/429 сохраняют паузу и не расходуют network budget. Счётчик подтверждённых страниц (включая пустую)
восстанавливает бюджет новой страницы после успешного append без job ack.
Истёкшее исполнение без достоверного исхода учитывается как один временный отказ
до нового запроса: это консервативно ограничивает повторы, включая crash до I/O.
Все решения apply, включая terminal error, проверяют активное поколение токена.


## Этап 6: закрытые месяцы и progress v2

`0005_month_cache` координирует закрытый месяц по company/source/category/path,
точным датам, FBS/RUB и версии семантики сырого запроса. Поколение credentials
в ключ не входит. Готовый snapshot повторно используется без API/S3 записи,
но с проверкой новых технических лимитов. Active owner сохраняет владение;
конкурент получает persisted wait без удержания worker. После ошибки слот
может занять другой job; после restart используется текущий snapshot owner job.
Partial не кэшируется. Старые готовые снимки остаются в истории.
При первом создании слота под его блокировкой принимается подходящий ready job
этапа 5 (с теми же фиксированными FBS/RUB/profile v1); старые выгрузки также
повторно используются после обновления. Поиск legacy ограничен месяцем/компанией
и поддержан частичным индексом, сетевого I/O нет.

GET `/internal/v2/companies/{company_id}/runs/{run_id}` возвращает origin api/cache,
collected_at исходного снимка и used_at текущего job, остальные статусы — null.
v1 не изменён; POST admission/catalog/snapshot metadata остаются v1. Свежий v2
контракт находится рядом с v1. До обновления PHP Python обязан поддерживать v2.

Symfony читает свои локальные проекции. Потребности проекта фиксируются при
человеческом изменении/старте и не расширяются ночью автоматически; preview
считает календарь на текущую дату. Статус выводится одним MVCC SQL: draft,
loading, loaded, partially_loaded, error. Добавленный во время загрузки период
не входит в уже принятый run. Последняя попытка каждого месяца определяет
покрытие; ошибка нового partial не скрывается прежним успешным снимком.

Восстановление: `make api-console CMD='app:research:reconcile-runs COMPANY_UUID
--limit=20'` (команда в одну строку). Повторять с `--after=NEXT_CURSOR` до `-`.
Команда включает завершённые доставленные runs и безопасно дополняет legacy
v1 проекцию при той же revision. `failed>0` даёт ненулевой exit; после исправления
причины повторить сканирование с начала, поскольку cursor уже прошёл ошибку.
Чужой source/lease и старая revision не перезаписывают данные.

Откат: сначала остановить worker/relay/poll и сделать backup технических
журналов. Down 0005 удаляет cache slots и provenance job, сохраняет snapshots/S3.
Doctrine `Version20261102033959` down удаляет только новые требования/метаданные;
при возврате вперёд потребности пересчитываются миграцией из intent, provenance
восстанавливается сверкой. Для точного исторического partial нужен backup:
legacy backfill использует последнюю дату изменения проекта в Europe/Moscow.
Production Python/egress/реальные ключи и активация модуля — этап 9;
feature flag остаётся выключенным.

Legacy API-ready jobs этапа 5 не имеют известного used_at: v2 возвращает null,
PHP сохраняет null. Новые API/cache jobs записывают точное время принятия;
origin=cache всегда требует used_at. Неизвестное прошлое время не восстанавливается
из collected_at.

## Этап 8 закрыт как разработка; включение — этап 9

[Принятая разработка](../../docs/plan/product-research-ozon-stage8.md),
[открытый план включения](../../docs/plan/product-research-ozon-stage9.md),
[операционный runbook](../../docs/operations-checklist.md#product-research-ozon--этап-8).
Production topology — отдельный `docker-compose.research.prod.yml`, default
без сервисов. Master CI публикует runtime image с immutable version/source;
этап 9 подключает его к Conwix deploy по RESEARCH_STACK_ENABLED; режим ожидания
и live-сбор разделены, пользовательский флаг остаётся независимым.
Включать его до wiring обновления/миграционного gate и live-предусловий нельзя.

Symfony дополнительно проверяет `RESEARCH_ALLOWED_COMPANY_IDS`: canonical
UUIDv7 через запятую, без пробелов. Пустой/невалидный список закрывает доступ,
включая availability, даже при общем PRODUCT_RESEARCH_OZON_ENABLED=true.
Python должен получить тот же список. Общий флаг false закрывает все компании.

Операторские CLI (требуют настроенного защищённого RESEARCH окружения):

```sh
python -m product_research_ozon.operations diagnostics COMPANY_UUIDV7 SOURCE_UUIDV7 --limit 200
python -m product_research_ozon.operations verify-snapshot COMPANY_UUIDV7 SOURCE_UUIDV7 --snapshot-id SNAPSHOT_UUIDV7
```

Diagnostics — sample последних N jobs этой company/source, не полный счётчик
API-квоты/объёма S3. truncated=true означает неполноту sample. ready API/cache,
ошибки, overdue и возраст активных jobs не содержат сумм, SKU, тела или секреты.
Snapshot audit проверяет manifest/число страниц/размеры/SHA-256, читая сырьё
постранично. MPStats не вызывается; код не исправляет и не удаляет данные.

`make product-research-ozon-recovery-check` создаёт новый случайный local
Compose project, делает synthetic501 rows/2 pages и cache reuse, pg_dump,
pg_restore в отдельный PostgreSQL и exact raw audit/tenant denial.
Копия БД без S3-связей не считается проверенным восстановлением. Это local
recovery drill, а не утверждение о наличии production backup/restore.
`make product-research-ozon-prod-config-check` только разбирает topology,
не читает настоящий env и не создаёт контейнеров.
