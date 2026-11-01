# product-research-ozon — отчёт этапа 5

Дата: 01.11.2026. Статус: завершён. Код объединён через
[PR #311](https://github.com/pavelsur07/app-service-analytics/pull/311),
merge `3039691cde4916438c1dbaf85346dae4340cd4bb`. Миграция Symfony, штатная выкладка и production smoke
подтверждены. Python production остаётся выключен до этапа 8.
[План, TDD и матрица](product-research-ozon-stage5.md),
[полные независимые заключения](product-research-ozon-stage5-review.md).

## Результат

Seller POST `projects/{projectId}/runs` сохраняет запуск, неизменные месячные
параметры и outbox одной ORM-транзакцией, увеличивает версию проекта и пишет audit.
Повтор requestId с прежним вводом возвращает прежний run даже после полуночи;
другой ввод и второй активный запуск получают 409. Даты не расширяются при
отложенной доставке. User HTTP не вызывает Python. GET run читает локальную
company-scoped проекцию прогресса, до 24 месяцев одним DBAL-запросом.

После commit Messenger уведомляет существующий async_ingestion без новых
транспортов/брокеров. Потерянное уведомление и delivery ack восстанавливает
`app:research:process-runs <company-uuidv7> --once`; без once цикл раз в 60 секунд.
Relay/poll используют DB leases и проверяют company/run/source, frozen periods,
монотонную revision и уже готовые результаты. Неудачная доставка остаётся в outbox.

Python атомарно принимает run/jobs/начальные snapshot ID; одинаковый payload
идемпотентен. Повтор 202 остаётся receipt queued/revision1, GET возвращает текущую
revision. Worker выбирает разрешённые компании/месяцы справедливо, FOR UPDATE
SKIP LOCKED, выполняет один bounded step и сохраняет ожидания без sleep на задаче.
Пустой allowlist закрыт, worker/collection выключены по умолчанию.

Network/5xx/временная инфраструктура — три повтора страницы, 60/120/240 секунд.
Счётчик подтверждённых страниц сохраняет сброс бюджета после append/crash,
включая total0. Apply атомарно сохраняет соответствующий retry_page. Durable outcome revision
и acknowledgement применяют сохранённый отказ один раз до нового I/O.
Истёкшее исполнение с неизвестным исходом консервативно считается одним отказом:
это может уменьшить оставшиеся попытки при crash до I/O, зато ограничивает
неопределённые повторы. Подтверждённые 202/429 не расходуют network budget.
Полнота — одна новая попытка месяца целиком с новым snapshot, дедлайн 24 часа.
Ready commit до дедлайна восстанавливается без API даже после дедлайна.

401/403 ставят общую auth pause токена; активные месяцы запуска завершаются,
готовые сохраняются. Snapshot cooldown не создаёт busy loop на paused source.
Parent-job lease и generation fencing действуют при публикации и при каждом apply,
включая terminal error старого worker после rotation. Все чтения scoped;
секретов, marketplace bodies/сумм/S3 keys в HTTP и событиях нет.

Новый запуск того же проекта выбирает только отсутствующие/неудачные закрытые
месяцы; partial всегда обновляется. Ready история не меняется. Межпроектный кэш
и агрегат статуса проекта остаются этапом 6, UI — этапом 7. Старые project responses
сохраняют draft-контракт до этапа 6; опубликованные v1 schema/examples не менялись.

## Проверки

| Проверка | Результат |
|---|---|
| Полный итоговый fresh db-rebuild-check | PASS: 811 PHP unit / 896 integration / 420 functional, Go, 153 Python |
| PHP assertions финального rebuild | 9150 / 23440 / 4166 |
| Go race / coverage internal | PASS, 87.7% при пороге 70% |
| Ruff / mypy strict / import-linter | PASS, без ослабления правил |
| PHP lint / PHPStan / Deptrac / structure / schema / api-types | PASS, пустой baseline сохранён |
| Реальный сквозной PHP → Python HTTP → PostgreSQL/S3 → PHP | Три ready-месяца, PASS; fixture-only/synthetic provider |
| Исходные 102 страницы месяца / 50702 SKU | Legacy regression PASS, исходные bytes и суммы не менялись |
| Up/down/up обеих схем, own DB/HBA isolation | PASS, raw и каталог сохраняются; восстановление журналов требует backup |
| Реализация внутреннего HTTP v1 / seller OpenAPI/TS | PASS, исходный v1 не изменён |
| Runtime smoke | Health/readiness/auth/unavailable PASS, UID10001, dev tools отсутствуют |

Финальный CI всего diff:
[41333118709](https://github.com/pavelsur07/app-service-analytics/actions/runs/41333118709)
— backend, frontend, services, contract/audit, E2E PASS до merge.

Локальные команды и результаты: var/product-research-ozon-stage5/:
`rebuild-final4.log` — полный fresh rebuild изолированного local проекта
research-stage5-rebuild, TEST_WORKERS=4; проверены отдельные тома и отсутствие
host ports, основное dev-окружение сохранено. `review3-final-gates.log / review3-runtime-smoke.log` —
итоговые Python lint/vet/architecture/runtime; `review-php-gates.log` — PHP/API gates.
Изолированные контейнеры остановлены после проверки без удаления томов.

TDD: первоначальные Red доменной политики/DTO → Green; четыре regression cases
первого 5B и по два обоих следующих 5B сначала воспроизведены, затем Green.
`review-red.log`, `review2-red.log`, `review3-red.log`; итоговый полный набор — 153 PASS.
Ранний CI остановился на отсутствии var в чистом checkout smoke, исправлен. Следующий CI выявил случайную неизменность tampered token старого теста курсора; генерация всегда отличного suffix исправлена без изменения production decoder.
Промежуточный подпакет на общей testDB был запущен параллельно с migration test
и получил ошибки схемы; этот прогон не засчитан, последующие полные и fresh
прогоны успешны. Проверки и лимиты не ослаблялись.

## Независимое ревью

Claude CLI отсутствует; применена разрешённая замена Codex CLI в новых сессиях.
Четыре раунда 5A/5B: 2+3, затем 0+2, затем 0+2, итог 0+0 новых уникальных принятых замечаний.
Все 9 приняты, исправлены и перепроверены; отклонённых/пробелов правил нет.
Финальные оба заключения complete без findings. Полные JSON и hashes хранятся
в постоянном отчёте ревью; var/review не объявляется архивом.

## Доставка

[Master CI/deploy 41333723441](https://github.com/pavelsur07/app-service-analytics/actions/runs/41333723441),
образ **765-3039691**. Новый образ штатно остановился на гейте неприменённой миграции.
Агент проверил source SHA нового одноразового api-контейнера и отдельно применил
только `DoctrineMigrations\Version20261101164957`, проверил up-to-date/schema,
перезапустил failed deploy. Автоматического применения миграции в workflow нет.

Production smoke: APP_SOURCE_COMMIT точно совпал с merge SHA; девять реально
разрешённых контроллеров имеют flag=false. Прямые categories/start/get run
вернули 404 feature_disabled без чтений/записей; публичный seller ping совпал
с версией образа, anonymous categories — 401. Все 12 контейнеров running,
заданные healthchecks healthy. Лог — production-smoke-3039691.log.

Одна Doctrine и одна Alembic итоговая миграция; Python production/его новая схема
не активировались, реальные MPStats API/ключи не использовались. Live egress,
операционные секреты и активация сервиса остаются этапом 8. Перед production down
нужны backup журналов и остановленные relay/worker; сырые S3 объекты сохраняются.
G01–G08 закрыты. Документальное закрытие меняет только Markdown, новое независимое
ревью по порогу AGENTS.md не требуется; его CI/выкладка проверяются отдельно.
