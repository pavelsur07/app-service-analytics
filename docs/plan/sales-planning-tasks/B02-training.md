# B02 — обучающие данные и проверяемая полнота истории

## Результат и границы до реализации

Продолжение B02 после capture-only выпуска639-6ceaa3e. Источники описаны
ADR-049 и B02.md; проверенная граница новых writers — 2026-10-28T05:54:45Z.
Нужно получить пригодные **количественные входы**, причины исключений и
coverage для gross/return моделей. Вероятности B03, деньги B11, Facade B07,
API/UI B12–B14, manifests B15 и численный rollout сюда не входят.

Никаких новых пакетов, очередей, межмодульных связей, миграций или изменения
старой buyout view. Работа остаётся внутри Ingestion. Старые отчёты сохраняют
методику ADR-019/029/032. Подготовленные в ignored audit рекомендации прочитаны
root; ниже зафиксированы выбранные решения, а не автоматическое принятие всего audit.

## Контракт consumer, выбранный root

1. Global deployment config `NET_FORECAST_CAPTURE_EPOCHS_JSON`, default `[]`.
   Не более16 непрерывных segments: UUIDv7 id, supported capture version,
   start UTC inclusive, end UTC exclusive nullable, cutover release.
   Strict parsing, timezone/bounds/overlap/version checks; неизвестное или
   недостаточное окно даёт source_history_unavailable, не guessed epoch.
   Config provider возвращает readonly value; tenant/account первыми параметрами
   потребителя. Реальное значение включается отдельно после проверки consumer.
2. Модель получает **два full-account aggregate SELECT в одном фактическом
   readonly RR snapshot**: gross и returns. Account и до50 requested SKU дают
   максимум51 scope rows на population. SKU filter не обрезает account sample.
   Нельзя накапливать коэффициенты по нескольким страницам/транзакциям.
   Нет server SQL query loop, SELECT * или гидрации фактов как Entity.
3. Query::build возвращает QueryBuilder; readonly typed row/DTO mapping рядом
   с инфраструктурным Query по существующему паттерну. Application action
   выполняет два data SELECT, создаёт snapshot либо проверяет фактические
   repeatable-read/serializable + read-only свойства внешнего владельца.
   Неподходящую внешнюю транзакцию отклонить, не менять её границы.
   Время чтения берётся внутри установленного snapshot; будущие stamped
   inputs исключаются. Исторический replay по одному timestamp не поддержан.
   Manifest/commit-visible replay до B15 не заявляется.
4. Отдельный bounded diagnostics Query (не новый HTTP endpoint) отдаёт целые
   candidate groups keyset-страницей1..200 и причины исключений. Cursor связан
   с company/account/population/epoch/read context/моделью. Это диагностика,
   не источник частично накопленного account fallback. Ненулевой nextCursor
   не означает готовый полный sample; старые contexts не переиспользуются
   как доказательство того же snapshot в новом запросе.
5. Внутренний `NetTrainingOutcomeSql` использует только фиксированные доверенные
   CTE names sales/status/returns и canonical T1/D/R/T2/P allocation semantics.
   Готового source-parameterized helper в текущем коде нет. Старую view/function
   не переписывать и не пытаться подменить её base relations внешними CTE.
   Semantic equivalence на полном synthetic snapshot проверяется сравнением
   с canonical view, отдельно — quantity conservation и ambiguous outcomes.

## Физические пределы и согласованность

Стартовые измеряемые caps на один account/population:20_000 candidate order/SKU
groups,50_000 sales source keys,50_000 return keys,400_000 selected revisions,
200_000 posting observations,50_000 relevant receipts. На группу128 sales+
128 return keys,128 revisions/source и512 status observations. Это operational
budgets, не статистические sample minima и не доказанная скорость.

N+1 sentinel проверяется до дорогой классификации. Любой общий либо групповой
overflow делает **весь соответствующий aggregate unavailable**, с точным
input_budget_exceeded и фактическим ограничением. Не обучать по первымN и
не исключать только дорогие группы ради сохранения выборки. Другую population
можно диагностировать, но полный net без нужного компонента останется null.
Сначала ограничить company/account/window candidates и natural keys, затем
поднять целые chains через PK. LIMIT после полного WindowAgg не считается
доказательством ограниченной работы. Все SQL измерить EXPLAIN/BUFFERS.

Временные query settings только локальные, с восстановлением connection при
ошибке; statement timeout5s. Таймаут — query_budget_exceeded, не insufficient
sample, не частичные статистики. Не ослаблять baseline, Deptrac или тесты.

## Источники и строгие proof guards

- Source order — fact_revision, не captured_at/id. Нужны initial1 с явным
  LivePolling/upsert, firstLoaded после epoch, contiguous chain и поддержанная
  version. Legacy0→1, initial backfill/replay/unknown не дают onset.
- Текущие facts допустимы только как scoped locator/budget hint/stale detector.
  Input values берутся из ledger. History lookup включает все прежние links
  и order siblings; current link/SKU filter не скрывает затронутые старые группы.
- Не отфильтровывать неудобные revisions до gap/correction проверки. Captured
  timestamp не commit watermark, live RR и будущий manifest имеют разные роли.
- Sales ledger не содержит substatus/cancel reason. Companion posting history
  допускается только как noncontradictory, scope/raw-linked proof recognized
  transitions и chronology, согласованный с capture anchors. Raw reuse/ABA,
  metadata gap, terminal-only first state и missing predecessor дают unknown.
  Одного sales.status или близости UUID/time недостаточно. Если строгий subset
  имеет низкий coverage, зафиксировать это; полный lifecycle capture — отдельное
  обоснованное улучшение, его отсутствие SQL не маскирует.

