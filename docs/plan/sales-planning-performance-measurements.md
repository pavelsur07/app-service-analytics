# A48 — измерения выполнения плана продаж

На измеренном синтетическом dev-стенде все 40 последовательных HTTP-запросов
после минимальной SQL оптимизации вернули 200 и уложились в 2 s. Это результат
конкретного стенда, а не гарантия production latency для любых объёмов.

## Методика и границы стенда

Только локальный Docker dev, `APP_ENV=dev`, host `postgres`, user/database `app` и
явный `CONWIX_BENCHMARK_PLANNING=1`. Helper создаёт собственные UUIDv7
компании/кабинеты и пользователя `benchmark-planning+<runId>@example.com`.
Удаление допускается после сверки имён и принадлежности всех этих объектов.
UUID всех bootstrap сущностей известны из Builder::build заранее: atomic
state записывается до первой persistence, bootstrap проходит в одной
транзакции. Если она не committed, cleanup принимает полностью отсутствующие
пять identities и удаляет только state, без DELETE дочерних таблиц. Частичное
присутствие, несовпадение markers, лишние accounts/memberships отвергаются.
Файл состояния с паролем и его `.tmp` имеют mode 0600 до записи содержимого
(scoped umask 0077), остаются в ignored `api/var/`; state удаляется cleanup.
Реквизиты и ответы чужих компаний в отчёт не попадают.

Нагрузка: 50 SKU × 31 день и отдельные 50 SKU × 184 дня, 10 750 строк
плана и столько же дневных фактов; 3 000 дополнительных обучающих фактов
с наблюдёнными переходами delivering → terminal на одном зрелом дне внутри
последних 30 дней (30 postings/SKU; account in-flight <3% на обучающем дне).
В основной выборке есть active/delivering/cancel/return mix. Ещё 20 000
синтетических фактов другой компании создают фон для проверки scope pushdown.
Внешние API площадок, новые индексы и зависимости не используются.

Реальный HTTP проходит nginx/php-fpm и авторизованную сессию отдельного actor.
Черновики утверждаются через существующий HTTP endpoint. Запросы performance
исполняются последовательно: day31; day last31 of season184; week/month/period184.
Каждый сценарий проверяется с limit1 и limit50, первый запрос отдельно и три
повтора. Таймер охватывает полный response body и JSON.parse. Проверяются
размер страницы, метрика, baseline, company/SKU scope, покрытие, целочисленные
денежные значения и скрытое накопление для позднего дневного окна.

«Первый запрос» не означает сброс PostgreSQL shared buffers, ОС page cache
или OPcache: заголовки и approve уже обращались к приложению. Эти замеры
не являются испытанием холодного диска, конкурентной нагрузки или production.
Во время HTTP не выполняются PHP benchmarks, EXPLAIN, E2E или DB suites.

После HTTP helper создаёт реальный action graph на отдельной instrumented
DBAL connection; snapshot, header, baseline, plan, Facade, p95 и cohort series
используют одну и ту же connection. Пять SELECT захватываются после DBAL
array expansion, с типизированными bindings; затем выполняется
`EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` без ручной подстановки параметров.
SQL длительности измеряют driver execute, а action wall включает получение
результата и вычисления. Они не заменяют полное HTTP время.

## Команды

Запускать из корня репозитория после освобождения общего runtime:

```sh
docker compose exec -T -e APP_ENV=dev -e CONWIX_BENCHMARK_PLANNING=1 php-cli php tests/Support/SalesPlanning/benchmark-performance.php seed
docker compose exec -T -e CONWIX_BENCHMARK_PLANNING=1 playwright node bin/benchmark-sales-planning.mjs
docker compose exec -T -e APP_ENV=dev -e CONWIX_BENCHMARK_PLANNING=1 php-cli php tests/Support/SalesPlanning/benchmark-performance.php explain
docker compose exec -T -e APP_ENV=dev -e CONWIX_BENCHMARK_PLANNING=1 php-cli php tests/Support/SalesPlanning/benchmark-performance.php cleanup
```

Артефакты: `api/var/benchmark-sales-planning/http-summary.json`,
`sql-summary.json`, `sql-<seasonDays>-<group>-<limit>.json`.
Полные EXPLAIN JSON и SQL не коммитятся. При превышении HTTP бюджета
артефакт сохраняется до ненулевого завершения Node.

## Фактические результаты

