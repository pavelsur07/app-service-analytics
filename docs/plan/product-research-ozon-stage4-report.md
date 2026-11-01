# product-research-ozon — отчёт этапа 4

Дата: 01.11.2026. Статус: завершён; код объединён, приёмка и независимое ревью
успешны, штатная production-выкладка и smoke подтверждены. Python остаётся выключен до этапа 8.
Основание: [план и матрица](product-research-ozon-stage4.md), ADR-050.

## Результат

MonthCollector реализует start/step/read одной категории и календарного месяца.
Один step получает не больше одной страницы по 500 строк; курсор сохраняется,
ожидание лимита возвращает управление. Sleep и сетевого I/O в SQL-транзакции нет.
FBS=1, RUB, пустой filterModel, revenue DESC/id ASC. Текущий месяц — только явно,
до вчера по Europe/Moscow; даты и число дней зафиксированы в снимке.

Исходные JSON bytes не сериализуются повторно. Сохраняются все строки/поля,
включая неизвестные поля, нулевые продажи и остатки. Parser проверяет границы,
точную длину, неизменный total, положительные SKU и уникальность; числа разбирает
через Decimal без float. DecimalException, дубли JSON keys, nonstandard constants,
oversize и некорректные страницы становятся безопасным отказом, а не ready.

S3-страница предшествует DB-регистрации, S3-манифест — атомарной публикации ready.
If-None-Match:* и перечитывание bytes/checksum защищают неизменяемость и повтор.
DB lease/CAS и generation fencing повторно проверяются при регистрации/публикации.
Межстраничные SKU проверяются пакетно через UNIQUE, временные ключи удаляются
при завершении. Сбой до commit не публикует ready; потерянный ответ после
успешного commit восстанавливается чтением готового результата без нового API.

Общий provider gate защищает токен, одновременность, RPM, Retry-After и auth pause.
202/429 сохраняют next_attempt_at; старое поколение ключа не публикует результат.
Журнал содержит безопасные статусы/ID/длительность: deferred — WARNING, error —
ERROR; сбой logging не ломает шаг. Токен только в заголовке HTTP-адаптера.

Чтение принимает company/source/snapshot/page и пересобирает S3-ссылки из scoped
DB-записей, проверяет размер/hash. Манифест сверяется с журналом/параметрами.
Время технических событий и serialization нормализованы в aware UTC независимо
от SQL-session TimeZone. Бизнес-календарь остаётся Europe/Moscow.
Total=0 — готовый снимок с предупреждением empty_category_or_renamed;
ошибка источника не выдаётся за пустой успешный отчёт.

## Подтверждённая приёмка

| Проверка | Результат |
|---|---|
| Полный Python-набор после исправлений | **119 PASS** |
| Ruff / mypy strict / import-linter | PASS, без baseline/ослабления правил |
| Runtime smoke обновлённого образа | health/readiness/auth/unavailable PASS; UID10001, инструментов dev нет |
| Полный fresh db-rebuild-check окончательного кода, TEST_WORKERS=4 | **807 unit /9134 assertions; 889 integration /23326; 419 functional /4129; Go; 119 Python; OK** |
| Офлайн-регрессия исходных fixture/CSV | **2 PASS**, без live MPStats |
| Полный месяц через HTTP MockTransport → настоящие PostgreSQL/S3 → чтение | **102 страницы, 50702 SKU, 133878270 исходных bytes, все SHA-256 совпали** |
| Точные контрольные суммы показателей | **3541442 продаж, 5172621135 RUB**, без float |
| Сохранение схемы/строк | 62 поля в каждой строке, товары с нулевыми продажами сохранены |
| M01: down до 0002 / up / check | Каталог и S3 bytes сохранены; новая схема восстановлена |

Реальные PostgreSQL/S3 тестируют конкурентные claims, lost lease и generation
после запроса/после manifest write, дубли SKU, обрыв между страницами, ошибки
S3/DB, commit ack loss, corruption/missing object, company/source scope,
неизменность предыдущих страниц и повтор ready без нового запроса.
В памяти только текущая страница и ограниченные метаданные манифеста.

Все 41 колонки ручного CSV сопоставлены в
[карте 0.3](product-research-ozon-columns.md); API CSV 49 и REST 62 — отдельные
профили. Полный январь/API CSV и ручной февраль/FBS=1 перепроверены офлайн.
Известные отличия percent_from_revenue и части вычисляемых полей/метаданных
перечислены в исходных отчётах; stage 4 их не пересчитывает и не скрывает.

Воспроизводимые команды и локальные логи var/product-research-ozon-stage4/:

- `make product-research-ozon-lint product-research-ozon-vet
  product-research-ozon-architecture product-research-ozon-test` — review-fix-gates.log.
