# Выполнение дневного планирования: планы задач и готовность

План: [68 задач](sales-planning-daily-implementation.md).
Начало исполнения: 27.10.2026. Владелец поручил реализацию автономно,
с подробным планом и чек-листом перед каждой задачей и отдельным Codex CLI ревью.
Это поручение принимается как запуск предложенного плана с рекомендованными
решениями; новые внешние зависимости и необратимое изменение production-данных
им не разрешаются.

**Действующее уточнение владельца:** «claude ревю пропускаем делаем только codex».
Для всего текущего плана дневного планирования (A01–A50 и B01–B18)
требование Claude отменено прямым указанием владельца;
используются независимые Codex CLI сессии. Для рисковых изменений сохраняются
оба прохода Codex: соответствие правилам и поиск дефектов. Это исключение
не распространяется на другие задачи репозитория. Остальные
предусловия проверок, CI, merge и production остаются в силе.

## Порядок исполнения

1. Перед изменениями записать план, файлы, проверки и критерии задачи.
2. Для нового поведения выполнить RED → GREEN, для исследования — собрать
   доказательства чтением источника и существующими поведенческими тестами.
3. Запустить независимый Codex CLI через штатный пакет, записать артефакты,
   разобрать замечания и исправить принятые.
4. Отметить готовность только после проверок и требуемого ревью.
5. Для кода получить отдельный Codex CLI проход; для рисковых областей —
   оба прохода в разных сессиях. Обязательные замечания исправить до merge.

## A01. Описать исходы заказа

Статус: выполнено.

### Подробный план до выполнения

1. Сверить ADR-019/020 и актуальную view buyout_outcome.
2. Выписать T1, D, T2, P, R, active и unknown; отличить исходы от состояния ожидания.
3. Указать доказательство передачи, единицы количества и правило исключения T1.
4. Записать примеры частичных исходов и запрет повторного вычитания R.
5. Провести Codex CLI ревью в новой сессии и разобрать замечания.

Файлы: этот журнал, sales-planning-daily-sources.md; два ранее подготовленных
документа включаются в первый пакет как исходный план реализации.
Проверки: источники ADR/view, существующие integration-тесты классификации,
локальные ссылки и отсутствие пробелов в конце строк.

### Чек-лист готовности

- [x] Каждая категория имеет точное правило включения в заказы и продажи.
- [x] Доказательство handover и недостаток истории различаются.
- [x] Учитываются штуки и частичные исходы, а не количество postings.
- [x] Активный заказ не записан как отмена; unknown не превращён в T1.
- [x] Проверки источника и результатов записаны.
- [x] Отдельное Codex CLI ревью завершено; замечаний нет.

### Результат и проверки

Матрица исходов записана в sales-planning-daily-sources.md.
make test-int TEST_WORKERS=2: 589 тестов, 3413 assertions, успешно.
Лог: var/sales-planning-baseline-int.log. Codex CLI: замечаний 0; пакет 548caa2b53298286084e278e59d7fd1b61aaec618636a2162941f044f0600bc2.
Ответ: var/review/runs/20261027T110450.745576Z-548caa2b5329-codex-fdjf0ezd/review.json.
Claude не требуется: задача A01 меняет только Markdown без ADR.

## A02. Проверить частичные исходы

Статус: выполнено. Claude снят владельцем; оба Codex-прохода успешны.

### Подробный план до выполнения

1. Дополнить существующий integration-тест синтетической когортой из 5 штук:
   доставлено 3, из них возвращено 1, ещё 2 находятся в ожидании.
2. Получить аллокации через штатный BuyoutOutcomeQuery: D=2, R=1, pending=2,
   исходное заказанное количество 5. Не вычитать R из D второй раз.
3. Проверить повторную загрузку того же возврата: количество и продажи не меняются.
4. Запустить integration, lint/stan/deptrac/structure-check для затронутой области.
5. Запустить отдельные Codex CLI с ролями соответствия и дефектов, а также
   обязательный Claude; разобрать все замечания.

Файл: api/tests/Integration/Ingestion/BuyoutOutcomeQueryTest.php.
Контекст: существующие Builder, view buyout_outcome и репозитории.
Характеризационный тест проверяет существующее поведение; изменения production-кода нет,
поэтому ожидается сразу зелёный результат, а не искусственный RED.

### Чек-лист готовности

- [x] Синтетические 5 штук сохраняются во всех аллокациях.
- [x] Из трёх выкупленных и одной возвращённой остаётся D=2, R=1.
- [x] Повторная загрузка возврата не меняет результат.
- [x] Integration и применимые backend-проверки успешны.
- [x] Codex CLI ревью и второй рисковый проход завершены, замечания разобраны.
- [x] Обязательное по текущему поручению Codex-ревью завершено; Claude снят владельцем.

## A03. Проверить входные данные

Статус: выполнено; план записан до исследования.

### Подробный план

1. Проверить поля продажи и возврата: цена, валюта, quantity, даты и история.
2. Проверить отметки загрузки, отличие полного пустого дня от отсутствующих данных.
3. Сверить SQL исторического asOf и возможность восстановить прошлые значения.
4. Записать матрицу доступности источников, ограничения и зависимости этапа B.
5. Провести отдельное Codex CLI ревью документа и разобрать замечания.

### Чек-лист готовности

- [x] Для каждой метрики указан конкретный источник и ограничение.
- [x] Цена, начисление и выплата не смешаны.
- [x] Наблюдаемая дата не объявлена точным моментом события площадки.
- [x] Отсутствующие ревизии sales/returns не объявлены восстановимым asOf.
- [x] Качество загрузки заказов и полнота возвратов различаются.
- [x] Codex CLI ревью завершено, замечания разобраны.

A03: матрица входов записана в sales-planning-daily-sources.md.
Codex CLI — замечаний 0, пакет 45e5078e7546;
ответ var/review/runs/20261027T110851.366081Z-45e5078e7546-codex-vko7gbk7/review.json.
Claude не требуется: Markdown без ADR.

A02: integration 590 тестов / 3416 assertions успешно; lint, PHPStan,
Deptrac и structure-check успешны. Первая проверка выявила только зависимость
проверочного массива от порядка аллокаций с одинаковым ключом сортировки;
тест исправлен нормализацией ключей, production SQL не менялся.
Codex CLI — замечаний 0 (de92474de842, codex-l42_jm1o).
Обязательный Claude запущен штатно: exit 127, исполняемый файл отсутствует;
авторизации также нет. Это блокирует завершение ревью и merge, не заменяется Codex.

## A11. Базовый агрегат заказов без подтверждённых T1

Статус: выполнено. Claude снят владельцем; оба Codex-прохода успешны.

### Подробный план до выполнения

1. Написать integration-тест публичного Query: 100 исходных штук, 10 T1,
   90 активных → raw=100, T1=10, effective=90.
2. Убедиться, что тест падает из-за отсутствия нового Query.
3. Добавить bounded DBAL Query внутри Ingestion: company/account/SKU/date,
   группировка SKU × день, готовая классификация buyout_outcome, readonly Row.
