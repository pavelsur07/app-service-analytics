# ozon-fetcher

Go-сервис загрузки raw Ozon (ADR-043): по активным подключениям Ozon
набирает ответы Seller API и Performance API в бакет сырья Conwix под своим
префиксом (ADR-044) и ведёт журнал
загрузок в своей БД. Работает параллельно загрузке PHP и её не меняет;
передача raw в PHP — вторая волна, отдельный ADR. Другие маркетплейсы —
не здесь.

**Источник кода** — ВашФинДир (`app-service-finance`, `services/ozon-fetcher`,
коммит `a9eb74206`), перенесён с адаптацией (этап 1 `docs/plan/ozon-fetcher.md`):
схема и код `store` в форме Conwix, подключение — пара `company_id` +
`marketplace_account_id`, UUIDv7, клиент выдачи подключений под контракт
`services/_contracts/marketplace-connections/v1`. Остальной код — как
в источнике; ссылки `docs/tasks/…` в комментариях кода и в
[`docs/ozon-api.md`](docs/ozon-api.md) ведут в репозиторий источника.

**Статус:** перенесён, загрузка выключена конфигурацией — без
`SYMFONY_INTERNAL_BASE_URL` планировщик и диспетчер не стартуют, сервис
отвечает только `/health` и `/ready`. Выдача подключений в PHP — этап 4,
приём выдачи по правилам отзыва — этап 5, наблюдаемость — этап 6,
вывод на прод — этап 7 плана: образ публикуется с `master`, контейнер
включается `OZON_FETCHER_ENABLED=true` в `/opt/conwix/.env`
(`docs/operations-checklist.md`, «ozon-fetcher на проде»).

**Журнал** (`services/AGENTS.md` §8): JSON в stdout. Обязательные поля —
`service`, `company_id`, `marketplace_account_id`, `job_id`, `trace_id`,
`resource_type`, `outcome`, `error_class`, `duration_ms` — дописывает в каждую
запись общий обработчик (`observability.Handler`): поля задачи берутся из
контекста задачи (их получают и запросы клиентов Ozon), неизвестные — `null`.
Ключей, токенов, тел ответов и сумм в журнале нет; от ответа Ozon пишется
только текст ошибки из конверта (`message`, до 200 символов, присланные
ключи вычищены). В GlitchTip (свой проект, `SENTRY_DSN`) — только ERROR.

**Подключения из PHP** (ADR-043 п. 4–5, `services/AGENTS.md` §2):

- снимок выдачи — только в памяти и только целиком собранный; подключение,
  которого в нём нет, сразу теряет ключи, его идущие задачи прерываются
  с курсором (`revoked`), новые не выдаются;
- снимок без подтверждения дольше `CONNECTIONS_MAX_STALE` — задачи стоят
  все (`connections_stale`), ERROR в журнал и трекер один раз на переход;
- 401 Ozon — одно обновление выдачи и один повтор, затем ключ кабинета на
  паузе до новой версии ключей (`updated_at` выдачи), без срока; у ключа
  рекламы — свой счёт и своя пауза;
- `OZON_FETCHER_CONNECTION_ALLOWLIST` закрыт по умолчанию: пусто — ни одного
  подключения; только `marketplace_account_id`, поимённо.

**Ресурсы — первая волна** (ADR-043): начисления по дням, FBO v3,
каталог, карточки, кампании, расход и дневная статистика рекламы
(`planner.FirstWave`). Остальной код источника остаётся, планировщик его
ресурсы не ставит; включение — правка списка, не настройка. Первичная
загрузка — с M-2.

**Признак ограничения доступа Ozon** (ADR-043 п. 5): 403 на ресурсе, который
этим ключом уже отвечал 200 (попытка с той же версией ключей — `updated_at`
выдачи в `fetch_attempt.key_version` — завершилась успехом или записала
страницу, в том числе текущая), — остановка всех подключений: выдача задач
закрыта строкой `service_halt`, задачи, уже взятые процессом, прерываются
и возвращаются в очередь с курсором, задача, взятая до остановки, проверяет
её перед первым запросом; ERROR в журнал и трекер. Перед решением выдача
подключений обновляется: если версия ключей в ней уже другая, 403 — смена
ключа, задача повторяется через минуту без остановки и паузы. Снятие —
вручную:

