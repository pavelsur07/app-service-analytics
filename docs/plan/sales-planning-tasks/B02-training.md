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
DTO возвращают exact quantity/count/prefix components и distinct posting
risk-set counts на61 boundary; без q/rates/float/money и без округления.

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
- [ ] Epoch/request/source/coverage proof guards реализованы и проверены.
- [ ] Обе populations дают целые inputs, overflow/null причины честные.
- [ ] Distinct account fallback полный, snapshots согласованы, diagnostics scoped.
- [ ] Корректность/canonical equivalence/границы/изоляция подтверждены tests.
- [ ] EXPLAIN/лимиты/query count подтверждены фактически.
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
