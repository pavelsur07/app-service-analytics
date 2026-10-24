-- Dev и тесты: роль и логические БД ozon-fetcher на postgres песочницы
-- (ADR-043 п. 3, services/AGENTS.md §5). Пароль — только для песочницы;
-- на проде роль и БД создаёт выкладка, пароль — из секретов окружения.
--
-- Идемпотентно: скрипт выполняет и initdb на пустом томе
-- (/docker-entrypoint-initdb.d), и `make ozon-fetcher-db` на уже живом.
SELECT 'CREATE ROLE svc_ozon_fetcher LOGIN PASSWORD ''ozon_fetcher_dev'''
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'svc_ozon_fetcher')\gexec

SELECT 'CREATE DATABASE ozon_fetcher OWNER svc_ozon_fetcher'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'ozon_fetcher')\gexec

-- Интеграционные тесты сервиса: своя БД, схема на тестовый пакет
-- (internal/store/storetest).
SELECT 'CREATE DATABASE ozon_fetcher_test OWNER svc_ozon_fetcher'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'ozon_fetcher_test')\gexec

-- Граница §1: роль сервиса к БД Conwix не подключается.
REVOKE CONNECT ON DATABASE app FROM PUBLIC;