4. Ограничить запрос 50 SKU и 184 днями, явно задать максимальное число строк.
5. Проверить неизменность исходных фактов и identity raw=effective+T1.
6. Выполнить integration и backend-гейты; получить отдельный Codex CLI
   и дополнительный рисковый проход; Claude остаётся обязательным.

Файлы: api/src/Ingestion/Infrastructure/Query/SalesCohort/SalesCohortMetricsQuery.php,
SalesCohortMetricsRow.php, api/tests/Integration/Ingestion/SalesCohortMetricsQueryTest.php.
Входы: существующая view и Builder; новых межмодульных связей/API/таблиц нет.
Выбор способа учёта T1 принят для исполнения из поручения запустить предложенный план.
Не принимаются попутно необратимые решения A04 о календаре или схеме.

### Чек-лист готовности

- [x] У нового поведения наблюдался RED, затем GREEN.
- [x] 100−10=90; исходное количество и факты сохранены.
- [x] company/account/SKU/date ограничивают SQL; список не бывает без лимита.
- [x] Готовые исходы не классифицируются повторно.
- [x] Integration, lint, stan, deptrac, structure-check успешны.
- [x] Отдельные Codex-проходы завершены; замечания разобраны.
- [x] Обязательное по текущему поручению Codex-ревью завершено; Claude снят владельцем.

### A11. Разбор первого Codex-прохода и план исправления

Пакет 4b9a8953e2e0, codex-0l3c68ny: 2 замечания, оба приняты.
1. Лимит 9200 дневных строк против максимума 200 в CLAUDE.md §5.
   Исправление: SQL агрегирует дневные точки в одну строку на SKU;
   максимум 50 строк SKU, у каждой максимум 184 точки. Это представление
   таблицы планирования, а не увеличение лимита списочного метода.
2. Нет проверки tenant isolation в новом чтении.
   Исправление: добавить чужую компанию с тем же accountId и свою компанию
   с другим accountId, при совпадающих SKU/дате; количества не входят в результат.

Второй проход поиска дефектов defects-7qtykhay: 0 новых замечаний.
После исправления — тесты исходного количества, изоляции, ограниченности
списка SKU и вложенных дней; повторить backend-гейты и оба Codex-прохода.
Публичные Facade/API ещё не созданы, поэтому изменение внутреннего Row
не меняет существующий контракт потребителя. Правила лимитов не ослабляются.

## Контрольная точка исполнения

Полностью закрыты A01 и A03. Реализация A02 и A11 проверена, но задачи
не закрыты: обязательное независимое Claude-ревью недоступно. A04 и остальные
зависимые задачи не объявлены выполненными. Новые API, схема и UI не выпускались.

Проверки окончательного кода:

- make test-int TEST_WORKERS=2: 596 тестов, 3431 assertions, OK;
  var/sales-planning-a11-isolation-int.log.
- make lint stan deptrac structure-check: OK;
  var/sales-planning-a11-isolation-checks.log.
- Исходный unit baseline: 477 тестов, 3495 assertions, OK;
  var/sales-planning-baseline-unit.log. Новых unit-сценариев в этой точке нет.
- A11 RED: Query class not found; var/sales-planning-a11-red.log.
- A02: Codex соответствия 0, Codex дефектов 0.
- A11: первый Codex 2 замечания — приняты оба; первый проход дефектов 0;
  повторный Codex соответствия 0 новых, повторный проход дефектов 0 новых.
  Отклонённых замечаний нет.
- Claude для обоих пакетов: незавершённые прогоны exit 127; исполняемый файл
  отсутствует, штатная авторизация в окружении не обнаружена. Другой моделью
  это обязательное ревью не заменялось. PR/merge/production не выполнялись.

### Реестр независимых ревью

- a01-review: complete, замечаний 0; модель gpt-6.1-sol; SHA256 548caa2b53298286084e278e59d7fd1b61aaec618636a2162941f044f0600bc2; ответ var/review/runs/20261027T110450.745576Z-548caa2b5329-codex-fdjf0ezd/review.json.
- a02-codex: complete, замечаний 0; модель gpt-6.1-sol; SHA256 de92474de842d9a48b97ccc566fb0f05e13ff426f532f17af95cbf269d22584d; ответ var/review/runs/20261027T110816.206977Z-de92474de842-codex-l42_jm1o/review.json.
- a02-defects: complete, замечаний 0; модель gpt-6.1-sol; SHA256 de92474de842d9a48b97ccc566fb0f05e13ff426f532f17af95cbf269d22584d; ответ var/review/runs/20261027T111030.946430Z-de92474de842-defects-p5euiuhw/review.json.
- a03-codex: complete, замечаний 0; модель gpt-6.1-sol; SHA256 45e5078e7546738ad1638663b132edd697a21ef29197fbdd7e8b3ef65dc69246; ответ var/review/runs/20261027T110851.366081Z-45e5078e7546-codex-vko7gbk7/review.json.
- a11-codex: complete, замечаний 2; модель gpt-6.1-sol; SHA256 4b9a8953e2e0ffd40bd11a7321002236b3402161af434b0b0421d05e375255c2; ответ var/review/runs/20261027T111250.040619Z-4b9a8953e2e0-codex-0l3c68ny/review.json.
- a11-defects: complete, замечаний 0; модель gpt-6.1-sol; SHA256 4b9a8953e2e0ffd40bd11a7321002236b3402161af434b0b0421d05e375255c2; ответ var/review/runs/20261027T111351.736249Z-4b9a8953e2e0-defects-7qtykhay/review.json.
- a11-recodex: complete, замечаний 0; модель gpt-6.1-sol; SHA256 5bc44a9b14d6eb4d7884a618e13f303fa61c5df35e77f2aa050d2b906ea4c12c; ответ var/review/runs/20261027T111755.558389Z-5bc44a9b14d6-codex-sjmyh7km/review.json.
- a11-redefects: complete, замечаний 0; модель gpt-6.1-sol; SHA256 5bc44a9b14d6eb4d7884a618e13f303fa61c5df35e77f2aa050d2b906ea4c12c; ответ var/review/runs/20261027T111944.050524Z-5bc44a9b14d6-defects-mtarl2oj/review.json.
- a02-claude: незавершён, exit 127; модель не сообщена; SHA256 de92474de842d9a48b97ccc566fb0f05e13ff426f532f17af95cbf269d22584d; ответ var/review/runs/20261027T110851.100703Z-de92474de842-claude-bg31608w/stderr.txt.
- a11-claude: незавершён, exit 127; модель не сообщена; SHA256 5bc44a9b14d6eb4d7884a618e13f303fa61c5df35e77f2aa050d2b906ea4c12c; ответ var/review/runs/20261027T111945.220296Z-5bc44a9b14d6-claude-gr6c4zh_/stderr.txt.

var/review — локальные артефакты, постоянный архив не гарантирован.
В этом журнале сохранены итоги, хэши, роли, модель и разбор замечаний.

## Дополнительное ревью по уточнению владельца