2026-10-27 18:06–18:14 UTC, HEAD `b0d8fc4ea31356b58bbd5970e5cbe1c98dc0a6ea`
с рабочим A48 diff двух Query. PostgreSQL 16.15, PHP 8.4.27, Node 24.18.1,
Playwright 1.62.1, Symfony dev debug; host 4 CPU / ~7.75 GiB RAM. У PostgreSQL и
php-fpm нет отдельных Docker memory/CPU quotas; CPU не закреплялся.
PostgreSQL shared_buffers 128 MB, work_mem 4 MB, buffers не сбрасывались.
Объёмы fixture из раздела выше сохранены до/после оптимизации; totals не
получались через COUNT(*) факт-таблиц.

Первая попытка до autoanalyze дала nginx 504 на p95 SELECT: 30027.8 ms.
Долгий SELECT продолжался после nginx timeout, отменён только его собственный
PID 3489 с проверкой query prefix. Autoanalyze выполнился уже после старта
того запроса; после актуализации статистики все 40 responses 200. Оригинал:
`api/var/benchmark-sales-planning/http-before-statistics.json`.
Seed теперь явно выполняет ANALYZE после массовой синтетической загрузки.
Заключительный свежий seed с явным ANALYZE и новым actor/UUID также выполнен:
40/40 responses 200 и budgetPASS, максимум 1790.3 ms; первый31/day1=768.5ms.
Это отдельный readiness прогон после исправления seed; исходное сравнение
ниже выполнено на одном неизменном dataset. Заключительный receipt —
`api/var/benchmark-sales-planning/http-summary.json`, UTC 2026-10-27T18:18:14.439Z.

На актуальной статистике до оптимизации season184/day50 занимал2029.3–2046.5ms:
бюджет RED получен автоматическим HTTP assertion. EXPLAIN показал ненужный
JSON roundtrip дневных компонентов; Series теперь соединяет тот же raw daily
SQL напрямую. Публичный MetricsQuery::build, Facade, формулы, валюта, лимиты,
округление, training и hidden history сохранены. Индексы/схема не менялись.

Итоговый HTTP на том же dataset (первый запрос +3 повтора, ms):

| Сезон/группа | limit | Первый | Warm min–max | Body bytes |
|---|---:|---:|---:|---:|
|31/day|1|764.5|749.0–798.4|71933|
|31/day|50|1232.4|1243.6–1267.5|3559544|
|184/day last31|1|777.4|764.6–769.2|72488|
|184/day last31|50|1711.3|1742.2–1758.7|3596089|
|184/week|1|767.6|754.5–768.6|66596|
|184/week|50|1557.0|1552.6–1571.4|3299195|
|184/month|1|765.5|751.3–754.4|17267|
|184/month|50|1153.2|1153.1–1155.4|832486|
|184/period|1|540.4|540.1–546.2|3018|
|184/period|50|837.8|835.2–840.8|122407|

Пять SELECT во всех 10 SQL сценариях и при limit 1/50: header, baseline, plan,
account p95, cohort series. Это 3 SalesPlanning + 2 Facade, без SQL-запроса по SKU
или дню в цикле. Одна RR/read-only connection для всей production action.
Для season184/day50:

|SQL стадия|До driver ms|После driver ms|После EXPLAIN ANALYZE ms|
|---|---:|---:|---:|
|header|6.5|6.4|1.790|
|baseline|2.6|2.5|0.047|
|plan|32.1|31.0|30.326|
|p95|214.4|211.4|232.158|
|series|1463.8|1173.9|1394.133|

SQL driver sum 1719.3→1425.3 ms, action wall 1952.6→1662.7 ms. Driver timings
не включают весь result fetching; EXPLAIN сам добавляет instrumentation.
HTTP таблица выше включает тело и JSON и является критерием бюджета.

EXPLAIN p95 агрегировал 10525 observed postings, tenant_outcome вернул 14825
количественных allocations от 13750 своих фактов. training_rates содержит 101
группу (100 SKU + account), handover curve не пуста. Scope company/account
применяется к sales/status/return источникам до тяжёлых оконных вычислений;
чужие 20000 фактов не попадают в tenant_outcome. SKU/date фильтр применяется к
материализованному account outcome перед valuation join/daily aggregation:
11620 allocations для 50 SKU / 184 дней, 3205 отсечено. Полная история своего кабинета
сохраняется намеренно для p95/обучения; фильтровать её видимыми SKU/датами нельзя.
В valuation используется существующий sales_fact_pkey по company/account/source,
11620 loops индексного SQL join — это часть одного запроса, не N+1 обращений.

