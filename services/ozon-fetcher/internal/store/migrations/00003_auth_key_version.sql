-- Отказ ключа подключения привязан к версии ключей (ADR-043 п. 4).

-- +goose Up

-- 401 Ozon — одно обновление выдачи и один повтор, затем пауза до новых
-- ключей, а не до времени: счётчик отказов считается по версии ключей
-- (updated_at выдачи), с которой шли запросы, и сбрасывается, когда
-- версия другая. Выдача задач закрыта, пока отказов два и выданная версия
-- та же. У рекламы свой ключ — свои счётчик, версия и короткая пауза.
ALTER TABLE connection_state
    ADD COLUMN auth_key_version     TIMESTAMPTZ,
    ADD COLUMN ads_suspended_until  TIMESTAMPTZ,
    ADD COLUMN ads_auth_failures    INTEGER     NOT NULL DEFAULT 0,
    ADD COLUMN ads_auth_key_version TIMESTAMPTZ;

-- +goose Down
ALTER TABLE connection_state
    DROP COLUMN ads_auth_key_version,
    DROP COLUMN ads_auth_failures,
    DROP COLUMN ads_suspended_until,
    DROP COLUMN auth_key_version;