## Две populations, без вероятностей

**Gross:**90полных MSK business days перед текущим днём. Account/day допускается
только при полном исходном количестве с известными terminal outcomes и postings
coverage, до SKU/page filters. Unknown/open у другого SKU не теряется. G=D+R,
pre components T1/G/T2/P, post G/T2/P; пример100G+100T1 сохраняет базу200.
Возраст от конца order day строго больше доступного observed account p95.
P95 deduplicated по posting, min30 observed terminals, только доказанные
known-nonterminal→first-terminal transitions. В новой модели measurement window
те же90полных order days после epoch, до day-maturity filtering. Это отдельно
версионируемое уточнение ADR-049; старый p95 provider не изменяется.

**Returns:** origins в `[T-157d,T-67d)`, явный first recognized delivered после
known predecessor, полный H60+L7 и отдельный returns proof. One posting либо
несколько с одинаковым origin; разные origins при неоднозначной аллокации дают
ambiguous_delivery_allocation. Quantity исходной продажи после origin неизменна.

Возраст delta — первое доказанное accepted ClientReturn и последующие positive
quantity increments. Known non-ClientReturn→ClientReturn начинает R в новом
наблюдении, если quantities/outcomes/история непротиворечивы; первое появление
другого типа не переносит onset назад. Автоматически отбрасывать любой type
change запрещено: исключаются corrections, нарушающие monotone curve, включая
ClientReturn→non-ClientReturn, decrease, link move и contradiction. Исключение
затрагивает также все прежние order/SKU links. R>G/negative age не clip.

Horizon полуоткрытый `[origin,origin+60d)`, prefix F(a) учитывает события строго
до origin+a; bins0..59, boundary a60. Event ровноH не входит. Это явная
конкретизация «до возраста» ADR-049. Одинаковая секунда разных source clocks
без доказанного порядка даёт ambiguous/unknown, не UUID tie-break.
DTO возвращают exact quantity/count/prefix components, exact initial distinct
postings и явно named atRiskPostingsLowerBound на61 boundary по
[B02-posting-risk](B02-posting-risk.md); без q/rates/float/money/округления.

## Coverage policy `daily_complete_receipts_v1`

Это наблюдённая operational полнота, не гарантия отсутствия будущих изменений
API. Scheduler3/30 postings и3/90 returns не расширяется.

- Postings: явные LivePolling completed receipts необходимых source days после
  нужных состояний; непротиворечивые initial/terminal bridges. Не требовать
  несуществующий daily60d postings rescan уже закрытого старого заказа.
- Returns calendar: каждый требуемый полный MSK visual day в origin..H+L имеет
  LivePolling completed witness, полностью покрывающий его и завершённый после
  конца дня не позднее чем через24h. Partial boundary days округляются
  консервативно; текущий незавершённый день полнотой не объявлять.
- Returns control: сохранённый deep completion покрывает visual дни origin..H,
  завершён не раньше origin+H+L и не позжеT. Старый origin не требует нового HTTP-запроса: искать
  сохранённое свидетельство своевременного deep poll.
- Relevant failure требует последующего unambiguous success строго позже,
  закрывающего весь нужный пересекающийся диапазон. S/F/S одной секунды —
  ambiguous до более позднего успеха. UUID не восстанавливает порядок.
  Late catch-up не исправляет missed daily observation retrospectively.
- Unknown/replay/backfill receipts не повышаются до live, current day marks
  только diagnostic. Один account-scoped receipt scan на population,
  bounded required-day series/joins, без SQL на каждую training группу.

## Простые задачи и readiness

1. **B02.6a — contracts/epoch:** minimal typed request/config/components/reasons,
   strict bounds/version/overlap/scope; empty epoch даёт unavailable. Не создавать
   wrappers/DTO без реального consumer в этой реализации.
2. **B02.6b — whole inputs:** bounded candidates, complete source chains,
   old links/order context, initial/revision/clock/correction guards.
3. **B02.6c — coverage:** receipts/cadence/deep/failure/ties; tests successful
   empty loads, failed/missing/late recovery и разные оси source windows.
4. **B02.6d — return aggregate:** origin, H/L, monotone bins и postings risk-set;
   tests type onset/corrections, allocation, quantity changes и boundaries.
5. **B02.6e — gross aggregate:** canonical compatibility, account/day maturity,
   own p95 and full pre/post denominators, dedup across SKU.
6. **B02.6f — snapshot/diagnostics:** две model data queries в одном actual RR,
   scoped keyset diagnostics отдельно; late commit/concurrent refresh tests,
   outer transaction protection, no historical replay claim.
7. **B02.6g — EXPLAIN:** representative20kgroups/100krevisions, foreign10×,
   older-account10× и adversarial overflow. Записать actual latency/rows/buffers,
   query count и caps. До измерения скорость/coverage не объявлять пройденными.
8. **B02.6h — gates/review/release:** affected behavioral tests и backend gates,
   actual2Codex CLI sessions/triage, full CI, trusted epoch config/cutover
   отдельно от numeric rollout. Общая B02 закрывается только после всех пунктов.

- [x] План/уточнения ADR reviewed до implementation; решения и schema boundary ясны.
- [x] Epoch/request/source/coverage proof guards реализованы и проверены.
- [x] Обе populations дают целые inputs, overflow/null причины честные.
- [x] Distinct account fallback полный, snapshots согласованы, diagnostics scoped.
- [x] Корректность/canonical equivalence/границы/изоляция подтверждены tests.
- [x] EXPLAIN/лимиты/query count подтверждены фактически.
- [ ] Static/affected/full required CI и два CLI reviews закрыты.
- [ ] Consumer cutover/docs отражены; B16/B17 numeric quality gate закрыт.

