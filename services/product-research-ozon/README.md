# product-research-ozon

Этап 1: внутренний FastAPI-каркас, baseline Alembic, отдельные dev/test БД,
company-scoped S3-порт и выключенный worker. Основание — принятый ADR-050.
Загрузка MPStats, задания и пользовательские экраны ещё не реализованы.

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
`product-research-worker`, по умолчанию выключен. Даже при явном старте он
фиксирует disabled и завершает работу, а worker_enabled=true отклоняется.
Production compose/выкладка Python не добавлены: активация — будущий этап.

/health проверяет процесс; /ready проверяет БД, точную baseline-ревизию и
HeadBucket S3, возвращая collection_enabled=false. API не применяет миграции
при старте и не создаёт bucket. Проверка S3 требует HeadBucket наряду с правами
на префикс. Бакеты общие с монолитом: conwix-dev / conwix-test, свой только префикс.
В dev SeaweedFS не проверяет credentials: границу компаний
проверяет порт. Production потребует отдельного ключа префикса, общего ключа
монолита здесь нет. [HeadBucket](https://docs.aws.amazon.com/boto3/latest/reference/services/s3/client/head_bucket.html)
не доказывает права записи; live приёмка отдельного S3-ключа — до включения.

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
