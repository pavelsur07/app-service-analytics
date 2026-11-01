# product-research-ozon — отчёт этапа 4

Дата: 01.11.2026. Статус: реализация/основная локальная приёмка выполнены,
полный rebuild, независимое ревью и доставка проверяются.
Основание: [подробный план и матрица](product-research-ozon-stage4.md), ADR-050.

## Результат

MonthCollector реализует start/step/read для одной категории и календарного
месяца. Один step — не больше одного запроса страницы; нет sleep или сетевого
I/O внутри SQL-транзакции. Существующий общий provider gate защищает и items:
квота/одновременность/Retry-After/auth pause/монотонная credential generation.
202/429 сохраняют ожидание; политика повторов и диспетчер — этап 5.

Исходные JSON bytes не сериализуются повторно. Parser читает числовые значения
через Decimal, проверяет границы/total/уникальные положительные SKU и отвергает
дубли ключей JSON/нечисловые константы/oversize. Нулевые продажи и остатки,
неизвестные поля и все исходные значения остаются в S3. PostgreSQL хранит только
технический журнал: фиксированные даты/категорию/FBS/RUB/profile version,
бюджеты/курсоры/счётчики, checksum/размеры и временные ID проверки полноты.
SKU-проверка пакетная с UNIQUE в БД, удаляется после завершения.

S3 If-None-Match:*; успешная запись и конфликт повтора проверяются чтением байтов.
S3-страница предшествует DB-регистрации, manifest — атомарному ready. Fencing
повторно проверяет аренду и generation при регистрации и публикации. Сбой до
commit не публикует ready; потерянный ответ после успешного commit оставляет
готовый результат, который повторный step читает без нового MPStats-запроса.

Чтение принимает company/source/snapshot/page, ищет scoped запись, пересобирает
S3-ссылку и проверяет size/hash. Входных готовых ключей нет. Манифест сверяется
с журналом и фиксированными параметрами. Подтверждённый total=0 — готовый снимок
с предупреждением empty_category_or_renamed; ошибка источника не становится пустым успехом.

## Проверки и TDD

- Начальный Red: domain/month отсутствовал — ModuleNotFoundError. Затем 17 unit
  проверок календаря/полноты/bytes/Decimal/бюджетов Green. Логи domain-red.log,
  domain-green.log в var/product-research-ozon-stage4/.
- Клиент: точные query/body/sort, секрет только в заголовке; 202/429 и оба формата
  Retry-After от времени ответа; 401/403, сеть/5xx/redirect/oversize; total deadline
  включает trickle body. Ошибки только безопасными кодами.
- Полный Python-набор: 114 PASS (до добавления отдельного migration preservation
  теста); Ruff/mypy strict/import-linter PASS. Лог python-gates.log.
- Дополнительный M01: down до 0002 оставляет каталог и S3 bytes, up/check
  восстанавливают схему; журнал снимков удаляется согласно документированному
  down. Лог migration-check.log. Итоговая схема с дополнительными constraints
  FBS/RUB/profile version перепроверяется в fresh rebuild.
- После окончательной правки: `make product-research-ozon-lint
  product-research-ozon-vet product-research-ozon-architecture
  product-research-ozon-smoke` PASS; health/readiness/auth/unavailable, runtime
  UID10001 и отсутствие инструментов разработки проверены. Лог final-gates-smoke.log.
- Полный месячный путь: 102 страницы через HTTP MockTransport → реальные PostgreSQL/S3
  → чтение всех страниц. **50702 SKU; 3541442 продаж; 5172621135 RUB точно**.
  Все bytes/SHA-256 совпали, 62 поля в каждой строке, нулевые продажи сохранены;
  после последней страницы лишнего запроса нет. Лог integration-green.log.
- Повторы, настоящие конкурирующие SQL claims, lost lease, generation rotation
  после запроса и после manifest write, межстраничные дубли, ошибки S3/DB,
  потерянный commit ack, corruption/missing object, tenant/source scope,
  ошибка logging проверены. Никаких live MPStats-запросов.
- Дополнительный офлайн-прогон `python -m unittest discover -s tests
  -p test_stage0_fixtures.py`: **2 PASS**. Подтверждены полный январь/API CSV
  и ручной февраль/FBS=1; карта 41 колонки версии 0.3 не менялась.
  Лог fixture-regression.log; известные отличия вычисляемых полей не скрываются.

## Гейты и доставка — выполняются

Полный изолированный rebuild: проект research-stage4-rebuild, отдельные
PostgreSQL/S3/Go volumes, host ports сброшены. Основные dev-тома сохранены.
Первая попытка остановилась до тестов на недоступности метаданных composer:2
в Docker Hub (network unreachable); повтор после проверки реестра выполняется.
Логи rebuild.log и rebuild-retry.log. Второй прогон дошёл до существующего
PHP integration-набора: 889 тестов, 3 ошибки и 10 отказов Ingestion/SalesPlanning,
включая ресурсные бюджеты. Отдельный прогон трёх затронутых классов прошёл:
67 тестов / 1192 assertions (baseline-focused.log). PHP-код не изменён;
причина общего прогона не объявляется доказанной. Выполняется полный повтор
с TEST_WORKERS=4 (rebuild-final.log), без изменения лимитов/утверждений.
Незавершённый прогон успехом не считается.

Claude CLI отсутствует; независимое ревью выполняется новыми сессиями Codex CLI
5A/5B по процедуре владельца. Результаты/принятые/отклонённые замечания будут
сохранены отдельным постоянным отчётом. До complete review и зелёного CI
merge/закрытие не выполняются.

## Границы результата

Runs/snapshots HTTP v1 остаются not_available, worker и collection readiness
выключены. Symfony/API-схема/UI не меняются; новых зависимостей или транспортов нет.
Python production compose/миграция/включение — этап 8; в этом этапе не заявляется
живая загрузка MPStats на production. Исполняется код одного месяца, не диспетчер
проекта и не кэш закрытых месяцев (этапы 5/6).

Одна новая Alembic-миграция 0003_month_snapshots; старые не переписаны.
Down оставляет каталог/S3, но удаляет технический журнал снимков; до production
отката требуется backup/export журнала для сохранения raw-связей. Политика
orphan GC/retention и реальные бюджеты обязательны до активации этапа 8;
автоматическое удаление/повтор ошибок в этот этап не добавлены.
Чужие B17/B18, securety_chek, temp и удаление onboarding-спецификации исключены.