Команды PHP/Composer только через php-cli/Make, TEST_WORKERS=2; integration
и functional suites последовательно. Fixtures через Builders, специальные
committed/concurrency tests очищают также3новые ledger/receipt tables.
Ни закрытие B02, ни predictive accuracy этим документом не заявлены.

## Приёмка контракта до implementation

Actual Codex CLI conformity0/defects0, complete/exit0, package SHA
67a8d367f711cd1e51f8c202486775a0a7284f0a8a9abbcad5014aa182a16eec.
JSON: var/review/runs/20261028T060635.736998Z-67a8d367f711-codex-hzrs5c0m/review.json
и var/review/runs/20261028T060635.732211Z-67a8d367f711-defects-d8_ccg37/review.json.
Оба CLI сообщили gpt-6.1-sol, отдельные session IDs сохранены в stderr.
Принятых/отклонённых замечаний0; runtime/скорость/coverage реализацией
ещё не подтверждены. Claude пропущен по указанию владельца для этой фичи.
Два model data SELECT в actual RR и отсечение historical replay остаются
обязательными; новые lifecycle/source доказательства нельзя придумывать.

## B02.6a — подробный план выполнения

1. RED: unit guards пустой/закрытой/перекрывающейся epoch config, unknown version,
   malformed UTC/UUID и scope/SKU/limits. Это границы доверия и чтения, не tests
   внутренней реализации helpers.
2. Minimal Domain/ValueObject NetForecastCaptureEpoch и Infrastructure Config
   provider с default[]; JSON ключи epochId/captureVersion/startUtc/endUtc/
   cutoverRelease, только UTC Z, максимум16 records, неизвестные поля запрещены.
3. Request/context/limits создаются только для реальных aggregate/diagnostic
   consumers следующих шагов. Scope canonical UUID,1..50 уникальных SKU;
   cursor context fingerprint не позволяет смешать компанию/кабинет/срез.
4. Wiring использует existing Symfony env default, без нового обязательного
   production env и без включения numeric/consumer epoch config.

Readiness: [ ] RED guards; [ ] GREEN guards; [ ] minimal typed contracts и
default[] wiring; [ ] источник/схема/очередь не менялись. Runtime принадлежит
исполнителю B02, TEST_WORKERS=2; Git/ADR/global docs остаются root.

B02.6a: RED отсутствующих classes затем GREEN4tests/24assertions, логи
`/tmp/b02-training-contracts-{red,green}.log`. Epoch parser lazy/default[],
никакой production config не включён. Request используется следующими Query.

## B02.6b/6c — план shared inputs и первых behavioral fixtures

1. RED integration valid account100G+100T1: source revisions через настоящие
   Builders/writers, known status anchors, explicit live receipts; assertions
   проверяют pre/post bases и account fallback, не только shape SQL.
2. Shared bounded source CTE: population-specific candidates, целые sales keys/
   order siblings, return keys из current+previous links, полные bounded chains,
   scoped companion statuses/receipts. Current facts только locators/counter hints.
3. Guards до classification: caps/sentinels, initial/revision/clock/gap, source
   corrections, raw/status anchors и metadata ambiguity. Группа не теряется до
   diagnostics; overflow закрывает population целиком.
4. Shared coverage CTE: postings initial/terminal witnesses, returns daily24h
   и deep>=H+L, failure/tie recovery; без запроса на каждую группу.
5. Новый canonical helper семантически повторяет старую view над trusted fixed
   CTE names; для invalid R>G до clipping действует отдельный proof guard.

Readiness: [ ] valid gross RED→GREEN; [ ] isolated/legacy/gap/overflow guards;
[ ] realwriter fixture cleanup; [ ] shared source/coverage inputs bounded.
Новый вопрос exact risk-posting count при partial R/multiple same-origin postings
передан root; до ruling произвольная физическая аллокация не реализуется.

## B02.6d — return aggregate, план до реализации

Root GO-risk: [B02-posting-risk](B02-posting-risk.md) и ADR-049 приняты двумя
CLI reviews0/0. Exact initial distinct postings остаётся; профиль имеет явное
atRiskPostingsLowerBound, capacity minimum+guaranteed IDs union по order,
без physical R→posting allocation и без суммирования SKU counts в account.

1. RED valid10G,2@5d→3@15d→4@H,61boundaryentries и half-open RH=3.
2. Whole order/SKU группы с proven original delivered capacities, одним origin,
   стабильным source quantity после delivery revision и исправными chains.
3. Shared daily receipt calendar/deep proof. Client delta onset/type corrections
   и все прежние links проверяются до curve, invalid quantities не clip.
4. Profile вычислять по необходимым event breakpoints, затем раскрывать61
   boundaries без округления; это exact discrete prefix, не аппроксимация.
5. Проверить multiple same-origin postings, shared postings между SKU, type
   onset/reverse/decrease, different origins, missing/failure/tie/late coverage.

Readiness: [ ] valid boundary RED→GREEN; [ ] risk lower-bound examples;
[ ] source/correction/coverage/tenant guards; [ ] no physical allocation.

B02.6d progress: valid boundary GREEN4/28; risk/shared/type/scope GREEN9/58;
coverage tie/gap and price-only/status-noop GREEN12/70. Логи
`/tmp/b02-training-{return-green,return-guards-green,coverage-price}.log`.
Raw alias regression RED32→expected0 затем source guards GREEN3/17.
Fixture shared-poll UUID registry исправлена без ослабления source guards:
все SKU одного posting/poll используют один app-generated raw UUIDv7.

## B02.6f — actual RR и current read action, план до реализации