27.10.2026 владелец повторно указал выполнять ревью Codex CLI в отдельной сессии.
Выполнены два новых независимых запуска для всех 10 файлов коммита
ca7542d953bcbbb0b369164ada9193a5740ad0c9. Каждый запуск — новая сессия,
временный каталог вне репозитория и read-only sandbox; история разговора
автора не передавалась. Переданы снимок diff, правила, ADR, точный контекст
и разбор предыдущих замечаний; чужой securety_chek.md исключён.

SHA256 пакета: 65d80a92e034e5d3a5148ee3365074d0daa96e6cb70e36f5fd6db52ffb2f9b5c.

- Соответствие правилам: complete, замечаний 0; модель gpt-6.1-sol; сессия 01a17d64-3378-7eb2-bd15-4b0343c6c1cd; каталог /tmp/conwix-review-session-wvomtbqy; ответ var/review/runs/20261027T124441.068585Z-65d80a92e034-codex-5988xb2f/review.md.
- Поиск дефектов: complete, замечаний 0; модель gpt-6.1-sol; сессия 01a17d64-eb85-7761-be17-ed23c12db9db; каталог /tmp/conwix-review-session-mnwxe1lq; ответ var/review/runs/20261027T124528.188745Z-65d80a92e034-defects-m62010qs/review.md.

Новых содержательных замечаний и уникальных принятых замечаний сверх
предыдущих проходов — 0 в каждом запуске; отклонённых — 0.
Повторно подтверждены исправления двух прежних замечаний. Код после
ca7542d не менялся, новые тестовые прогоны для одного только ревью не выполнялись.
Запись результатов не объявляет завершённым весь план реализации.

## Возобновление после уточнения ревью

Владелец явно отменил Claude для текущей задачи: «claude ревю пропускаем
делаем только codex». Старые записи о недоступном Claude выше сохраняются
как история, а не как действующая блокировка. A02 и A11 закрыты по успешным
проверкам и двум независимым Codex CLI проходам, подтверждённым повторным
ревью всех 10 файлов (пакет 65d80a92e034). Текущий итог: A01/A02/A03/A11 выполнены.

## A04. Решения и контракт дневного планирования

Статус: выполнено; ADR-046 Accepted после независимых Codex-проходов.

### Подробный план до выполнения

1. Сверить ADR-040/041, исходный план и выявленные ограничения источников.
2. Зафиксировать ADR-046: семантика заказов, наблюдаемых продаж и денег;
   первая утверждённая цель, метрика старых планов, пакетный Facade/API и календарь.
3. Явно отделить текущий прогноз выкупа от будущего нетто-прогноза B.
4. Уточнить ограничения SQL/DTO без ослабления лимита 200 и tenant isolation.
5. Сохранить согласованные в запущенном владельцем плане решения;
   не внедрять новые зависимости и не запускать отложенный подсорт v2.7.
6. Провести независимые Codex CLI проходы по ADR и документам; разобрать замечания.

Файлы: docs/adr/0046-sales-planning-cohort-performance.md, индекс ADR,
план реализации и журнал. Код/схема на этом шаге не меняются.

### Чек-лист готовности

- [x] Метрики, время когорты и возвраты имеют однозначные определения.
- [x] Исходная цель сохраняется при новых версиях и загрузках фактов.
- [x] Старые штуки и API не переинтерпретируются автоматически.
- [x] Facade, API и календарь описаны с конкретными границами и лимитами.
- [x] Будущий прогноз и неполные данные не названы готовым фактом.
- [x] Оба Codex CLI прохода закончены; содержательные замечания разобраны.

### A04. Разбор первого прохода

Codex соответствия: 1 замечание — якобы исключение Claude разрешено только A04,
а не остальным подзадачам. Отклонено: текущая задача владельца — вся фича
дневного планирования, разбитая на 68 подзадач. Прямое указание «claude ревю
пропускаем делаем только codex» дано после запуска всего плана и снимает
блокировку этой фичи, а не только документа A04. В шапке журнала уточнены
границы исключения: все A/B данного плана, без изменения правил других задач.
Codex поиска дефектов: 0 новых замечаний. Архитектурных замечаний нет.

## A12. Остальные исходы заказов

Статус: выполнено. Подробный план и чек-лист — sales-planning-tasks/A12.md.
Добавлены 9 синтетических сценариев реальных аллокаций, Query менять не пришлось.
Проверки: integration 605 тестов / 3512 assertions; lint, stan, deptrac,
structure-check и diff-check успешны. Логи: /tmp/A12-test-int.log, /tmp/A12-checks.log.
Оба Codex CLI прохода complete, замечаний 0; уникальных новых принятых 0/0.
Ответы: var/review/runs/20261027T130405.250018Z-587bccad93bf-codex-6rvtruwb/review.json
и var/review/runs/20261027T130444.851663Z-587bccad93bf-defects-w239i0k5/review.json.

A04: повторные Codex соответствия и поиска дефектов complete, новых замечаний 0/0;
уникальных принятых замечаний 0/0. Единственное первоначальное замечание
об ошибочной области Codex-only отклонено с основанием выше; уточнение владельца
учтено в текущем плане. Пакет 62c8b10ee5601fca24cf3238fef5b76da6d19bbbb94d9fc5b7b0bd9d021621f9.
Ответы: var/review/runs/20261027T130831.022017Z-62c8b10ee560-codex-pcyf58l0/review.json;
var/review/runs/20261027T131204.653815Z-62c8b10ee560-defects-vckhmsn6/review.json.
После этих заключений ADR-046 переведён из Proposed в Accepted без изменения решений.

## A13. Количество и область чтения

Статус: выполнено. План до кода — sales-planning-tasks/A13.md.
Добавлены пакетные SKU/дни, порядок/агрегация, точные пределы 50 SKU/184 дня,
календарные даты с разными часовыми поясами. Уже проверенные частичные исходы
и tenant isolation не дублировались. Integration: 609 тестов, 5046 assertions;
lint/stan/deptrac/structure-check успешны. Оба Codex CLI прохода complete,
замечаний 0/0, уникальных новых принятых 0/0.
Ответы: var/review/runs/20261027T131205.977709Z-47762626728f-codex-2fcbsott/review.json;
var/review/runs/20261027T131344.949687Z-47762626728f-defects-u5w0zcby/review.json.

## A14. Наблюдаемые нетто-продажи

Статус: выполнено. Подробный план/чек-лист — sales-planning-tasks/A14.md.
Добавлена сумма D в SQL/readonly Row, без повторного вычитания R.
RED отсутствующего показателя подтверждён; GREEN615/5085 и backend-гейты OK.
Первые правки сигнатуры provider исправлены до фиксации RED поведения.
Оба Codex CLI прохода complete, новых замечаний 0/0, принятых 0/0.
Ответы: var/review/runs/20261027T132435.226940Z-26fcda50121a-codex-hk2pjwu_/review.json;
var/review/runs/20261027T132638.700173Z-26fcda50121a-defects-stvbe65u/review.json.

## Проверка pending миграции A05/A07 с пустой базы

