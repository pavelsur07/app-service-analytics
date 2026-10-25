-- Исходы попыток за сутки — счётчиками, а не COUNT(*) по журналу попыток
-- (services/AGENTS.md §5).

-- +goose Up

-- Число закрытых попыток подключения по исходу за час. Пишется в той же
-- транзакции, что закрывает попытку (FinishTask, сборщик просроченных
-- lease); часы старше двух суток удаляет сборщик просроченных lease на
-- каждом тике, по всем подключениям. Отчёт /internal/fetch-status
-- суммирует последние 24 часа.
CREATE TABLE attempt_outcome_hour (
    company_id             UUID        NOT NULL,
    marketplace_account_id UUID        NOT NULL,
    hour                   TIMESTAMPTZ NOT NULL,
    outcome                TEXT        NOT NULL,
    attempts               INTEGER     NOT NULL,
    PRIMARY KEY (company_id, marketplace_account_id, hour, outcome)
);

-- Очистка по всем подключениям — по времени (индекс следует за запросом,
-- CLAUDE.md §1).
CREATE INDEX attempt_outcome_hour_hour ON attempt_outcome_hour (hour);

-- +goose Down
DROP TABLE attempt_outcome_hour;
