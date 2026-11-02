# product-research-ozon — отчёт этапа 7

Дата: 02.11.2026. Статус: завершён.
Код — [PR #315](https://github.com/pavelsur07/app-service-analytics/pull/315),
merge `4b396c982e564d43d5d4f916238c52cfe230d12c`. Штатная выкладка и smoke подтверждены.
[План и приёмка](product-research-ozon-stage7.md),
[независимые заключения](product-research-ozon-stage7-review.md).

## Результат

В seller реализованы список исследований с keyset-пагинацией, сезонный проект,
выбор категории по полному пути от трёх Unicode-букв и серверный предпросмотр
календарных месяцев. Есть 3/6/12 закрытых месяцев, прошлый год, текущий неполный
месяц по согласию, название и комментарий. FBO+FBS/RUB закреплены MVP.

Карточка восстанавливает все месяцы проекта с числом строк, границами среза,
источником, временем получения/использования и безопасными ошибками. Добавление
периодов допускается во время загрузки, догрузка сохраняет готовую историю,
копия создаёт draft. Повтор старта после неизвестного ответа сохраняет UUIDv7
intent и исходные version/calendarDate. Неизвестное создание не выдаёт успех:
кнопка повтора блокируется, проверка списка показывает сохранённый проект.

Availability читает существующий server flag, без отдельного frontend-флага и
без Python; навигация скрывается при false, direct route объясняет недоступность.
Существующая проверка подключения компании ADR-021 сохранена.
Новые company-scoped progress/estimate читают ограниченный набор требований
одним SQL/MVCC, проверяют source и точные даты/partial. Оценка кэша — ориентир
по локальным проекциям, не обещание API hit. OpenAPI/TS сгенерированы из PHP.

Polling только для loading, каждые 5 с, без фона, максимум10 мин. После ошибки
focus/reconnect не возобновляют запросы; ручной повтор обновляет прогресс и
календарь проекта. Бывший partial после закрытия месяца можно дозагрузить:
UI сравнивает свежий preview с точными границами готового среза.

## Проверки

| Проверка | Результат |
|---|---|
| Полный backend CI | 812 unit / 901 integration / 422 functional PASS; 9162/23877/4229 assertions |
| Межъязыковой fixture-only HTTP smoke в CI | PHP intent → Python HTTP → PostgreSQL/S3 → PHP:3 ready months PASS |
| PHP scoped unit/integration/functional | 96 tests / 1226 assertions PASS |
| PHP lint, PHPStan, Deptrac, structure-check | PASS, правила/baseline сохранены |
| api-doc-export, api-types, api-types-check | PASS |
| Seller typecheck, ESLint/Prettier, Knip, Vitest, build | PASS, 113 files / 606 tests |
| TypeScript browser spec/fixture | PASS, strict отдельно |
| make test-e2e | PASS, 12 seller/admin scenarios + extension real Chrome storage |
| Browser research coverage | 5 сценариев: полный путь; partial/потеря start; потеря create; закрытие partial; ручной polling resume |
| Узкий экран | Screenshot 800px проверен, общие UI-примитивы не менялись |

TDD: scoped SQL и библиотечные правила сначала Red → Green; неизвестное создание
с сохранением до потери ответа — Red → Green. Оба принятых замечания первого
5B воспроизведены browser Red → Green. При настройке новых тестов исправлены
формат отображаемых дат и ожидание асинхронной готовности; ранний прогон стартовал
до пересборки SPA, повтор выполнен на новой сборке. Ничего не ослаблялось.
Раннее расхождение локального extension dist (dev localhost против production
origin) устранено штатной production-сборкой, без изменений исходников.

Логи: var/product-research-ozon-stage7/, финальные review1-fix-gates.log,
review1-browser-final-types.log, review1-full-e2e.log; PHP backend-pre-review-*.
БД-схема/миграции не менялись: db-rebuild-check к задаче не применяется;
обязательный CI прогоняет миграции с пустой базы. Локальный полный audit/admin
и полный backend повторно не запускались: результаты берутся из CI отдельно.
Унаследованное Vite warning >500k не ослаблялось; build и CI гейт действуют.

## Ограничения и следующий этап

Browser research API — типизированная синтетическая фикстура, login/company shell
реальные. Это доказательство UI-пути, а не production-интеграции MPStats.
PHP integration/functional проверяют реальные SQL и границы доступа; ранее
созданный межъязыковой fixture-only smoke остаётся в CI.
Боевой Python research не включён, флаг остаётся false; живых запросов MPStats
в этом этапе нет. Реальные данные, квоты, эксплуатация, restore и ограниченное
включение относятся к этапу 8. Новых зависимостей, Facade, очередей,
Python изменений и миграций в этапе 7 нет.

## CI и независимое ревью

[CI 41398125939](https://github.com/pavelsur07/app-service-analytics/actions/runs/41398125939)
зелёный для итогового f2dec07f0ef855e1958a7821cd725029762b55f3 до merge.
Backend/frontend/E2E/contract-audit PASS; services по путям skipped (исходники
сервисов не менялись), но межъязыковой HTTP smoke включён в backend и прошёл.

Claude CLI недоступен. Выполнены 4 новые независимые Codex CLI сессии в 2 раундах:
5A первого — 0 уникальных принятых, 5B первого — 2; второй 5A и 5B — 0 новых сверх
предыдущих. Два замечания приняты, воспроизведены browser Red, исправлены и
повторно проверены. Отклонённых и пробелов правил нет. Полные ответы, метаданные,
модель из stderr и хэши сохранены в product-research-ozon-stage7-review.md.

## Production

Master pipeline [41398679017](https://github.com/pavelsur07/app-service-analytics/actions/runs/41398679017)
завершён успешно: публикация образа, штатный deploy и проверка готовности.
Через conwix-prod проверен точный APP_SOURCE_COMMIT; все контейнеры running,
определённые healthchecks healthy. Схема не менялась, миграции не применялись.
Прямые безопасные вызовы проверили ten data controller flags=false,
availability=false, feature_disabled guards. Публичные запросы без сессии
к categories/availability/project progress вернули 401. Реальные marketplace
API и боевые пользовательские изменения не выполнялись.

```text
PASS: deployed source 4b396c982e564d43d5d4f916238c52cfe230d12c; ten data controller flags=false; availability=false; categories/start/get run/project progress guards=404 feature_disabled; no writes
PASS: company-scoped reconciliation command registered (--after/--limit); no execution
api: running / healthy
nginx: running / healthy
ozon-fetcher: running / healthy
postgres: running / healthy
redis: running / healthy
traefik: running / no healthcheck
worker-backfill: running / healthy
worker-calc: running / healthy
worker-freshness: running / no healthcheck
worker-ingestion: running / healthy
worker-scheduler: running / healthy
worker-service-coverage: running / healthy
PASS: public seller ping version=780-4b396c9
PASS: anonymous categories request=401
PASS: anonymous availability=401
PASS: anonymous projects/019b0000-0000-7000-8000-000000000002/progress=401
```

Закрытие меняет только Markdown, без ADR: дополнительное независимое ревью
по порогу проекта не требуется. Итоговая документальная выкладка и smoke
фиксируются в PR закрытия; результатов будущего этапа 8 здесь нет.