Локальность Compose подтверждена: проект app-service-analytics, postgres/s3
имеют локальные named volumes, external volumes отсутствуют. Первый запуск
остановился до миграций: новый php-cli имел UID1000, а private .env.local
принадлежит UID1001. Исправлена ignored локальная .env через существующие
CONTAINER_UID/GID=1001; права секретного файла не ослаблялись, содержимое не выводилось.
Второй make db-rebuild-check TEST_WORKERS=2 — OK полностью: dev/test миграции,
schema validate, PHP unit/integration/functional, Go migrate и race tests
(coverage internal87.8%). Лог var/sales-planning-db-rebuild-owner.log.
К production/живым кабинетам не обращались. Дальнейшая code-проверка A05
добавляет только указатель baselinePlanId в аудит и assertion без новой схемы.

A14 SHA256 пакета: 26fcda50121ab708349c4372b7d7362e7ae0e0ac1f366e53e7655d6b7fdb3446.

## A15. Денежная оценка состоявшихся продаж

Статус: выполнено. План/чек-лист — sales-planning-tasks/A15.md.
SQL суммирует D × индивидуальная цена в numeric/bigint внутри Ingestion,
связывая source_row_id с company/account/SKU/date. Row возвращает nullable Money.
Частичный/повторный R не вычитается второй раз; отсутствие выбранной валюты
или смесь валют D даёт отсутствие оценки при сохранении количеств.
RED/GREEN: корректный RED отсутствующего показателя → integration623/5136;
backend-гейты успешны. Исправление валюты комиссии synthetic USD fixture
записано отдельно и не объявлялось отсутствующим production-поведением.
Оба независимых Codex CLI прохода complete, новых/принятых замечаний 0/0.
Ответы: var/review/runs/20261027T135321.692464Z-a76708fd8afb-codex-kq_oc2og/review.json;
var/review/runs/20261027T135510.931881Z-a76708fd8afb-defects-d4g9jyzg/review.json.

## A05/A07. Явная метрика и первое утверждение

A07 выполнено; A05 foundation готов, end-to-end legacy revision остаётся A08.
Nullable metric без backfill, команда выбора с optimistic version и аудитом,
trusted actor утверждения, атомарное занятие company/account/SKU/day,
запрет пустого плана и rollback конфликтов. API/OpenAPI/TS обновлены.
Pure Domain factory A08 создаёт только новую шапку draft, копирование строк
и replacement пока не реализованы. Чек-листы: sales-planning-tasks/A05.md,A07.md,A08.md.

Разбор Codex CLI: первый conformity-проход — 2 уникальных принятых замечания:
companyId первым параметром approve с guard до записи; Builder для claims Entity.
Первый defects — 0. Второй conformity — ещё 1 уникальное принятое замечание:
Reflection fixture нарушала Builder rule; заменена на настоящую newRevision
и Builder, raw UPDATE version_number также удалён. Второй defects — 0.
Третьи conformity/defects complete, новых замечаний 0/0, отклонённых 0.
Все 3 принятых устранены; Claude пропущен по действующему указанию владельца.

Итоговые проверки: unit486/3539, integration624/5140, functional356/2619;
lint,stan,deptrac,structure-check,API/types и seller typecheck успешны.
Схема ранее прошла полный db-rebuild-check, после него менялся только код.
Логи /tmp/a05-review2-green2.log,/tmp/a05-review2-final.log.
SHA256 финального пакета 5b3ab6ed60bc2ea98831e0220e26a947e5ee82122ae97049f6d6d3ea6786ec72.
Ответы var/review/runs/20261027T140332.183928Z-5b3ab6ed60bc-codex-o4z4bkpu/review.json;
var/review/runs/20261027T140434.861682Z-5b3ab6ed60bc-defects-lxa0qb7c/review.json.
Фактическая модель обоих прогонов gpt-6.1-sol (stderr), отдельные CLI sessions.

A16: подробный план и checklist созданы до тестов в sales-planning-tasks/A16.md;
учёт покрытия заказов не утверждает полноту возвратов.

## A16/A17. Покрытие и зрелость

Выполнены, before-code планы/чек-листы и точные результаты в
sales-planning-tasks/A16.md,A17.md. Query покрытия возвращает каждый день
(complete/partial/missing) отдельно от фактов. Maturity переиспользует
BuyoutMaturityQuery по своему кабинету и назначается каждой SKU-дневной точке;
known и actual D/(D+T2+P) различаются, наблюдаемые продажи preliminary сохранены.
Покрытие заказов и зрелость выкупа не означают полноту возвратов.

Общий финальный прогон lint/stan/deptrac/structure/unit/int/func GREEN:
487/3544,642/5285,357/2639 соответственно; /tmp/a08-final.log.
A16 Codex0/0, packagefcd0b0254ab4; A17 Codex0/0, package70f5347479ff.
Уникальных новых принятых в каждом дополнительном проходе0, отклонённых0.
Полные SHA256 и пути к review.json записаны в отдельных планах задач.

## A08. Новая версия и replacement

Выполнено, вместе с end-to-end acceptance A05. Новые UUIDv7 и точные значения
копируются DBAL keyset-порциями<=10000 в одной транзакции с ORM/audit;
исходный approved сохраняется во время редактирования draft. Replacement
сначала flush superseded source, затем approved target, освобождая все старые
claims; конфликты откатывают обе шапки и аудит. Реальный legacyNULL проходит
выбор метрики через revision без изменения исходной цели.

Нормативное расхождение literal statusSQL ADR040 разобрано явно в новом
ADR047 (принят после Codex): Domain status + ORM expectedversion, все
производственные status transitions версионированы. Старый ADR не правился.

Первый conformity:1 уникальное принятое замечание о cap10000; defects0.
Ресурсный regression trigger дал RED на едином INSERT10120 и GREEN после
порционного исправления. Последующие conformity/defects0/0, новых принятых0/0,
отклонённых0. Полный SHA/пути — sales-planning-tasks/A08.md.
Backend unit487/3544,int643/15415,func357/2639; всеstatic/API/seller gates GREEN.
Source/target optimistic races проверены; настоящая конкуренция остаётся A10.
A09 план/checklist записан до кода; следующий lifecycle шаг — cancel/discard.

## A18/A19. Текущий прогноз и будущий нетто-прогноз

Выполнены: существующие ставки SKU/кабинет, pre/posthandover и кривая
доставки, доля без оценки ADR031/033. Numeric компоненты сохраняются
строками до суммирования/округления; forecast8 не становится нетто-прогнозом,
тот покаnull с net_forecast_not_available до модели B. Старые Buyout не менялись.
RED3 -> GREEN646/15436, затем RED1 причины -> общий GREEN652/15533;
unit491/3556,func358/2673,lint/stan/deptrac/structure и API/seller gates OK.
Оба Codex CLI complete0/0, уникальных новых принятых0/0, отклонённых0.
Полный SHA256/артефакты — sales-planning-tasks/A18.md,A19.md.
A09 код GREEN, ожидает отдельного Codex ревью; A06 план записан до кода.

## A09. Отмена и отказ с аудитом