Daily JSON aggregate→recordset устранён. Dense cumulative join остаётся
ограниченным 263500 строками; сортировка external merge 35456 KB, temp read 4432/
write 4443 blocks. Итоговый series shared hits 49065, shared reads 0; p95 hits 2582,
plan hits 288, header hits 202, baseline hits 2. Условия данного бюджета выполнены,
поэтому отдельную оптимизацию cumulative prefix windows не добавляли.
Оставшаяся сортировка и повторное чтение outcome для p95/series — известная
граница этого замера, не обещание линейного масштабирования на любой объём.

Receipts до/после: `api/var/benchmark-sales-planning/before-optimization/`,
`after-same-dataset/`, `sql-*.json` (все 10 сценариев, полные plans/rows/loops/buffers).
Все артефакты локальные ignored, суммарно около 9 MiB; постоянным архивом не являются.

Проверки: scoped semantics 53 tests / 2915 assertions; full `make lint stan deptrac
structure-check` PASS; `make test-int test-func TEST_WORKERS=2` — 689 tests /
17177 assertions + 362 tests / 3042 assertions PASS. Попытка default 4 остановилась
на отсутствующей app_test4; использованы существующие app_test1/2 без reset.
Guard test 1 test / 27 assertions отвергает test/prod/missing marker до соединения.
Дополнительно реальный helper с перепутанным foreignAccountId отказал до
измерений; state восстановлен, cleanup выполнен. После cleanup company/user
sandbox markers отсутствуют, файл state с паролем удалён.

Независимое ревью: финальный пакет 5286ad9e3592, два отдельных Codex CLI
gpt-6.1-sol complete, conformity0/defects0. Первое замечание о company predicate
helper принято и исправлено; дополнительный pair не добавил замечаний.
Полные receipts и разбор — [A48.md](sales-planning-tasks/A48.md).
Интеграция и CI всей ветки выполняются отдельно в A49. Синтетический dev не
проверяет production cold disk, сетевые расстояния, конкурентный трафик,
другие размеры кабинетов и аппаратные ограничения; таких гарантий нет.

## Исправление bootstrap recovery перед независимым ревью

Внутренняя проверка выявила окно commit→state исходного benchmark helper.
Оно устранено описанным write-ahead state; два production Query, состав
фактов и результаты замеров выше не менялись, повтор latency не требовался.

Проверка отсутствующего bootstrap сначала воспроизвела RED старого cleanup:
валидный state с отсутствующими UUID отвергался. После исправления тот же
state успешно очищен. Затем выполнен реальный interrupted seed без test hooks:
отдельный psql удерживал `BEGIN; LOCK TABLE company IN SHARE MODE;`, CLI
wrapper записал свой PID и через `exec php` запустил seed. После появления
state (mode 0600) seed успел INSERT пользователя внутри общей транзакции
и заблокировался на INSERT company; точный PHP PID получил SIGKILL. После
ROLLBACK blocker все пять UUID отсутствовали, cleanup завершился0 и удалил
state. Локальный сценарий: `api/var/benchmark-sales-planning/recovery-smoke.py`.

Штатный seed с тем же полным объёмом фактов также выполнен. Последовательная
подмена state проверила отказ cleanup для существующих company/account/user
с несовпадающими markers; исходный state восстановлен после каждого случая.
Штатный cleanup затем прошёл, synthetic company/user запросы вернули0rows,
state отсутствует, активных запросов app нет. Scoped PHP-CS-Fixer и PHPStan
helper/guard прошли; guard PHPUnit повторно1test/27assertions PASS. Это
дополнительная проверка recovery к ранее выполненным backend gates.

Первый независимый пакет A48 actual Codex CLI: defects0, conformity1.
Принято единственное замечание §1: account lookup helper был ограничен
только id, несмотря на известный companyId. Исправлен на
`WHERE company_id = ? AND id = ?`, companyId первым binding. Ответ:
`var/review/runs/20261027T182615.493630Z-44750ab295fe-codex-8qq9uq3k/review.json`.
Отклонённых замечаний нет; повтор двух проходов после исправления pending.

Повторные scoped CS/PHPStan и guard1test/27assertions PASS. Actual SIGKILL/
rollback recovery повторён успешно. Короткий seed прерван после committed
bootstrap/plan; mismatch accountId и missing userId отвергнуты cleanup,
исходный owned state затем успешно очищен. Также absent-bootstrap state
с существующим accountId чужой E2E компании очищен без child DELETE, чужая
строка сохранена (проверено company-scoped SELECT после cleanup).
Локальный сценарий: `api/var/benchmark-sales-planning/scope-review-smoke.py`.
Два production Query и HTTP методика не менялись, повтор latency не требовался.