```bash
ozon-fetcher -resume-halt   # в контейнере сервиса, после разбора причины
```

403 на ресурсе без прежнего 200 — пауза этого ресурса подключения на сутки
(`resource_pause`). 401 — свой путь, по версии ключей (см. «Подключения из PHP»).

Границы: сервис не пишет в БД PHP, не нормализует данные в факты и не
решает об идемпотентности по состоянию БД PHP (`services/AGENTS.md`).

## Запуск (dev)

```bash
make ozon-fetcher-up        # сборка образа, миграции, запуск (профиль services)
docker compose --profile services exec ozon-fetcher wget -qO- http://127.0.0.1:8080/ready
```

БД: логическая `ozon_fetcher` (и `ozon_fetcher_test` для тестов) с ролью
`svc_ozon_fetcher` на общем контейнере postgres песочницы. Их создаёт
`services/docker/postgres/init/01-ozon-fetcher.sql` — на пустом томе сам
postgres, на существующем `make ozon-fetcher-db`. Пароль — только для
песочницы. Без `DB_DSN` сервис работает без БД (`/ready` — `db: disabled`).

## Схема БД

Только техническое состояние, без сумм, ПДн, ключей и тел ответов
(`services/AGENTS.md` §5). Миграции `internal/store/migrations/` с рабочим
`down`: стартовая `00001_init.sql`, признак ограничения доступа
`00002_access_guard.sql`, пауза ключей по версии `00003_auth_key_version.sql`, счётчики исходов
`00004_attempt_outcome_hour.sql`; прогон —
`make ozon-fetcher-migrate`, проверка с пустой базы — в `make db-rebuild-check`.
Гейт выкладки — `ozon-fetcher -migrations-check`: ошибка при неприменённых
миграциях, выкладка останавливается до запуска контейнера; применение —
`ozon-fetcher -migrate` отдельным шагом (`docs/operations-checklist.md`,
«ozon-fetcher на проде»).

| Таблица | Назначение |
|---|---|
| `fetch_task` | Что должно быть загружено: `(company_id, marketplace_account_id, resource_type, period_key)`, статус (`pending → running → done \| not_ready \| failed_retryable \| failed_permanent`), приоритет, попытки, `next_run_at`, lease, курсор и номер следующей страницы, `content_hash` + `revision`, страницы ревизии для зависимых ресурсов |
| `fetch_attempt` | Журнал попыток (append-only): время, исход, HTTP-статус, страницы, байты |
| `raw_object` | Объект хранилища на страницу попытки: `storage_uri`, sha256 несжатых байтов, размеры |
| `connection_state` | Подключение: `last_served_at` (честная очередь); по каждому ключу — продавца и рекламы (`ads_*`) — отказы 401 версии ключей, сама версия (`auth_key_version`) и короткая пауза после первого отказа. Со второго отказа той же версии ключ стоит без срока до новой версии в выдаче |
| `connection_limit` | Пара (подключение, endpoint): ручное `rps_override`, адаптированная `rps_current` |
| `resource_stop` | Ресурс, снятый у Ozon (`obsolete_endpoint`), — не выдаётся никому |
| `planner_run` | Проход планировщика с перезагрузкой — один раз за дату на подключение |
| `service_halt` | Остановка выдачи задач всех подключений по признаку ограничения доступа (одна строка, снимается `-resume-halt`) |
| `resource_pause` | Пауза ресурса подключения на 403 без прежнего 200 |
| `attempt_outcome_hour` | Закрытые попытки подключения по исходу за час — для исходов за сутки в `/internal/fetch-status` без `COUNT(*)` по журналу попыток; часы старше двух суток удаляет сборщик просроченных lease |