Выполнено: cancel через approved source первым ORM flush; sibling draft
discarded, claims освобождены в общей транзакции, аудит обоих переходов.
Discard сохраняет строки и историю; исходная approved goal не меняется.
Guards и rollback source/sibling optimistic races проверены. A08 бывшие
status SQL fixtures заменены реальными командами. Схема не менялась.
Unit491/3556,int652/15533,func358/2673 и backend/API/seller gates GREEN.
Оба независимых Codex CLI complete0/0, уникальных новых принятых0/0,
отклонённых0; SHA/артефакты — sales-planning-tasks/A09.md.

## A06. Исходная числовая база

Выполнено: company/account/lineage-scoped LIMIT1 выбирает минимальный
успешно утверждённый version_number, включая superseded/cancelled историю.
Draft/discarded исключены, nullable approval metadata и metric старого
плана не теряют числовую цель. Смена/отмена версии оставляет исходные числа.
Company/lineage/version index добавлен в ORM и единственную pending миграцию
с down, поддерживает baseline и исторический MAX. Полный local db-rebuild-check
GREEN: schema dev/test,unit491/3556,int655/15565,func358/2673,Go87.8%;
var/sales-planning-a06-db-rebuild.log. Static/API check GREEN.
Оба Codex CLI complete0/0, новых принятых0/0 и отклонённых0; SHA/артефакты
в sales-planning-tasks/A06.md. A10 теперь проверяет настоящие отдельные процессы.

## A10. Настоящая конкурентность

Выполнено tests-only: отдельные kernel/PHP/DB процессы, пять interleavings
(edit/approve оба порядка, пересекающиеся approvals, copy/cancel оба порядка),
разные backendPID и реальный Lock с pg_blocking_pids до commit победителя.
Проигравший не сохраняет неверные числа/header/claims/audit, закрытая линия
не открывается. Worker защищён APP_ENV+exacttestDB+syntheticcompany, negative
guard проверен; deadlines и finally cleanup с count0 собственных данных.
Parent inspection добавил поддержку sequentialapp_test: focused4/229 GREEN.
Unit491/3556,int659/15795,func358/2673 и backend gatesGREEN.
Оба Codex CLI complete0/0, уникальных новых принятых0/0 и отклонённых0;
SHA/артефакты — sales-planning-tasks/A10.md. Production/schema/API не менялись.

Фундамент A01–A19 завершён (19/68); таблица/PerformanceAPI/календарь и этапB
ещё не выпущены. A20–A23 начал отдельный implementer по before-code brief,
parent готовит независимый календарный пакет A41–A43 в рамках ADR046.

## A20–A23. Публичный пакетный Facade

Выполнено: bounded Request/Grouping/Batch→SKU→Point/nullable Components,
period и cumulative в SQL до clip/rounding; два SQL независимо от1/50SKU.
Partial/missing не превращаются в полный итог; confirmedzero, Moneycurrency,
unknown против отсутствия обучения, grouped maturity и hiddenmissing проверены.
ClockInterface, netnullreason, returnsCoverage not_assessed явные; HTTP RR
снимок открывает будущий caller, latency измеритA48. SourceQuery и facade
не меняют старые отчёты; schema/API/deps неизменны.

Final int667/16876, lint/stan/deptrac/structure GREEN; unit499/3566 и
func358/2673 ранее GREEN (последние изменения только cohortfields, полная
integration повторена). Оба Codex CLI0/0, новых принятых0/0, отклонённых0.
SHA/артефакты — A20..23.md. Чек-листы A01–A23 завершены (23/68).
Calendar A41–43 планы и ProposedADR048 опубликованы до кода; ещё не реализованы.


## Приёмка A24–A29: 2026-10-27

Завершено: исходная числовая база, SQL-накопление до отсечения окна,
day/week/month/period, выполнение и отклонение по выбранной метрике,
отдельная скорость продаж D/день, bounded API и согласованный read-only snapshot.
Планы и текущие прогнозы выкупа не подменяют будущий нетто-прогноз.

Проверки: полный pre-review unit516/3616, integration676/16967,
functional359/2829; API export/types/check и seller typecheck GREEN.
После дополнительной проверки cursor: make lint stan deptrac structure-check
test-unit test-func TEST_WORKERS=2 GREEN, unit517/3617, functional359/2832.
Логи: var/sales-planning-a29-cursor-green2.log и A24-A29-report.md в локальном SDD.

Первый пакет SHA256 431b0d6bd83cbc1ae1b9abba6a7993ff4037a3b889f9ac62e9a9907b1a1d4b51:
два Codex CLI complete, findings0/0. Parent inspection принял три собственных
замечания: обязательный period, отдельная скорость продаж, NUL в cursor;
все исправлены и подтверждены тестами. Это не замечания дополнительных CLI.
Повторный итоговый SHA256 d480806e776a34cee3db19b415374bb315aa31ef671e00f5c5ae0ff60472a22d.
Модель gpt-6.1-sol, отдельные сессии; оба complete, findings/rule_gaps/nitpicks0:
- var/review/runs/20261027T161011.798683Z-d480806e776a-codex-6lbkrdlw/review.json
- var/review/runs/20261027T161011.790768Z-d480806e776a-defects-7oikgyjl/review.json
Уникальных новых принятых замечаний повторных проходов0/0, отклонённых0.
Claude пропущен по явному исключению владельца, см. sales-planning-review-rule.md.
Артефакты var/review локальные; данный текст сохраняет выводы и хеш пакета.

Чек-листы A01–A29 завершены: 29/68. A41–A43 в разработке, UI и выпуск впереди.


## Приёмка календаря A41–A43: 2026-10-27

Все критерии выполнены:5 enum,3 сохраняемых вида и2 вычисляемые границы;
ORM command500, scoped/versioned PUT с точным аудитом, readonly approved,
copy новыхUUID, removeSKU calendar+audit rollback; DBAL QueryBuilder и
read-action с одним snapshot statement, GET default50/max200 keyset,
строгая валидация и422 cursor вне набора. API сгенерирован штатно.
Схема добавлена в единственную pending миграцию, down удаляет свою таблицу.
Полный local fresh db-rebuild-check GREEN (a41-db-rebuild2.log), DDL с тех пор
не менялась. Финальные проверки:unit517/3617,int686/17143,func362/3042;
lint/stan/deptrac/structure/API types GREEN. Seller typecheck checkpoint exit0
на новых типах подтверждён журналом команды implementer; полный UI прогон отдельно.

Независимые Codex CLI, модель gpt-6.1-sol, отдельные сессии, Claude waived:
первый SHA c148f57058250e8beda6bbc8be6df07645cd689d360d66aaef148b5497dce26e —
conformity2 принято (QueryBuilder/default50), defects1 отклонено (Base58:
установленный Symfony уже проверяет RFC, HTTP422 воспроизведён без правкиsource).
Второй SHA af1420c91721a79d1eccc177679f406c7771767eb63e4d50754dc3130b212b7d —
conformity1 новое принято (out-of-range cursor422), defects0 новых принятых.
Оба прежних замечания исправлены; отклонение Base58 подтверждено обоими.
Итого3 уникальных принятых,1 отклонённое; все принятые исправлены с RED/GREEN.
Заключительный SHA e81451a4054283343dc2e656a49ae36ef5268226085a06f0342c15ad03f86a7f:
- var/review/runs/20261027T164228.790524Z-e81451a40542-codex-a9boplr0/review.json
- var/review/runs/20261027T164228.789146Z-e81451a40542-defects-zgwbslte/review.json
Оба complete0/0, новых уникальных принятых0/0, rule_gaps/nitpicks0.
ADR048 принят после заключительных проходов. Локальные var/review не являются
постоянным архивом: данный текст сохраняет выводы и SHA. Production не менялась.