1. RED committed integration fixture с двумя connections: late source данные
   записаны старым captured_at в незавершённой transaction; Clock callback
   commits после PIN, до model SELECT. Оба aggregates обязаны сохранить старый
   visible input. Дополнительные SHOW проверяют реальные isolation/read-only.
2. Module-local snapshot helper по существующему паттерну: own RR readonly
   либо strict validation внешнего owner. Реальный txid_current_snapshot PIN
   выполняется **до** Clock/readAt. Metadata SELECT не третий model data query.
3. Action выполняет gross/return ровно по одному data SELECT; default[]/invalid
   epoch дают typed unavailable, не guessed cutoff/частичные коэффициенты.
   Query settings локальные; savepoint восстанавливает внешнюю transaction
   и настройки при успехе/ошибке, собственная transaction откатывается штатно.
4. Проверить unsupported outer transaction и missing epoch. Committed fixtures
   guard app_test[N] и finally удаляют все own history/receipts/facts/statuses.

Readiness: [ ] late-commit RED→GREEN; [ ] own/external readonly scope;
[ ] no epoch failclosed; [ ] no historical manifest claim.

B02.6f: GREEN4/32 metadata/current snapshot diagnostics; keyset cursor scope и
runtime snapshot identity проверены, stale context возвращает unavailable.
Source missing-link regression RED10→0, затем combined17/108 GREEN, включая
budget input/limit/observedLowerBound. Early static stan0/deptrac0/structureOK,
логи `/tmp/b02-training-{diagnostics-green,budget-metadata-green,static-second}.log`.

## B02.6g — план измерения до benchmark

1. Guarded CLI fixture harness только APP_ENV=test/current_database app_test[N],
   собственные synthetic UUID scopes и cleanup всех history/receipt/current
   fact tables. Никаких truncate/reset/production/API вызовов.
2. Target20k groups:5настоящих accepted sales revisions на источник, known
   status transition chain и живые receipts. Synthetic returns разных возрастов
   проверяют не только нулевой профиль, но worst event-boundary expansion.
3. Foreign10× и older-account10× — те же5revisions/group, отдельные scopes либо
   старые business/status days, target data неизменны. Все seed через Builders
   и настоящие writers; budgets/correctness expectations не вычисляются fixture.
4. ANALYZE только локальных test tables; один readonly RR, jit off/timeout5s,
   EXPLAIN(ANALYZE,BUFFERS,FORMAT JSON) обоих queries. Сохранить actual rows/
   execution/planning times/buffers/temp, проверить typed account quantities.
5. Overflow/adversarial fixture отдельно подтверждает unavailable, не partial
   sample. При timeout/неверном плане найти повторные scans и исправить SQL,
   не снижать proof guards и не заявлять benchmark passed.

Readiness: [ ] guarded dataset/cleanup; [ ] actual20k/100k; [ ] foreign/older10×;
[ ] explain/query count/time; [ ] overflow; [ ] limitations recorded.

B02.6g первый actual target20k/100k: RED, PostgreSQL57014 на первом gross
EXPLAIN при5s, scopes очищены. Диагностический повтор увеличивает timeout
только test harness до120s, без изменения consumer/action5s; сохраняет планы
до проверки результата и пропускает noise seed. Это диагностика, не perf PASS.
Следующий шаг: по actual loops/rows/buffers найти повторные scans/joins,
исправить ровно найденную причину и повторить штатный5s benchmark.

## B02.6h — входные типы и дополнительные trust guards, план до правки

1. RED request с int/null/nested-array SKU: controlled InvalidArgumentException
   до array_unique/trim и SQL; DTO после проверки содержит list<string>.
2. Добавить meaningful quantity decrease/link move/future captured source guards
   через настоящие Builders/writers; старые links также должны исключаться.
3. Проверить terminal-only/unknown companion и early-deep coverage. Причины
   диагностики различают unknown delivery и ambiguous origins без ослабления
   уже принятых eligibility guards.
4. Golden canonical D/R/T1/T2/P equivalence на valid fixtures и strong outer
   readonly owner/settings проверяют отдельные риски, не внутренние helper API.

Readiness: [ ] malformed types RED→GREEN; [ ] correction/future guards;
[ ] canonical equivalence; [ ] strong owner; [ ] diagnostics precision.

B02.6g diagnosis actual plans: gross11.878s, return56.628s. Gross
80m rejected join pairs (grouped CTE estimates1row); delivery sources400m
rejected pairs; global event breakpoints expand20k groups to1.2m rows.
Plan before fix: gross signed UNION ALL +GROUP BY instead of grouped join;
first delivered origin window in status anchors instead of repeated join;
order-local breakpoints then exact signed delta/prefix sums for scope profile.
No guessed allocations, no loss of difficult groups, no cap/timeout changes.
Readiness: [ ] semantic integration guards; [ ] exact sparse profiles;
[ ] actual5s EXPLAIN and complete account quantities.

B02.6g second actual5s: gross3.782s with complete200000units/20000postings/
51scope rows; returns still57014. Diagnostic-only current returns5.743s,
gross3.751s. Remaining daily-group proof repeats identical68day windows20k
 times (1.36m rows,1.71s). Next exact change: aggregate distinct required
visual-day windows once (≤91 origins-day windows), equality-join to groups;
profile equality join uses collision-free scope key instead of null-safe
cross scan. Existing enable_nestloop=off report precedent considered; no planner
setting change until needed. Added guards22/149 GREEN include unchanged canonical
D/R/T1/T2/P equivalence, old+new link corrections, future capture, quantity
change/decrease, sparse age preservation, strong outer snapshot/settings.

