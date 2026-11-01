# Этап 1 product-research-ozon — отчёт

Статус: реализация и локальная приёмка выполнены; независимое ревью и CI
предшествуют закрытию. Подробный [чек-лист](product-research-ozon-stage1.md).

## Результат

- FastAPI-каркас /health, /ready и защищённые /internal/v1/companies/*.
  Токены направлений различаются; авторизация предшествует разбору запроса.
  Тело до 65536 байт / 5 секунд, ошибки по общему контракту без входных данных.
- Версия v1 схем/общих примеров категорий, запуска, прогресса, метаданных,
  ошибок; PHP и Python проверяют один corpus. Успешные операции не имитируются:
  пока 503 not_available, collection_enabled=false.
- Свои dev/test БД и роль, pg_hba запрещает подключения в остальные БД.
  Alembic baseline с проверкой ревизии, ручной migrate и рабочим down;
  предметных таблиц пока нет, монолит не изменяет свою схему.
- Company-first S3-порт строит ключ по UUIDv7, проверяет hash/размер и scope,
  пишет If-None-Match: *, не принимает произвольного ключа.
- Общий безопасный JSON-форматтер для API/worker/Uvicorn и зависимостей;
  traceback, URL/query, заголовки, суммы и тела не выводятся.
- Dev/runtime образы non-root, runtime без тестовых инструментов, hash-lock
  из утверждённого графа. Только internal сеть, без host ports/Traefik/egress.
  Worker выключен и не получает задач; его преждевременное включение запрещено.
- Make/CI и db-rebuild-check расширены гейтами нового сервиса.

## Проверки

- Red: исходные тесты security не собирались без приложения; затем Green.
- Red: лишние/дублирующиеся query-параметры ошибочно давали 503; исправлено,
  тесты получили ожидаемый 422.
- `make product-research-ozon-check`: Ruff check/format, mypy strict (23 файла),
  2 import-linter контракта, **27 тестов** unit/contract/integration,
  health/readiness/401/503 smoke, non-root/no-dev-tools — успешно.
  Локальный лог: var/product-research-ozon-stage1-check.log.
- `make product-research-ozon-stage0-check`: hash-install, pip check/audit,
  imports, Ruff, mypy и 2 офлайн-эталона успешно; evidence check.EcUwEV.
- PHP corpus: 1 тест, 112 assertions успешно, включая method_not_allowed.
- `make lint stan deptrac structure-check` — успешно. `make api-types-check` —
  успешно, публичный API не менялся.
- Rebuild: `make db-rebuild-check TEST_WORKERS=2` с отдельным Compose project
  research-stage1-rebuild, без host ports: **db-rebuild-check: OK**, включая
  26 тестов Python на пустой инфраструктуре. Текущие PostgreSQL/S3 dev-тома
  не удалялись. Лог: var/product-research-ozon-stage1/rebuild.log.

## Границы и известные ограничения

Задания/outbox, поиск каталога, реальная загрузка и UI — следующие этапы.
Семантические проверки месячных периодов не подменяются JSON Schema:
пока проверяется формат; календарь/дедупликация — в предметной реализации.
Идемпотентный приём запуска и прогресс описаны, но 202/готовый результат
не выдаются до реализации БД заданий.

S3 dev не проверяет ключи. До production-включения нужны отдельные права
на префикс и live-приёмка ключа; HeadBucket не доказывает возможность записи.
Production compose/секреты Python не добавлены и сервис на проде не включается.
Схема монолита и боевые данные не меняются.

Закреплённая Starlette сообщает одно предупреждение о будущей замене httpx
в TestClient. Тесты работают; предупреждение не скрывается, новая библиотека
вне утверждённого графа не добавляется.

Claude CLI отсутствует; обязательные 5A/5B выполняются новыми независимыми
Codex CLI-сессиями по правилам проекта. Реквизиты и разбор будут сохранены здесь.
Чужие локальные изменения в пакет и коммит не включаются.

Исходные ревью выявили 3 замечания; все приняты, исправлены и покрыты
регрессиями. Из-за изменения Git-снимка эти прогоны не засчитаны как финальные.
Подробности: [журнал ревью](product-research-ozon-stage1-review.md).