Итого завершено32/68: A01–A29 и A41–A43. A30–A40 seller в разработке;
A44–A48 подробная подготовка опубликована до кода, не выполнены.


## Итог source-пакета A30–A40: 2026-10-27

UI/lifecycle/typed serverstate реализованы: оригинальная цель против дневного
и накопленного факта; состав заказов, отдельные sales/revenue/salesSpeed,
качество/maturity/свежесть, текущий выкуп отдельно от недоступного net forecast.
Metric firstdraft явно меняется до утверждения, inherited revision readonly.
URL хранит applied from/to/group/mode/page/view, cursor связан с trusted header;
recovery повторяет comparison, причины409 различаются. Dirty формы сохранены.

Final make front-typecheck front-lint front-knip front-test front-build APP=seller
exit0,101files/475tests (в том числе4 отдельных rootCalendar tests вне пакета).
Log var/sales-planning-a30-a40-fix1-front-gates.log; build с configured публичным
SmartCaptcha key, guard не менялся, значения не печатались. SSR/typedMSW
используют настоящие компоненты/клиент/QueryClient; Playwright пока не запускался.
Browser acceptance URL/retry/lifecycle/409/company/keyboard закреплено в A46/A47.
A35 остаётся открытой до keyboard/visual proof; source готовность позволяет
продолжать A36–45 без ожидания полного end-to-end gate, не снимая его перед merge.

Первый CLI SHA f48f587ef51ca7402f6b5e7d1a72863b828ae4f4feb7b0681d852c2e832e7134:
conformity1 принято (URL), defects2 принято (retry/различение409), отклонённых0.
Parent firstdraftchooser1 исправлен до CLI, не является дополнительным CLI finding.
Все3 CLI замечания исправлены RED/GREEN и повторены все5seller gates.
Итог SHA 1c323a23ae37c61a6db264a0fe5d5e5cd116503bf0676ae12723f43a05ef46b8:
- var/review/runs/20261027T170741.969304Z-1c323a23ae37-codex-a4c0h9pu/review.json
- var/review/runs/20261027T170741.970115Z-1c323a23ae37-defects-tdho5pvp/review.json
Оба complete0/0, model gpt-6.1-sol, separateCLI sessions. Новых уникальных
принятых дополнительных проходов0/0, rule_gaps/nitpicks0; Claude waived
по явному решению владельца. var/review локален, выводы/SHA сохранены здесь.
Production не менялась; полный CI/замеры/браузер/выпуск остаются отдельными gates.

Итого42/68 complete; A35 source-ready, ждёт реального keyboard/visual proof A46.
A44–45 data foundation ready, UI integration следующая.


## Приёмка A44–A45 и browser proof A35: 2026-10-27

Полный bounded snapshot≤500 через200страницы, derivedreadonlyboundaries,
manual3kind, редактирование/fullsave/cancel, capturedversion+dirty409,
user/company/plan scope и companyonlyinvalidation реализованы. Native feature-
local DOM window>200 без новой зависимости/клиентскойпагинации; row64px,
viewport384px, aria logicalrowcount/index и keyboardHome/End/Arrow/Page.
Стабильная подписка существующего period/null TanStackQuery сохраняет metadata/
Calendar instance при сменеSKUgroup/pending/error; serverstateне копируется
в Reactstate. Новыйcaptureждётsuccess&&!fetch, existingdirtydraftне меняется.

Frontendfinal make front-typecheck front-lint front-knip front-test front-build
APP=seller exit0,105files488tests, logvar/sales-planning-a44-a45-readiness-gates.log.
ПервыйCLI63cd5fbaacedfb7402fad62f0983129a40966b914d2d9c0660cf95b18b6a9dda:
conformity1 принято(virtualization), defects1 принято(unmount), отклонённых0.
Повторный67b187cf661fead417c27f514754936d5a383470befb8646457185b7b9950286
оба0/0, дополнительныхуникальныхпринятых0/0. Parentactualbrowser отдельно
нашёл1 readinessrace, подтверждёнheldGET RED и исправлен; это неCLI finding.
ФинальныйSHA c7fcd8b056a39bd71325a1072af9a1071c2b36d33c7cd2923872c1430a367922:
- var/review/runs/20261027T174452.837017Z-c7fcd8b056a3-codex-fimd_1_c/review.json
- var/review/runs/20261027T174452.840523Z-c7fcd8b056a3-defects-_vv3cme0/review.json
Оба complete0/0, gpt-6.1-sol, отдельныеCLIсессии; дополнительныхпринятых0/0,
rule_gaps/nitpicks0. Claudewaivedпо решениювладельца; локальныеvarартефакты
непостоянныйархив, выводы/SHAсохраненыэтимтекстом.

НастоящийChromium focusedсценарий черезguardedlocalapp fixturefeed:
var/sales-planning-a46-browser4.log, exit0,1passed11.1s. Доказаны goal9 после
revisioncurrent19, rawimport D3→D2/R1 тойжекогорты, Money300→200р, URLreload/
back/forward, realcalendar/day409 dirtyinputs, groupchangecalendardraftretention,
performance transportfailure→realretry200 приsameheader; foreignplan404/cache.
500events actualAPI→boundedDOM<200, End/Tabedit500/Tabdelete500→fullPUT499;
31дневная performancegrid keyboardArrowRight actualscroll>0 и missingfactdash.
A35keyboard/scrollproof теперьGREEN. Screenshot viewport просмотрен root:
var/sales-planning-performance-browser.png. Полныйseller/admin/extensionE2E,
reviewсамогоtestharness, SQLзамерыиобщийCI/выпускещёпредстоят. НеобъявлятьихGREEN.

Итого45/68 complete: A01–A45. A46/A47 focusedbrowserGREEN, но testharness review/fullE2E pending.

## Приёмка A46/A47: браузер, дневной возврат и штатный harness

Планы/чек-листы и полный разбор — sales-planning-tasks/A46.md и A47.md.
Реальные raw importers и HTTP/Chromium: исходная дневная цель не меняется
при revision; поздний возврат D3→D2/30000→20000 RUB minor уточняет день
заказа, день возврата остаётся D0/0, накопление D2/goal9. URL/history, 409
dirty forms, calendar copy/500-event virtualization и company isolation PASS.
Guarded write-ahead bootstrap выдержал настоящий SIGKILL/rollback/cleanup;
flock автоматически освобождается и охватывает общий seed. Конкурирующий
штатный make отказал до мутации sandbox.