B02.6h diagnostic reason precision before implementation: diagnostic query
will derive ambiguous_delivery_allocation / delivery_observation_unknown from
bounded return-member/posting proof groups and prefer them for excluded rows.
Aggregate eligibility and performance-measured gross/return SQL remain unchanged;
these are explanatory reasons in the separate diagnostics SELECT. Verify real
pinned readonly diagnostic output with mixed origins and terminal-only history.

B02.6g third штатный target20k/100k: gross3.778s/returns3.855s, оба
51scope rows/200000quantity/20000distinctpostings; noise fixture ещё идёт.
B02.6h self-review дополнение до кода: real-writer RED на sale link move,
сохраняющий другого source прежнего order, и business_date correction за
90day окно. План: history-aware bounded seed/order siblings; стабильная sale
identity включает business_date; invalid source распространяется на все
исторические business_date/order/SKU aliases до whole-day/group eligibility.
Schema/index не менять; дополнительный old-account history scan обязательно
учесть новым EXPLAIN, при необходимости нового индекса сообщить prerequisite.

B02.6h source clarification: normal/backfill writer SET не обновляет
business_date/marketplace_sku; RETURNING ledger хранит исходные значения.
Root указал и исполнитель сверил реальные строки185–222 writer. Поэтому
historical business_date full scan не добавляется, unsupported SQL mutation
не моделируется. Current immutable day locator остаётся верен. Реальные order/
posting moves требуют historical seed orders/siblings через PK/существующий
order index и propagation invalid source на старые aliases. business_date
в chain identity остаётся дополнительным sanity guard.

B02.6g следующий измерительный шаг до запуска: при штатном57014 harness
сохраняет diagnostic120s plan на том же неизменном fixture, затем пробует
existing-report planner policy enable_nestloop=off в новом actualRR с5s и
полными typed quantity assertions. Если и он timeout — отдельный diagnostic
plan. Альтернатива помечена в артефакте; исходный failed gate остаётся RED,
consumer setting не меняется без подтверждения actual plan/typed sample.
Это предотвращает повтор12min seed только ради диагностического SELECT.

B02.6g fourth target с alias guards:5s RED; diagnostic gross4.973s/returns5.200s,
existing enable_nestloop=off также RED(5.072/5.259); consumer policy не меняется.
Actual plan больше не имеет quadratic joins, но имеет repeated wide disk sorts.
Next minimal plan: убрать unused revision id/scope UUID из bounded ledger CTE
(scope явно в WHERE, latest canonical получает trusted UUID constants), narrow
ordered/latest projections по действительным потребителям; все proof fields
сохранить. После ordinary VACUUM ANALYZE локальных app_test tables (cleanup
предыдущих2m revisions оставил dead tuples) повторить target/real10x noise.
Это maintenance test heap, не уменьшение live noise/caps и не ускорение prod claim.

B02.6h latest projections сохранены, behavior26/178 GREEN. Static final
`make lint stan deptrac structure-check`: all exit0, PHPStan0, Deptrac
0violations/0errors, structureOK. Логи `/tmp/b02-training-{lint,stan,deptrac,
structure}-final.log`. Actual source hashes/EXPLAIN fifth pending; пока не
заявляются full backend/performance/review/release readiness.

B02.6h diagnostics pagination дополнительный план: один return order/SKU может
иметь несколько immutable source business days, но return group одна. Добавить
real-builder fixture такого случая и проверить отсутствие duplicate cursor
positions; при RED deduplicate return diagnostic rows, не менять aggregates.

Root ruling до нового source change: known training return types v1 только
Cancellation/ClientReturn (observed subset ADR019, не exhaustive API enum).
Domain/capture всех строк сохраняется; UnknownType→ClientReturn chain даёт
failclosed и precise diagnostic, Cancellation→ClientReturn остаётся valid.
Добавить real-writer RED, затем guard+diagnostic. Текущий fifth performance
после этого guard не финальный; актуальные hashes измерить fresh worker.

## B02.6g fixture hold/recheck — подробный план до helper

Root подтвердил test-only решение. Default всегда finally cleanup; optional
hold только guarded app_test[N]. Seeder сохраняет OWN UUID/context/known TARGET/
FOREIGN/OLDER markers и status phases в local manifest ещё до записи данных.
Fresh readonly measurement worker каждый раз загружает текущий consumer,
пишет code hashes/current actualRR/5s plans+typed assertions в новый artifact.
Worker fixture не меняет; failed5s остаётся failed, diagnostic120s не PASS.
После accepted recheck/release hold заканчивается cleanup всех own histories,
receipts/currentfacts/statuses/dayloads; manifest отмечает cleanup. Новых deps,
общей artifact системы, API/source writer изменений нет.

Retained sixth target default/off strict5s RED; actual diagnostics4.792/5.004s
(default),4.923/5.086s(off). OWN fixture held without DB transaction, manifest
waiting_after_failed_measure, actual plans retained per population. Next single
optimization trial: candidate_groups and source_alias_proof already GROUPBY /
BOOLAND their identities, so inner UNION DISTINCT is redundant. Replace with
UNION ALL only in those idempotent aggregations; compare actual fresh strict
worker on unchanged fixture, preserve caps and all quantity computations.

Noisy sixth default/off actual120s diagnostics exhausted. Cheap estimated JSON
`api/var/net-training-noise-estimated.json` localises sale_keys non-hashed
SubPlan8: seed_orders estimate155564×width146 превышает hash memory, OR/IN
перечитывает20k keys для220k facts. Next exact fix: scoped current_order_keys
JOIN, bounded UNION natural expanded_keys, equalityJOIN locators и explicit
expanded/current-key budget sentinel. Никакого нового memory GUC/индекса.

