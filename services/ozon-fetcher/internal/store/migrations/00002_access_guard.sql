-- Признак ограничения доступа Ozon (ADR-043 п. 5).

-- +goose Up

-- Остановка всей загрузки: 403 на методе, который этим же ключом уже отвечал
-- 200. Одна строка; снимается только вручную (ozon-fetcher -resume-halt).
-- Подключение и ресурс — какой отказ её вызвал, для разбора.
CREATE TABLE service_halt (
    id                     BOOLEAN     PRIMARY KEY DEFAULT true CHECK (id),
    company_id             UUID        NOT NULL,
    marketplace_account_id UUID        NOT NULL,
    resource_type          TEXT        NOT NULL,
    reason                 TEXT        NOT NULL,
    halted_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Пауза ресурса подключения: 403 на методе, который у ключа ещё не отвечал
-- 200 (у ключа нет роли на метод) — остальные ресурсы подключения идут.
CREATE TABLE resource_pause (
    company_id             UUID        NOT NULL,
    marketplace_account_id UUID        NOT NULL,
    resource_type          TEXT        NOT NULL,
    paused_until           TIMESTAMPTZ NOT NULL,
    reason                 TEXT        NOT NULL,
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (company_id, marketplace_account_id, resource_type)
);

-- +goose Down
DROP TABLE resource_pause;
DROP TABLE service_halt;