Полный make ci-local TEST_WORKERS=2 exit0 (var/sales-planning-a49-ci-local.log):
backend517/689/362, seller488/admin38/extension71 tests; 8 browser25.4s +
extension storage PASS; static/type/lint/knip/build/audit0/Go/API PASS.
Финальный пакет a1d2fb4f8be5, два отдельных Codex CLI gpt-6.1-sol complete0/0.
Первый pair принял2 уникальных замечания; дополнительные pair приняли1,1,0.
Всего4 принято и исправлено,0 отклонено. Parent company predicate перенесён
из A48 дополнительно. Claude пропущен по зафиксированному исключению владельца.
Все реальные review.json paths/hash/session receipts в A46, локальные var
артефакты постоянным архивом не являются. Итого47/68: A01–A47 готовы.

## Приёмка A48: HTTP/SQL budget и минимальная оптимизация

Отчёт: sales-planning-performance-measurements.md; подробный план, guards и
receipts — sales-planning-tasks/A48.md. На неизменном dataset50SKU×184дня
HTTP2029–2046ms RED→1711–1759ms PASS после удаления daily JSON roundtrip.
Свежий seed с явным ANALYZE дал40/40 HTTP200/<2s, максимум1790.3ms.
Все10 EXPLAIN cases имеют5 SELECT независимо от limit1/50; source scope,
формулы, деньги/округление, обучение и hidden cumulative history сохранены.
Нет новых индексов/DDL/API/deps. Dense join/temp sort остаётся указанным
ограничением стенда; нагрузка production этим замером не обещается.

53 scoped semantic tests и все проверки ci-local PASS. Test-only benchmark
имеет exact local guards, write-ahead scope, одну bootstrap transaction,
state0600, реальные SIGKILL/mismatch/cleanup proofs. Первый Codex pair принял1
уникальное замечание company predicate helper, исправлено. Дополнительный
pair5286ad9e3592 complete0/0, gpt-6.1-sol в разных sessions;0 новых принятых,
0 отклонённых. Parent recovery finding устранён до первого CLI. Полные
review.json receipts в A48; Claude пропущен по исключению владельца.
Итого48/68: A01–A48 готовы. Общий локальный CI PASS; A49 ждёт публикацию,
обязательный удалённый CI и сверку итогового diff. A50/B ещё не выполнены.

## A49: локальные гейты и интеграция актуального master

Ветка объединила 7b77412/8887d31 автоматически без конфликтов, сохранив
виджет прибыли. После merge полный ci-local TEST_WORKERS=2 PASS:
unit517/3617,int718/17280,func362/3048; seller493/admin38/extension71;
static/type/lint/knip/build/audit0/Go/API и browser8+extension PASS.
Root затем устранил собственное отступление CLAUDE §10: отдельный planning
test перенесён в .flow.ts, вызываемый существующим company-switch сценарием.
Снова seller type/lint/knip и полный browser7/27.2s+extension PASS; предметные
assertions и raw-importer feed сохранены. Логи/подробный план — A49.md.
Дополнительное review объединённых common файлов ef78cce37e87: отдельные
Codex CLI gpt-6.1-sol complete0/0, новых уникальных принятых0/отклонённых0.
Все review.json receipts и parent finding отражены в A49. Подготовлен план
выпуска A50 до любых release действий. Общий счёт остаётся48/68: удалённый
CI и выпуск ещё не выполнены, production не затрагивался.

## Приёмка A49, 2026-10-28

PR #270 создан, HEAD851b78a2139a5f708cef757202ed62d08dd4f55e; обязательный
CI40562787280 success по всем шести проверочным jobs. Image/deploy для ветки
штатно skipped. Review coverage192/192 конечных non-MD blob, missing0/errors0.
Все независимые замечания разобраны, blocking review нет, PR mergeable.
Подробные URLs/receipts в A49.md. Итого49/68: A01–A49 готовы; A50 до merge/
deployment/migration/smoke не закрывается, B01–B18 не начинались.

## Приёмка A50: production, 2026-10-28

PR#270 merged после обязательного CI exactHEAD851b78a; masterad9e28a.
Run40629823925 собрал635-ad9e28a и штатно отказал в deploy на одной pending
migration. Preflight подтвердил exactimage+единственную Version20261027120000;
она отдельно применена (45.7ms/9SQL), up-to-date PASS. Failed deploy повторён,
attempt2 completed/success. Seller/admin HTTP и running api подтверждают
635-ad9e28a, сервисы running/healthy. Read-only probe после переключения
проверил schema/indexes, scoped empty header/calendar SQL, enum и routes PASS,
не читая бизнес-значений. Полные URLs/логи/ограничения в A50.md. Числовой
prod-аудит клиентского плана и prod load test не заявляются выполненными.
Итого50/68: этап A реализован, проверен, отревьюен и выпущен. B01 подготовлен
подробным планом и source audit; B01–B18 пока не завершены. Netforecast до B null.

## Приёмка B01, 2026-10-28

Принят ADR-049: отдельные gross G=D+R и return populations, H60/L7,
Wgross/Wreturn90, sample100units/30postings, risk30/10, SKU→свойaccount→null.
Known R не вычитаются повторно; fresh target не обязан дозреть до H+L,
его training обязан. Truth open ждёт будущий deliveryOrigin+H+L, pending
не превращается в scored0. Numeric rollout ждёт пригодного B16 holdout.
Источники показали необходимость immutable accepted sales/returns revisions
и load receipts: подготовка наблюдений из B15 перенесена в B02 и отражена
в его подробном плане. Capture-only запуск не считается выпуском B18.
План сохраняет68 контрольных задач; B02 внутри разбита на простые schema/
writer/receipts/guards/training шаги, её оценка уточнена по исследованию.

Первый Codex CLI pair принял2 уникальных замечания (gross population и
component-relative truth), оба исправлены,0 отклонённых. Дополнительный
7bab05e19c4e gpt-6.1-sol complete0/0, новых уникальных принятых0; root сверил
реальные JSON/hash/session IDs. Полные receipts в B01.md; Claude пропущен
по исключению владельца. Только MD/ADR, runtime/DDL ещё не менялись,
предсказательная точность не заявлена. Итого51/68: A01–A50 и B01 готовы.

## B02: source foundation готов к CI, 2026-10-28

Реализованы accepted revisions sales/returns и immutable load receipts, включая
пустые успешные загрузки; явный provenance и старый readonly queue wire совместимы.
Одна pending миграция Version20261028120000 с legacy0 и отдельными postcommit
VALIDATE для двух CHECK. Fresh db-rebuild-check, фактические local down/up,
schema validate и E2E7+extension security прошли; точные receipts в B02.md.

Два actual Codex CLI итогового пакета c23d63557848 complete0/0. Из первого
набора принято1 замечание Builders, исправлено; отклонено0, дополнительных
уникальных замечаний повторного набора0. Все48 конечных code blob покрыты
обеими clean ролями. Дальше обязательный CI и capture-only выпуск, после него
training selection/epoch consumer. **Итого остаётся51/68**: эта часть не закрывает
B02 и не включает числовой нетто-прогноз.

## B02: source foundation в production, 2026-10-28