Measurement method correction по official PostgreSQL16 EXPLAIN TIMING docs:
plain query+typed assertions всегда первым под неизменными5s; measured
EXPLAIN(ANALYZE,BUFFERS,TIMING OFF,FORMAT JSON) тоже5s сохраняет actual rows/
buffers/global ExecutionTime без per-node clock overhead. TIMING ON только
120s diagnostic; его latency не заменяет plain5s и не даёт PASS.

OR locator fix actual noisy diagnostic2:6.617s/6.837s, уже без>120s N².
Strict plain5s всёещёRED. Plan показывает два hashbuild по1.1m revision rows
(нужно100k),32batches/23900tempblocks. Следующий целевой шаг до правки:
bounded parameterized PK load каждого natural source key через LATERAL с
per-source129 sentinel и общим400001 sentinel. Это один set-based SELECT,
не PHPN+1/не coefficient accumulation/не query по каждой training группе;
полные выбранные chains по PK как исходно требовалось. У cap/ordinal guards
та же семантика, source counter>128 даёт whole aggregate unavailable.

B02.6g final noisy source evidence: PK bound исправил whole-account hashing.
`foreign-and-older-10x-attempt3` strict current-source worker COMPLETE exit0:
plain gross4190ms/returns4255ms; EXPLAIN ANALYZE BUFFERS TIMING OFF
4318.774ms/4461.595ms, каждый statement<=5s. Полные51scope rows,
account200000units/20000postings, каждыйSKU4000units/400postings,
RH8000/risk192000, age0postingLowerBound20000. Consumer planner без GUC
изменений (jitoff/timeout5s), schema/index/caps без изменений.
Artifact `api/var/net-training-benchmark-sixth.json.foreign-and-older-10x-attempt3.json`:
актуальные source SHA256/actual rows/buffers/temp/global times, manifest5/5/5
при20000target+200000foreign+200000older groups и5sales revisions/group.
Earlier target baseline source предыдущей версии не выдаётся за finalhash proof.

## B02.6h — финальные проверки до source freeze

1. Format только own PHP files; lint/stan/deptrac/structure на final diff.
2. Full unit, затем integration, затем functional c TEST_WORKERS=2. Во время
   suites baseapp_test fixture удержан idle, measurement queries не запускаются;
   worker databasesapp_test1/2 независимы. Новые errors исправить, rerun только
   affected +gates по изменению; уже прошедшие suites без причины не повторять.
3. После formatting сверить source hashes с actual artifact; если source
   изменился — fresh strict recheck на held fixture, не guessed speed.
4. Exact own paths/status/gate logs root для actual2Codex review. Root-owned
   ADR/risk/B03/structure/prodcompose и чужойsecurity не включать в own list.
5. Fixture удержан только для review-driven performance recheck; обязательный
   final cleanup всех own histories/receipts/currentfacts доrelease, с evidence.

Readiness: [ ] finalstatic; [ ] fullunit; [ ] fullintegration; [ ] fullfunctional;
[ ] finalhashes; [ ] exactfreeze; [ ] reviewroot; [ ] fixturecleanup.

Full suites completed: unit530/3693, integration766/18125,
functional362/3048 — all exit0, TEST_WORKERS2, sequential integration/functional.
До freeze закрывается ещё запланированный initial-provenance trust case:
реальные initialHistoricalBackfill/RawReplay/Unknown→laterLivePolling должны
остаться excluded, хотя latest origin уже LivePolling. Это только fixture/test
добавление, aggregate source/perf hashes не меняются; affected suite rerun.

Final initial-provenance case GREEN29/193: historical/replay/unknown initial
не становится trusted от later LivePolling. Дополнительно existing overflow
case проверяет новый PK per-source bound: limit1 при фактических2revisions
даёт whole aggregate unavailable/revisions_per_source, не first-prefix stats.
Unsupported ledger gap/version corruption не создаётся fake DML/reflection:
реальные writers пишут fact+revision атомарно. Ordinal/min/max/fullcounter
sanity guards присутствуют; supported legacy/provenance/concurrent cases
проверены настоящими writers. Historical commit replay/accuracy не заявлены.

## Source freeze для независимого review

Consumer code/config/tests готовы; следующая code mutation только после triage
root review feedback. Own scope33files, exact paths/hashes в ignored
`/tmp/b02-training-freeze-paths.txt` и `/tmp/b02-training-freeze.json`.
В scope не входят parent ADR/risk/B03/consumer-release/structure/prodcompose
и чужой securety_chek.md. Никаких child Git/stage/commit/deploy операций.

- Final affected29tests/198assertions GREEN после per-source PK cap/initial
  provenance guards; `/tmp/b02-training-final-behavior.log`.
- Full unit530/3693, integration766/18125, functional362/3048 exit0;
  последние fixture/test-only additions дополнительно прошли affected suite,
  production consumer после full suites не менялся. Root full CI ещё впереди.
- Final lint/stan exit0: `/tmp/b02-training-final-lint-stan.log`; PHPStan0.
  Deptrac0violations/0errors и structureOK: `/tmp/b02-training-{deptrac,structure}-freeze.log`.
- Actual current-source noisy strict5s proof COMPLETE: plain4190/4255ms,
  EXPLAIN TIMING OFF4318.774/4461.595ms. Четыре SQLsource SHA256 совпадают
  после final formatter; baseline прошлой версии не подменяет эти hashes.
- Source writers/schema/queues/deps/старуюview не меняли. Default epoch[]
  остаётся failclosed; numericalforecast/probability/money/Facade/API/UI нет.
- Root actual2Codex reviews/full CI/production activation pending. Claude
  пропущен по waiver владельца. B15 replay/B16–B17 accuracy не заявлены.

