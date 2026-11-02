# product-research-ozon — этап 6: кэш и статусы проекта

Дата: 02.11.2026. Статус: завершён. Основание: ADR-050 и принятый этап 5.

## Цель и границы

Закрытый месяц одной компании/подключения/категории повторно используется без
MPStats; конкурирующие проекты делят одну успешную загрузку. Неполный месяц
создаёт новую попытку. Symfony достоверно показывает итог потребностей проекта.
UI — этап 7; production Python, egress и реальные ключи — этап 8, флаг закрыт.
Новых зависимостей, Facade, брокеров, транспортов и финансовых фактов нет.

## 6.1. Ключ и координация кэша

Цель: переиспользовать только одинаковое проверенное сырьё.

- Ключ: company, source, точный category_path, date_from/date_to, partial=false,
  FBS, RUB, версия сырья/контракта и фиксированные семантические параметры запроса.
  Секрета/поколения credentials в ключе нет. Технические бюджеты проверяются при
  reuse, а не меняют семантику ключа. Только ready job + ready snapshot пригодны.
- Один cache slot с UNIQUE(company, source, key) указывает на owner job. INSERT
  ON CONFLICT + row lock координируют выбор. Active owner не перехватывается;
  ожидающий job сохраняет next_attempt_at без I/O и не удерживает worker.
  Ошибка owner допускает новую попытку другого job. Deadline/generation/lease
  и прежние retry budgets продолжают действовать.
- Ready snapshot неизменяем; cache hit ссылается на него в своей компании.
  Изначальный незаполненный snapshot остаётся технической историей, без raw I/O.
  Owner completeness/generation restart прозрачен через актуальный job pointer.
  Первый slot атомарно принимает подходящий ready job этапа 5, чтобы обновление
  сервиса не заставляло повторять уже выполненные выгрузки.
- Partial обходит slots, всегда новый snapshot. Старый успешный partial остаётся
  в истории; новая неудачная потребность не считается закрытой прежним снимком.

## 6.2. Достоверная проекция

Цель: отдельно показывать исходную загрузку и использование результата.

- v1 schema/examples неизменны. Новый read-only GET internal/v2/.../runs/{id}
  отдаёт атомарный progress с origin api/cache, collected_at (snapshot completion)
  и used_at (принятие результата job). При незавершённом месяце значения null.
  POST admission и каталог сохраняют v1. Новый контракт v2 и реальные сериализаторы
  проверяются на обеих сторонах, ошибки в v2 также version2.
- Symfony сохраняет только проверенный согласованный progress; формат проекции
  версионируется, legacy v1 допускает одно обогащение без изменения revision.
  Старые revision не перезаписывают новое; ready не регрессирует.
- Company-scoped reconciliation сканирует доставленные runs keyset-порциями,
  включая завершённые, и восстанавливает пропущенную/legacy проекцию через v2.

## 6.3. Потребности и статус

Цель: единый результат для detail/list/mutation без фоновой гонки статусов.

- Project хранит frozen требования (до24 MonthSlice) при создании, добавлении
  периодов и старте. Это периоды человеческого намерения, ORM-запись вместе с ним.
  Текущий partial не расширяется автоматически ночью; новый запуск/изменение
  фиксирует новый диапазон. Preview по-прежнему отражает сегодняшний календарь.
- Status вычисляется SQL по локальным проекциям и frozen требованиям, одним
  company-scoped запросом, без отдельного mutable status cache: draft без runs;
  loading при active run; loaded при всех ready; partially_loaded при части;
  error при нуле ready после запуска. Latest attempt каждого требуемого месяца
  в текущем source определяет готовность; история ready не удаляется.
- Добавление периодов не меняет активный run. Его завершение не закрывает новые
  месяцы. Старый run progress не заменяет latest attempt. Копия — новый draft.
- Категория/FBS в MVP неизменны уже при создании; recordRunRequested фиксирует
  блокировку атомарно. Изменение категории только через существующее copy.

## 6.4. Проверки, ревью и доставка

Одна Doctrine и одна Alembic миграция, рабочий down с backup технических журналов.
Гейты PHP/Python, OpenAPI/TS, настоящие PostgreSQL/S3, полная проверка с пустыми
изолированными БД ДО ревью; новые Claude либо Codex CLI5A/5B при недоступности
Claude, разбор всех замечаний, зелёный CI, merge, отдельная Symfony migration
после штатного deploy gate, production smoke и постоянные отчёты.

## Приёмка и TDD

| ID | Проверка |
|---|---|
| U01 | Ключ различает company/source/category/dates/FBS/currency/version/semantics; partial запрещён |
| I01 | Второй проект closed month ready без нового API, тот же snapshot, разные даты collected/used |
| I02 | Два настоящих конкурентных claim не загружают один ключ параллельно |
| I03 | Failed/incomplete owner не cache hit; waiting recovery, completeness restart, deadline и rotation |
| I04 | Другая компания/source/key не используют слот; более строгий budget не обходится |
| I05 | Partial всегда новый, неудачный refresh сохраняет ready history |
| C01 | v1 bytes неизменны, настоящий v2 progress и ошибки соответствуют новому контракту |
| P01 | Origin/collected/used сохраняются, повтор/старая revision/чужой source не меняют данные |
| P02 | Reconcile keyset+limit, tenant isolation, восстановление legacy/готового run |
| F01 | Draft/loading/loaded/partially_loaded/error одинаковы в list/detail/mutation |
| F02 | Новый период во время active run даёт partial после завершения старых месяцев |
| F03 | Поздний старый run не откатывает новый; failed partial не закрывает новую потребность |
| F04 | Copy draft без истории, но closed cache повторно используется |
| G01 | Новые red/green тесты, все прежние регрессии, PHP/Python/schema/API гейты |
| G02 | Up/down/up и полный fresh rebuild успешны до независимого ревью |
| G03 | Обязательное ревью complete, принятые замечания исправлены и перепроверены |
| G04 | Полный CI, merge, разрешённая migration/deploy/smoke и постоянные отчёты |

- [x] Кэш и concurrency приняты (U01, I01–I05).
- [x] Контракт и достоверная проекция приняты (C01, P01–P02).
- [x] Статусы и границы запусков приняты (F01–F04).
- [x] Проверки/миграции/ревью/доставка завершены (G01–G04).

Legacy API-ready jobs этапа 5 не имеют известного used_at: v2 возвращает null,
PHP сохраняет null. Новые API/cache jobs записывают точное время принятия;
origin=cache всегда требует used_at. Неизвестное прошлое время не восстанавливается
из collected_at.

[Отчёт приёмки](product-research-ozon-stage6-report.md), [независимое ревью](product-research-ozon-stage6-review.md).