Инварианты: у подключения не больше одной `running`-задачи на семейство API
(Seller и реклама — частичный уникальный индекс); страница и курсор пишутся
одной транзакцией; просроченный lease возвращает задачу в `pending`
с сохранённым курсором. Межарендаторно работают только операционные
функции с `AcrossConnections`/`AllConnections` в имени (CLAUDE.md §1):
индекс `fetch_task_ready` для выдачи задач ведёт временем, чтения одного
подключения — `company_id` первым.

Интеграционные тесты `internal/store` идут на `TEST_DB_DSN` (в песочнице —
`ozon_fetcher_test`), каждый пакет — в своей схеме; без переменной
пропускаются.

**Raw в хранилище** (`internal/rawstore`, ADR-044): бакет сырья Conwix, ключ
`conwix/svc/ozon-fetcher/v1/companies/{company_id}/accounts/{marketplace_account_id}/{resource_type}/{period_key}/{sha256}.{ext}.gz`,
gzip исходных байтов, `If-None-Match: *`, sha256 несжатых — в метаданных
и `raw_object`. Порт принимает подключение первым параметром; ключ
собирается из полей (при чтении — из строки `raw_object` и задачи),
сегменты вне формы отклоняются. Интеграционный тест — `TEST_S3_ENDPOINT`,
`TEST_S3_BUCKET` (в песочнице — `conwix-test`).

## Переменные окружения

Секреты — только env, значения не коммитятся (шаблон — `.env.example`).
Ключи Ozon в env не задаются: они приходят по подключению из PHP
(`marketplace-connections` v1) и живут только в памяти.

| Переменная | Назначение |
|---|---|
| `LISTEN_ADDR` | Адрес HTTP, по умолчанию `:8080` |
| `DB_DSN` | PostgreSQL сервисной БД; пусто — без БД |
| `S3_ENDPOINT`, `S3_REGION`, `S3_BUCKET`, `S3_ACCESS_KEY_ID`, `S3_SECRET_ACCESS_KEY` | Бакет сырья Conwix (ADR-024). На проде — пока ключ PHP (`RAW_STORAGE_*`, решение владельца 25.10.2026); по ADR-044 — отдельный пользователь с правами на префикс `conwix/svc/ozon-fetcher/`, открытая издержка в `docs/operations-checklist.md` |
| `SYMFONY_INTERNAL_BASE_URL` | Базовый URL PHP для `GET /api/internal/marketplace-connections`; пусто — загрузка выключена |
| `INTERNAL_AUTH_TOKEN` | Токен направления «сервис → PHP» |
| `SERVICE_API_TOKEN` | Токен направления «PHP → сервис» для `/internal/*` сервиса, ≥ 32 символов; пусто — `/internal/*` закрыт (401) |
| `CONNECTIONS_POLL_INTERVAL` | Интервал опроса выдачи подключений, по умолчанию `5m` |
| `MAX_PARALLEL_CONNECTIONS` | Сколько подключений загружается одновременно (по умолчанию 4) |
| `CONNECTIONS_MAX_STALE` | Сколько снимок выдачи живёт без подтверждения, по умолчанию `30m`; больше интервала опроса; дольше — все задачи стоят |
| `OZON_FETCHER_CONNECTION_ALLOWLIST` | `marketplace_account_id` через запятую — только эти подключения; пусто — ни одного (ADR-043 п. 5); не UUID — отказ запуска |
| `SENTRY_DSN`, `SENTRY_RELEASE`, `SENTRY_ENVIRONMENT` | GlitchTip: записи ERROR уходят событием; пустой DSN — только журнал |
| `OZON_BASE_URL`, `OZON_PERFORMANCE_BASE_URL` | Seller и Performance API (в песочнице — мок) |
| `TAIL_DAYS`, `RECONCILE_WEEKDAY`, `FINAL_DAY`, `PLAN_EVERY` | Окна планировщика: 3, 1 (пн), 10, 1h |
| `ACCRUAL_POSTINGS_DAYS`, `ACCRUAL_POSTINGS_DAILY_DAYS` | Начисления по отправлениям: глубина перечитывания (45) и ежедневная часть (14) |
| `ORDERS_FROM`, `ORDERS_TAIL_DAYS` | Отправления: первый день загрузки (МСК; пусто — с M-2, не раньше M-2) и глубина ежедневного перечитывания (30) |