Readiness source: [x] finalstatic; [x] fullunit; [x] fullintegration;
[x] fullfunctional; [x] finalhashes; [x] exactfreeze; [ ] rootreview/CI;
[ ] finalfixturecleanup. Fixture session37816 сейчас idlehold, manifest
`api/var/net-training-benchmark-sixth.json.fixture.json` measurements_complete,
все5/5/5phases сохранены. Root просил удержать до review-driven recheck; после
reviews mandatory cleanup own scopes и receipts/history/currentfacts.

## Review9c1b9338765b — план исправлений до кода

Оба actual Codex CLI завершены: conformity1 +defects1, root VERIFIED/ACCEPTED
оба (уникальных2, отклонённых0). JSON roles39zwiehs/5y77r46g сохранены в
`var/review/runs/20261028T095212.{467597,468818}Z-9c1b9338765b-*`.
Root GO получен; предыдущий freeze снимается только для этих fixes.

1. Conformity: CLAUDE.md349 требует default50/max200. В DiagnosticsQuery
   заменить default100→50; никаких новых mirrored tests helper API.
2. Defect: latest-capture receipt сам по себе не доказывает полноту gross-дня.
   RED real-writer fixture31posting: initial03:00MSK, terminal15:00MSK и
   receipts intraday. При позднем read p95=0 не разрешает day completeness.
3. Сохранить latestcapture/failure/provenance/initial witnesses; добавить
   completed postings witness после точного конца business_date вMSK,
   приведённого вUTC. Не вводить24h postings cadence из returns policy.
4. Boundary assertion: receipt до00:00MSK не помогает; после00:00MSK
   (ещё предыдущаяUTCdate) открывает полный31quantity sample. Обычные fixtures
   с terminal+2days должны сохранять прежние inputs.
5. Affected query/action tests +static gates; sourceSQLизменился, поэтому
   retained strict5s recheck с новым hash, plainquery+TIMINGOFF evidence.
   Затем обновить exactfreeze; root перезапускает actual2CLI по своему triage.

Readiness: [ ] intraday completeness RED; [ ] exactMSK boundary GREEN;
[ ] default50; [ ] affected/static; [ ] newhash5sproof; [ ] rootrepeatreview.

## Re-freeze после двух принятых review findings

Оба исправления закончены: default50; completed postings witness строго после
концаMSK business_date при сохранении capture/failure/provenance/initial proof.
RED intraday true→expectedfalse подтверждён; GREEN30/207, включая границу
после00:00MSK при прежнейUTCдате. Full integration768/18148 exit0; повторять
неизменённые fullunit/functional не требовалось. Lint/Stan/Deptrac/structure
exit0, PHPStan0/Deptrac0. Логи `/tmp/b02-training-review-{day-red,green,
int-full,lint,stan,deptrac,structure}.log`.

Current source strict noisy recheck COMPLETE:
plain gross4726ms/returns4251ms, EXPLAIN ANALYZE BUFFERS TIMING OFF
4263.463ms/4448.988ms, statement timeout5s сохранён. Все51scopes, account/
SKUcounts и nonzero RH8000 assertions пройдены. Artifact
`api/var/net-training-benchmark-sixth.json.foreign-and-older-10x-recheck-attempt1.json`
с актуальными SQL SHA256 заменяет предыдущую perf reference для нового кода.
Отклонённых findings0, принятых2 (conformity1+defects1); повторные actual2CLI
ещё pending root. Source/config/tests/этот MD снова frozen,33ownpaths.
Fixture37816 оставлен idlehold для repeat review evidence; cleanup pending.

Readiness fixes: [x] RED; [x] MSKboundaryGREEN; [x] default50;
[x] affected/fullintegration/static; [x] currenthash5sproof; [ ] root2CLI.

## Review round2 de0c9e759762 — scoped maturity fix plan before code

Actual conformity0, defects1 NEW VERIFIED/ACCEPTED (finding3 total): p95
currently counts recognized terminal with NULL canonical outcome. ADR02947–58
and existing BuyoutMaturityQuery postingIntervalsSql require known outcome.
Root GO received; old freeze снимается только для этого correction.

1. RED real-writer29delivered dayA +1cancelled dayB с unknown Cancellation
   reason/no handover. Все source/dayend receipts valid; неизвестный исход
   не должен давать30-е p95 observation и разрешать sample дняA.
2. На том же source исправить Cancellation reason на verified pre-handover
   T1; теперь30-е known outcome восстанавливает p95 и корректный sample.
   Cancelled postings не исключаются целиком, min30 не ослабляется.
3. В maturity_postings добавить bounded semijoin/set membership по
   canonical_outcomes outcomeISNOTNULL, до фильтрации day maturity.
   Сохранить scope/provenance/terminal-observed/full-day proof и distinct
   posting count; не изобретать all-outcomes-known conjunction.
4. Affected/static/fullintegration; затем retained37816 strict5s current
   plain+TIMINGOFF recheck с новыми hashes. Повторять unchanged fullunit/
   functional без нового риска не требуется. Re-freeze33ownpaths root.

Readiness: [ ] RED29+unknown; [ ] GREENknownT1; [ ] boundedknown-outcome;
[ ] affected/static/fullint; [ ] currenthash5s; [ ] rootrepeat2CLI.

## Re-freeze finding3: known-outcome maturity

RED29known+unknownCancellation дал erroneous input_complete=true; после bounded
posting semijoin canonical_outcomes outcomeISNOTNULL — GREEN31/216. На том же
source verified T1 reason восстанавливает30-е observation и sample30 (G29/T1=1).
Не добавлялись delivered-only/all-outcomes-known/day-maturity filters в p95.
Scope/provenance/dayend/terminal-observed guards сохранены.

