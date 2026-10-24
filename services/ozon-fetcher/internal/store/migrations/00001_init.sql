-- +goose Up
-- Журнал загрузок raw сервиса (ADR-043). Только техническое состояние:
-- без сумм, ПДн, ключей и тел ответов (services/AGENTS.md §5).
--
-- Схема источника (ВашФинДир, миграции 00001–00006) собрана в одну
-- миграцию: connection_ref заменён парой company_id + marketplace_account_id
-- (UUID подключения Conwix). Таблицы выдачи фактов источника (sync_job,
-- checkpoint, outbox_batch) не переносятся: код их не использует, выдача
-- фактов — вторая волна.
--
-- Индекс следует за запросом (CLAUDE.md §1): выдача задач диспетчеру идёт
-- по всем компаниям — ведущий столбец время и статус; чтения одного
-- подключения — company_id первым.

-- Что должно быть загружено. period_key: YYYY-MM-DD | YYYY-MM | ref.
CREATE TABLE fetch_task (
    company_id             UUID        NOT NULL,
    marketplace_account_id UUID        NOT NULL,
    resource_type          TEXT        NOT NULL,
    period_key             TEXT        NOT NULL,
    status                 TEXT        NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'running', 'done', 'not_ready', 'failed_retryable', 'failed_permanent')),
    priority               SMALLINT    NOT NULL DEFAULT 0,
    attempts               INTEGER     NOT NULL DEFAULT 0,
    next_run_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    lease_owner            TEXT,
    lease_until            TIMESTAMPTZ,
    attempt_id             UUID,                 -- текущая попытка (running)
    cursor                 TEXT,                 -- курсор следующей страницы
    next_page_no           INTEGER     NOT NULL DEFAULT 1,
    page_hashes            TEXT        NOT NULL DEFAULT '', -- sha256 страниц текущей загрузки через запятую
    content_hash           CHAR(64),             -- sha256 последней успешной загрузки
    -- Страницы текущей загруженной ревизии (sha256 через запятую, по порядку):
    -- зависимый ресурс читает номера из страниц ревизии другого ресурса.
    content_pages          TEXT        NOT NULL DEFAULT '',
    revision               INTEGER     NOT NULL DEFAULT 0,
    -- Перезагрузка пришла, пока задача в работе: применяется после текущей
    -- попытки (загрузка с первой страницы), lease не прерывается.
    refresh_pending        BOOLEAN     NOT NULL DEFAULT false,
    -- Начало загрузки (запись первой страницы) и ревизии (копия при done):
    -- ревизия загружена целиком после этого момента.
    load_started_at        TIMESTAMPTZ,
    content_started_at     TIMESTAMPTZ,
    last_error_class       TEXT,
    last_success_at        TIMESTAMPTZ,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, marketplace_account_id, resource_type, period_key),
    CHECK ((status = 'running') = (lease_until IS NOT NULL))
);

-- Выдача готовых задач по всем компаниям.
CREATE INDEX fetch_task_ready ON fetch_task (next_run_at, priority DESC)
    WHERE status IN ('pending', 'not_ready', 'failed_retryable');
-- Одна running-задача на (подключение, семейство API): реклама (ozon.ads.*) —
-- свой ключ Performance и свои лимиты. ClaimNext проверяет то же выражение.
CREATE UNIQUE INDEX fetch_task_one_running_per_connection_family
    ON fetch_task (company_id, marketplace_account_id, (resource_type LIKE 'ozon.ads.%'))
    WHERE status = 'running';
-- Просроченные lease.
CREATE INDEX fetch_task_lease ON fetch_task (lease_until) WHERE status = 'running';

-- Журнал попыток, append-only. Ключ начинается с подключения: попытка
-- не ищется по одному идентификатору (CLAUDE.md §1).
CREATE TABLE fetch_attempt (
    company_id             UUID        NOT NULL,
    marketplace_account_id UUID        NOT NULL,
    attempt_id             UUID        NOT NULL,
    resource_type          TEXT        NOT NULL,
    period_key             TEXT        NOT NULL,
    trace_id               TEXT        NOT NULL,
    lease_owner            TEXT        NOT NULL,
    started_at             TIMESTAMPTZ NOT NULL,
    finished_at            TIMESTAMPTZ,
    outcome                TEXT,                 -- done | unchanged | <класс ошибки> | released
    http_status            INTEGER,
    pages                  INTEGER     NOT NULL DEFAULT 0,
    bytes                  BIGINT      NOT NULL DEFAULT 0,
    PRIMARY KEY (company_id, marketplace_account_id, attempt_id)
);
CREATE INDEX fetch_attempt_task
    ON fetch_attempt (company_id, marketplace_account_id, resource_type, period_key, started_at DESC);

-- Объект хранилища на страницу попытки; подключение — то же, что у попытки.
CREATE TABLE raw_object (
    company_id             UUID        NOT NULL,
    marketplace_account_id UUID        NOT NULL,
    attempt_id             UUID        NOT NULL,
    page_no                INTEGER     NOT NULL,
    storage_uri            TEXT        NOT NULL,
    sha256                 CHAR(64)    NOT NULL,     -- от несжатых байтов
    size_bytes             BIGINT      NOT NULL,
    stored_bytes           BIGINT      NOT NULL,     -- после gzip
    content_type           TEXT        NOT NULL,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, marketplace_account_id, attempt_id, page_no),
    FOREIGN KEY (company_id, marketplace_account_id, attempt_id)
        REFERENCES fetch_attempt (company_id, marketplace_account_id, attempt_id)
);

-- Состояние подключения: честная очередь, пауза и отказы ключа подряд.
CREATE TABLE connection_state (
    company_id             UUID        NOT NULL,
    marketplace_account_id UUID        NOT NULL,
    last_served_at         TIMESTAMPTZ,
    suspended_until        TIMESTAMPTZ,
    suspend_reason         TEXT,
    auth_failures          INTEGER     NOT NULL DEFAULT 0,
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, marketplace_account_id)
);

-- Лимит пары (подключение, endpoint).
CREATE TABLE connection_limit (
    company_id             UUID          NOT NULL,
    marketplace_account_id UUID          NOT NULL,
    endpoint               TEXT          NOT NULL,
    rps_override           NUMERIC(10,4) CHECK (rps_override IS NULL OR rps_override > 0),
    rps_current            NUMERIC(10,4) CHECK (rps_current IS NULL OR rps_current > 0),
    updated_at             TIMESTAMPTZ   NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, marketplace_account_id, endpoint)
);

-- Ресурс, остановленный для всех подключений (obsolete_endpoint).
CREATE TABLE resource_stop (
    resource_type TEXT        PRIMARY KEY,
    reason        TEXT        NOT NULL,
    stopped_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Проход планировщика с перезагрузкой — один раз за дату на подключение:
-- рестарт и второй экземпляр не дублируют перезагрузку.
CREATE TABLE planner_run (
    company_id             UUID        NOT NULL,
    marketplace_account_id UUID        NOT NULL,
    kind                   TEXT        NOT NULL,
    run_date               DATE        NOT NULL,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, marketplace_account_id, kind, run_date)
);

-- +goose Down
DROP TABLE planner_run;
DROP TABLE resource_stop;
DROP TABLE connection_limit;
DROP TABLE connection_state;
DROP TABLE raw_object;
DROP TABLE fetch_attempt;
DROP TABLE fetch_task;