## Внутренний API сервиса

| Метод | Назначение |
|---|---|
| `GET /health`, `GET /ready` | Живость и готовность БД, без токена |
| `GET /internal/fetch-status?limit=&cursor=` | Состояние загрузки по подключениям и ресурсам за окно M-2…M: статусы задач, пропуски (до 31 на ресурс и общее число), последняя успешная загрузка, лимиты, исходы за сутки, паузы; ключ, стоящий до новых ключей, — `auth_paused_key_version` / `ads_auth_paused_key_version`. Кабинеты страницей: `limit` 1–200 (по умолчанию 50), `cursor` — `next_cursor` прошлой страницы (`marketplace_account_id`); иначе 422 |
| `GET /internal/coverage?from=YYYY-MM-DD&to=YYYY-MM-DD` | Покрытие «ресурс × период → подключений по статусам», контракт `services/_contracts/service-coverage/v1`; окно не больше 93 дней — это и есть лимит списка |

`/internal/*` — под токеном `SERVICE_API_TOKEN`.

## Тесты и гейты

```bash
make ozon-fetcher-test       # go test -race, интеграционные store, порог покрытия internal/
make ozon-fetcher-vet        # go vet
make ozon-fetcher-lint       # golangci-lint fmt --diff + run (.golangci.yml)
make ozon-fetcher-vulncheck  # govulncheck
```

Тулчейн — контейнеры `ozon-fetcher-dev` (golang:1.27.3, cgo для `-race`)
и `ozon-fetcher-lint` профиля `services`. Состав гейтов — `services/go.md`
«Гейты»; в конвейере — задача `services`.

## Стенд: сквозной прогон в песочнице

PHP-выдача → сервис → мок Ozon (`test/stand/mock`) → raw в SeaweedFS.
Реальных ключей и обращений к Ozon нет: мок принимает ключ вида
`client-<метка>` / `key-<метка>`, метка `cab-401` всегда получает 401.

1. Подключения с фиктивными ключами:
   `make api-console CMD="app:identity:seed-ozon-sandbox-company 'E2E A' client-e2e-a key-e2e-a"`
   (и так же `client-cab-401 key-cab-401` для проверки 401).
2. Токен «сервис → PHP» на время прогона — строкой `INTERNAL_AUTH_TOKEN=…`
   (≥ 32 символов) в `api/.env.local`; после прогона убрать.
3. `make ozon-fetcher-migrate`, затем мок и сервис в сети песочницы:

```bash
docker compose --profile services run -d --name ozon-stand-mock ozon-fetcher-dev go run ./test/stand/mock
docker compose --profile services run -d --name ozon-fetcher-e2e \
  -e SYMFONY_INTERNAL_BASE_URL=http://api-internal -e INTERNAL_AUTH_TOKEN=<токен из шага 2> \
  -e SERVICE_API_TOKEN=<≥ 32 символов> -e OZON_BASE_URL=http://ozon-stand-mock:8090 \
  -e S3_ENDPOINT=http://s3:8333 -e S3_REGION=ru-1 -e S3_BUCKET=conwix-dev \
  -e S3_ACCESS_KEY_ID=sandbox -e S3_SECRET_ACCESS_KEY=sandbox \
  -e OZON_FETCHER_CONNECTION_ALLOWLIST=<marketplace_account_id через запятую> \
  -e CONNECTIONS_POLL_INTERVAL=20s -e CONNECTIONS_MAX_STALE=1m \
  ozon-fetcher-dev go run ./cmd/ozon-fetcher
```

4. Проверка: `GET /internal/fetch-status` (задачи `done`), объекты под
   `conwix/svc/ozon-fetcher/v1/` бакета `conwix-dev`, счётчики мока —
   `GET http://ozon-stand-mock:8090/stats`. Отзыв — `state = 'revoked'`
   у подключения в PHP; срок снимка — убрать токен из `api/.env.local`.
5. `docker rm -f ozon-fetcher-e2e ozon-stand-mock`.

## План

`docs/plan/ozon-fetcher.md`, ADR-043.