- `make product-research-ozon-smoke` — review-fix-smoke.log.
- `make db-rebuild-check TEST_WORKERS=4 COMPOSE='docker compose -p
  research-stage4-rebuild -f .../docker-compose.yml -f .../rebuild.override.yml'`
  — rebuild-reviewed.log. Проверены отдельные local PostgreSQL/S3/Go volumes
  и отсутствие host ports; рабочие dev-тома сохранены. Изолированные контейнеры
  после проверки остановлены, тома не удалены.
- `python -m unittest discover -s tests -p test_stage0_fixtures.py` в dev-контейнере
  — fixture-regression.log.

## TDD, повторы и независимое ревью

Начальный Red — отсутствующий domain/month; 17 pure tests затем Green.
Четыре regression cases замечаний (DecimalException, WARNING, ERROR, PostgreSQL
TimeZone=Europe/Moscow) сначала упали, после исправлений прошли: review-red.log
и review-green.log. Полный набор и fresh rebuild повторены после исправлений.

Ранние прогоны не скрываются: первый rebuild остановился до тестов на Docker Hub
metadata network failure. Второй с TEST_WORKERS=2 дал в существующем PHP integration
3 ошибки/10 отказов Ingestion/SalesPlanning, включая resource budgets. Отдельный
baseline трёх классов — 67/1192 PASS; два полных повтора с четырьмя процессами
успешны (115 Python до исправлений, 119 после). Причина первого общего отказа
не объявляется доказанной; код/лимиты/утверждения не ослаблялись.

Claude CLI отсутствует; выполнены четыре новые независимые сессии Codex CLI
0.166.0, gpt-6.1-sol/high. Первый пакет: 5A — 2 принятых; 5B дополнительно — 2.
Три исправления кода и одно признанное процессное нарушение: первое ревью
отправлено до завершения rebuild. Новый пакет подготовлен только после успешного
fresh rebuild окончательного кода. Итоговые 5A/5B complete без новых findings,
rule gaps или nitpicks; дополнительно принятых 0/0, отклонённых 0.
Полные JSON, hashes и разбор — в [отчёте ревью](product-research-ozon-stage4-review.md).
Ревьюеры тесты не запускали; локальные прогоны и CI — отдельные доказательства.

## Доставка

Код объединён через [PR #309](https://github.com/pavelsur07/app-service-analytics/pull/309),
merge `d1737a8b3ddbe4798cb53d5968a70e37d19a903d`.
Итоговый [CI ветки 41319838686](https://github.com/pavelsur07/app-service-analytics/actions/runs/41319838686)
успешен по всему diff ветки: services, contract/audit и E2E; backend/frontend
штатно skipped по фильтрам, их локальный полный rebuild выполнен отдельно.
[Master CI/deploy 41320437973](https://github.com/pavelsur07/app-service-analytics/actions/runs/41320437973)
успешен; образ **757-d1737a8**, новых Symfony-миграций не было.
Production smoke:

- APP_SOURCE_COMMIT точно совпал с d1737a8b3ddbe4798cb53d5968a70e37d19a903d.
- У семи реально разрешённых контроллеров флаг false; прямой categories guard
  вернул 404 feature_disabled до чтения данных, без записей.
- Публичный seller ping вернул версию 757-d1737a8; categories без seller-сессии
  с валидным UUIDv7 маршрута вернул 401.
- Все 12 контейнеров running; заданные healthcheck healthy. Первая проверка
  увидела ozon-fetcher в starting; повтор после готовности успешен.
  Отсутствующие healthcheck не объявляются healthy.

Лог: var/product-research-ozon-stage4/production-smoke-d1737a8.log.
Python production-сервис/его новая БД не активировались; Alembic применяется
при контролируемом включении этапа 8. Это проверка штатной доставки при закрытом
флаге, а не live-приёмка MPStats. G01–G08 закрыты; постоянные заключения и
результаты сохранены в документах этапа. Финальное изменение только Markdown
не требует нового независимого ревью по порогу AGENTS.md.

## Границы и ограничения

Одна новая Alembic-миграция 0003_month_snapshots; старые не переписаны.
PostgreSQL содержит только технический журнал/метаданные, тела/суммы остаются S3.
Down сохраняет каталог/S3, но удаляет журнал снимков: до production-отката
требуется backup/export журнала для сохранения связей с raw.

Диспетчер/retry budget/outbox — этап 5, месячный cache — 6, UI — 7,
production Python/его миграция/реальная квота/egress/отдельные S3-права — этап 8.
Runs/snapshots HTTP остаются not_available; worker и collection readiness
выключены. Контракт v1, Symfony и публичные API-схемы не менялись.
Технический rows/pages/bytes бюджет задаётся явно и не объявляется квотой MPStats.
Orphan-объекты не публикуются/не считаются кэшем; автоматического GC нет.
Политики восстановления/retention/бюджетов обязательны до включения этапа 8.
Проверки counts/ID не доказывают snapshot isolation изменяемого источника.
Живая загрузка MPStats в production в этом этапе не заявляется.
Чужие B17/B18, securety_chek, temp и удаление onboarding-спецификации исключены.