[PR272](https://github.com/pavelsur07/app-service-analytics/pull/272) merged
после6зелёных CI checks. Master6ceaa3ee86941cfa00aa2875dc71ad47c0423477,
[run40639786803](https://github.com/pavelsur07/app-service-analytics/actions/runs/40639786803)
attempt2 success, image639-6ceaa3e. Единственная pending migration V20261028120000
отдельно применена75.4ms/20SQL; два postcommit VALIDATE и up-to-date PASS.
Readonly production smoke, seller/admin versions и все PHP writers/health
проверены. Cutover evidence **2026-10-28T05:54:45Z**, accepted_state_v1.
Подробные receipts/ограничения в B02.md. Consumer epoch config/обучение ещё
не реализованы; numeric gate закрыт, общий счётчик51/68 сохраняется.

## Приёмка B02: training consumer в production, 2026-10-28

Обе bounded account-wide populations с trusted accepted-state history,
current read-only RR, полными coverage proofs и typed diagnostics реализованы.
Четыре уникальных замечания независимых Codex CLI приняты/исправлены,
отклонённых0; итоговые роли0/0,35/35 source/config/test blobs покрыты.
Последние affected34/246, fullintegration772/18184, static gates PASS.
Strict noisy plain4068/4403ms <5s, без ослабления caps/planner/выборок.

[PR273](https://github.com/pavelsur07/app-service-analytics/pull/273), exact
fc49 CI40675962337 SUCCESS, expected-head squash446126e. Master
CI40676598749/run643 attempt1 и deploy SUCCESS; image643-446126e у всех
PHPservices. Все12сервисов running/настроенные healthchecks healthy.
Trusted epoch принят всеми7PHPservices: start2026-10-28T05:54:45Z,
accepted_state_v1, исходный cutover639-6ceaa3e сохранён. Readonly smoke
пустого и явного config, seller/admin version pings PASS. Собственная
большая synthetic fixture очищена,14scoped EXISTS false.

Полные receipts/идентификаторы/разбор в B02-consumer-release.md и
B02-training.md. B02 закрыта,52/68; numeric forecast/accuracy ждут B03–B18.

## Приёмка B03: exact return curve, 2026-10-28

Чистые Domain fractions F/q с H60/61ages, sample100/30 иrisk30/10,
scoped adapter без fallback реализованы. Unit555/4434 и static gates PASS;
actual2Codex0/0, exact8/8source/testblobs покрыты, findings0.
[PR274](https://github.com/pavelsur07/app-service-analytics/pull/274)
exacte679CI40680237413 SUCCESS, squashd11a4ca. MasterCI40681313833/645
attempt1/deploy SUCCESS, production645-d11a4ca,12serviceshealthy/7PHPepoch
B02 сохранён. In-memory formula и readonly empty-source adapter smoke,
seller/admin pings PASS. Подробности B03.md; numeric/accuracy gates остаются.
B03 закрыта,53/68. NextB04: apply remaining risk to currentD, whole-scope
fallback, target agedH+L readiness; B05 по globalplan — OPEN pre-handover.

## Приёмка B04: риск уже выкупленных штук, 2026-10-28

Exact arbitrary-precision currentD×q иretainedD−futureR, wholeSKU→account
fallback, scope/currentRR/readAt иbound H+Lreadiness реализованы. KnownR
не вычитается повторно, unknown не становится0. Math теперь explicit в
Deptrac длятекущихиспользований; новыхзависимостейнет. Unit572/4681+static
PASS; actual2Codex0/0, exact9/9code/config/testblobsпокрыты.
PR275 exact39e9CI40685293252 SUCCESS, squash8b36fdf. MasterCI40685926691
647attempt1/deploy SUCCESS, production647-8b36fdf. All12serviceshealthy,
7PHPB02epochunchanged, publicseller/internaladminpings иpure runtime math
smokePASS. Подробные receipts B04.md. B04 закрыта,54/68; actualtarget
producerB09/quality/numericrollout не объявляютсяготовыми. NextB05 pre-handovergross.


## Приёмка B05: исход до передачи в доставку, 2026-10-28

Точная ставка G/(T1+G+T2+P), whole SKU→свой account fallback, scope/RR,
knownzero/unknown и exact expected quantity реализованы. Исторические T1
остаются в training:100G+100T1 дают p100/200 и gross50 для100open.
Unit586/4961 и все static gates прошли. Два отдельных actual Codex CLI
complete0/0, пробелов0; принятых/отклонённых0, второго прохода уникальных
принятых0. Все11/11 code/test blobs покрыты обоими ответами.

[PR276](https://github.com/pavelsur07/app-service-analytics/pull/276),
точныйf775 CI40689227154 success; squash49a62a5. MasterCI40690372833/run649
attempt1/deploy success, production649-49a62a5. Root:12healthy/running,
7PHP с прежней непрерывной epoch,8PASS pure-memory math smoke,
seller/admin version pings. Подробные receipts и review IDs — B05.md.
B05 закрыта,55/68. B06 — следующий расчёт после передачи; B09 target producer
и реальное качество/числовой допуск ещё не готовы.


## Приёмка B06: исход после передачи в доставку, 2026-10-28

Условная ставка G/(G+T2+P) использует100условныхunits/30dedicatedpostings,
полная raw sample сохраняется; частичные T1/post в одномposting допустимы.
Тот же200source=100G+100T1 даёт50gross до и100после для100open.
Unit604/5545, static gates иCI40692403671 точного9be00b7 прошли.
Два отдельных actual Codex CLI complete0/0;8/8code/testblobs покрыты;
принятых/отклонённых0, второго прохода уникальных принятых0.

[PR277](https://github.com/pavelsur07/app-service-analytics/pull/277), squash6281cfb.
MasterCI40693051190/run651 attempt1/deploy success; production651-6281cfb
проверен13:10:15UTC.12healthy/running,7PHP сохранили прежнюю epoch/config,
8PASS purememory stage/count smoke, seller/adminversions совпадают.
Подробные review IDs/receipts/ограничения — B06.md. B06 закрыта,56/68;
B07 будущие возвраты открытых — следующая задача, numeric gate остаётся закрыт.


## Приёмка B07: будущие возвраты открытых заказов, 2026-10-28

Exact eligible×pGross×(1−F60), согласованная одна scopedпара, genuinezero/unknown,
source/cachedconsistency иточный epochcontext реализованы.100open приp1/2,F1/5
даютgross50/returns10/retained40. Localunit623/6459 затем listfixturefix иaffected
19/914+staticexit0; исходный combinedexit2 не выдаётсязаexit0. Финальный
CI40695981843 точногоca59e42 прошёл полныйunit/contractaudit/E2E.
ActualtwoCodexCLI complete0/0,5/5code/testblobs покрыты; принятых/отклонённых0,
второго прохода уникальных принятых0. Internal epochprecisionfinding исправлен
локально перед CLI, не включён в их findings.

[PR278](https://github.com/pavelsur07/app-service-analytics/pull/278), squash65c33df.
MasterCI40696673264/run653attempt1/deploysuccess; production653-65c33df
проверен13:35:16UTC:12healthy/running,7PHPoriginalepoch/config,8PASSinmemorymath,
seller/adminversions совпадают. Подробные review IDs иreceipts — B07.md.
B07 закрыта,57/68. B08 wholefallback следующая; numeric/qualitygate закрыт.