Full integration769/18156 exit0; lint/Stan/Deptrac/structure exit0. Logs
`/tmp/b02-training-maturity-{red,green,int-full,lint,stan,deptrac,structure}.log`.
Current noisy strict5s recheck COMPLETE: plain4234/4266ms, EXPLAIN ANALYZE
BUFFERS TIMING OFF4319.628/4468.939ms; все account/SKU/RH assertions PASS.
Immutable copy: `api/var/net-training-b02-final-aea7843fe9a8-noisy.json`
(полный source SHA проверяется freeze manifest). Временный attempt1 filename
не является постоянным архивом; final copy отделена до повторных reviews.

Own33files снова frozen; `/tmp/b02-training-freeze.json` обновлён. Новых
отклонённых findings0; принято3уникальных всего: firstconformity1,
firstdefects1, seconddefects1. Текущий root round3 pending. Fixture37816
idlehold до решения root по review/cleanup, ни Git ни prod не тронуты.

## Finding4 f3ff2c0c63e8 — подробный план до кода

Root VERIFIED/ACCEPTED: Cancellation превышает cancelled capacity, но старый
ClientReturn-only guard пропускает canonical LEAST clipping. HEAD3cdf024
не меняется; previous round3 invalidated root и не считается review.

1. Shared current-state typed signed balances: ClientReturn↔recognized
   delivered qty, Cancellation↔recognized cancelled qty, отдельно по полному
   order/SKU. Нельзя складывать типы/статусы в одну capacity. Ledger/current
   latest validated inputs; historical Cancellation→Client onset не запрещать.
2. RED realwriters: known cancelled1/Cancellation1→accepted2 исключает день;
   29known +overflowcancel не получает фиктивный30-й p95 observation.
3. Gross whole-day proof и p95 known-outcome membership исключают bad groups;
   возвращать contradictory_quantity diagnostic, не arbitrary clipping.
4. Shared proof используется return population: mixed delivered/cancelled
   group с current Cancellation overflow не может обучать returnCDF.
   Positive multiple-posting mixed capacities и restored current ClientReturn
   после известной type correction остаются valid, если новые capacities fit.
5. Affected/static/fullintegration, затем strict5s noisy held37816 recheck,
   immutable JSON с новыми4SQLhashes. После freezerootcommit/push ДОpackage;
   HEAD/source/context неизменны до обоих CLI completions.

Readiness: [ ] correctionRED; [ ] maturity29guard; [ ] mixedtypedaxes;
[ ] diagnostics; [ ] validmixed/onsetpreserved; [ ] gates; [ ] 5s/freeze.

## Re-freeze finding4 — current typed quantity proof

Shared bounded signed balances считают независимо ClientReturn↔recognized
Delivered и Cancellation↔recognized Cancelled capacities полного order/SKU.
Gross whole-day и p95 known-membership не используют contradictory groups;
return population также исключает их. Diagnostics обоих population дают
contradictory_quantity. Исторического capacity/type-change запрета нет:
проверяется latest current return state, existing monotone ClientReturn
history guards сохраняются.

Real-writer RED reproduced clipped Cancellation1→2 и contaminated30th
maturity; mixed capacity RED дал41вместо31. GREEN34/246 покрывает эти случаи,
ClientReturn не занимает canceled capacity, positive mixed D3+D4/C1+C2,
обе diagnostics и restored current Cancellation→Client с G7/RH5.
Full integration772/18184 exit0; lint/Stan/Deptrac/structure exit0.
Logs `/tmp/b02-training-{cancellation-red,cancellation-mixed-red,
current-quantity-green,current-quantity-int-full,current-quantity-lint,
current-quantity-stan,current-quantity-deptrac,current-quantity-structure}.log`.

Final current-source strict5s noisy recheck COMPLETE/defaultplanner:
plain4068/4403ms, EXPLAIN ANALYZE BUFFERS TIMING OFF4174.584/4546.598ms;
51scopes/account200000/20000/everySKU4000/400/RH8000 assertions PASS.
Root immutable artifact `api/var/net-training-b02-final-quantity-711b773b7c8b.json`,
SHA256 a94ea743ace26682b186a1ab3694f82474f1ad062a2aea200ddd2a5f637fd548.
Current4SQLhashes verified. No source writer/schema/queue/GUC/cap changes.

Own33paths re-frozen at HEAD3cdf024ef6a8cdedd401be25b0ac7a34e3eb8cc9;
source/context больше не меняются без root GO. Root теперь commit/push ДО
package prepare и держит HEAD неизменным до обоих CLI completions.
Всего принято4уникальных findings, отклонённых0; invalidated round3 не
считается успешным review. Новый valid2CLI/CI pending root. Fixture37816
idlehold и runtime передаются root, mandatory finalcleanup остаётся pending.

## Финальная приёмка root

Actual final2Codex CLI packagec4b0198609ac complete/exit0/hashmatch0/0,
all4accepted findings fixed, rejected0, exact35/35code/config/testcoverage.
Exactfc49CI40675962337 и masterCI40676598749 GREEN; PR273 merged446126e,
production643-446126e. Epoch start05:54:45UTC/cutover639 сохранён, parsed
config одинаков у7PHPservices;12running/healthy, readonly consumer smoke и
seller/admin pings PASS. Retainedfixture37816 cleanup finallyexit0,
manifestcleaned,14scoped EXISTSfalse. Readiness rootreview/CI/finalcleanup
завершён. Подробные permanent receipts в B02-consumer-release.md.
B02 закрыта52/68; B15manifest/B16quality не подменялись synthetic validation.
