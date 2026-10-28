## ADR-049: Нетто-прогноз — наблюдения, конечный горизонт и допуск

**Дата:** 2026-10-28
**Статус:** Accepted
**Модули:** Ingestion, потребитель SalesPlanning через существующий Facade
**Дополняет:** ADR-020/029/032/046; текущий прогноз выкупа сохраняет методику.

### Контекст

Дневной факт A использует текущие нормализованные quantities и цены.
`buyout_outcome.D` уже исключает R. Старый BuyoutForecast обучает D при
исключённых R; умножение его результата на новую retention-долю повторно
внесло бы влияние возвратов. Для B нужны собственные gross-ставки G=D+R.

История posting status неизменяема, но `resolved_at` — первое delivered
**или cancelled**, а `resolution_observed` не доказывает время доставки.
Нужен явный наблюдённый переход в delivered с предыдущим известным состоянием.
Это время наблюдения, а не точное событие площадки.

Sales/returns upsert обновляет quantity, связи, тип и цену без прежних
версий. `first_loaded_at` сохраняется, `last_updated_at` меняется при
изменении; ни один из них не восстанавливает прежнюю quantity. Visual clock
возврата означает последнюю смену обработки. Raw дедуплицирован по body hash,
поэтому повторный A→B→A не гарантирует полную временную последовательность.
Read-only RR защищает один запуск, но не создаёт исторические значения.

### Решение

#### 1. Цель и фиксированные параметры v1

Модель `observed_retention_h60_v1` оценивает оставшееся количество в конечном
горизонте **наблюдаемых ClientReturn после наблюдения доставки**. Она
применяется к уже заказанным штукам; будущие ещё не размещённые заказы
в неё не входят. Заказы по-прежнему относятся к исходному дню заказа.
На T берётся весь известный D(T), включая известные поздние R за пределом H;
к нему применяется только ещё не наблюдённый tail до deliveryOrigin+H.
Поэтому при T после H известный D(T) сохраняется: это rolling прогноз от T,
а не восстановление исторического остатка ровно на60-й день.

- H=60 полных суток по 86400 секунд от наблюдения delivered.
- L=7 суток дополнительной проверки загрузок/фиксации дозревшего label.
- Wreturn=90 суток origins полностью дозревших доставок до T−H−L;
  Wgross=90 суток заказных когорт до T с отдельной зрелостью ниже.
- Возраст a — число полных суток с origin, 0..H; события возврата имеют
  bins0..H−1. F(a) включает наблюдения до origin+a суток, F(H) — весь горизонт.
- Минимум training на выбранном SKU или своём кабинете:100 штук и30 разных
  postings; минимум risk-set конкретного возраста:30 штук и10 postings.

Это инженерные параметры версии модели, не правила/сроки Ozon и не
обещание точности. Проверка B16 сравнивает их до численного выпуска;
изменение методики получает новую version, прежние artifacts сохраняются.
Дискретная кривая имеет точность возраста в один день; известные текущие R
всегда учитываются фактом, включая наблюдения внутри текущего возрастного bin.

#### 2. История источников до обучения

Подготовка неизменяемых входов из B15 переносится в начало B02. Совместимая
добавочная миграция создаёт историю принятых sales/returns состояний и
неизменяемые receipts успешных/неуспешных загрузок. Текущие таблицы и
текущие отчёты продолжают выполнять свою роль.

У mutable sales/return source строки появляется внутренний `fact_revision`
с legacy0. Новый source insert получает1; принятый update увеличивает
счётчик под тем же row lock. DBAL accepted UPSERT RETURNING и запись
неизменяемого состояния выполняются атомарно. История хранит source key,
revision, UUIDv7, capture time/version, raw id/hash и поля, необходимые
модели: quantity, order/SKU/posting, тип/статус, исходная цена и валюта.

Естественный ключ истории: company/account/source-row/revision; UUIDv7
создаётся приложением. Noop/rejected update не добавляет revision. Оба
sales writer пути, включая fill-only repair, сохраняют этот инвариант.
MAX(revision)+1 по исторической таблице и timestamp/hash как порядок
принятых ABA изменений не используются. Прежние business поля и clocks
при одинаковом raw не изменяются ради истории.

Legacy или первая захваченная поздняя revision не объявляются начальным
наблюдением source. Capture epoch/version фиксирует начало непрерывного
сбора после переключения всех writers. Происхождение начального состояния
проверяется явно; до epoch onset неизвестен. При откате на writer без capture
epoch закрывается, продолжение открывает новый непрерывный отрезок.
Подробный SQL/схема и порядок cutover фиксируются планом B02 до правки.

Уточнение wire в B02: происхождение задаёт доверенный producer явно
(live polling/backfill/raw replay), неизвестное остаётся unknown. В message
сохраняется прежний serialized набор string полей. Scalar origin передаётся
в уже существующем Symfony SerializerStamp context; принимающий middleware
для этих двух сообщений преобразует его в non-sendable handler argument.
Некорректный/отсутствующий context даёт unknown. Старый consumer декодирует
прежние поля и известный vendor stamp, не загружая новый enum/message field;
новый consumer старой задачи также получает unknown. Retry не переносит
HandlerArgumentsStamp на wire. Transport/queue/routing сохраняются, bus
middleware регистрируется явно. Обязательны реальные old→new/new→old Native
PhpSerializer roundtrips, retry и non-ingestion no-op: optional promoted поле
само по себе ломает старый readonly message при rolling deploy.

Receipts сохраняют tenant/account/report/window/status/capture time,
атомарно с текущей отметкой. Они доказывают и успешную пустую загрузку.
Полнота postings не заменяет полноту returns по их оси visual-change.

Consumer B02 конкретизирует operational coverage как
`daily_complete_receipts_v1`: LivePolling completed witness полного MSK visual
дня после его конца в пределах24h, без пропущенных daily observations;
отдельный сохранённый deep completion закрывает visual дни origin..H
не раньше origin+H+L и не позже T. Поздний catch-up не восстанавливает onset
пропущенного дня. Failure требует более позднего однозначного success нужного
диапазона; одинаковая секунда success/failure остаётся ambiguous, UUID не
задаёт порядок. Postings требуют доказательств нужных initial/terminal states,
а не несуществующего60-дневного ежедневного рескана закрытых заказов.
Это наблюдённая operational полнота, не гарантия будущих событий площадки.
Точные bounds, DTO/SQL, исключения и проверки —
[B02-training](../plan/sales-planning-tasks/B02-training.md).
История изменений сама по себе также не доказывает отсутствие пропущенных
выгрузок. Все растущие чтения ограничены, companyId первый, SQL/DBAL,
keyset и индексы; сырьё/очереди/транспорты и зависимости не меняются.
Автоматического удаления новых observation artifacts этот ADR не вводит.

Capture-only часть B02 допускает отдельный совместимый выпуск для накопления
данных до численной модели; её собственные проверки/два Codex CLI/CI обязательны.
Новая миграция одна на этот подготовительный выпуск, ORM schema обязательна,
fresh rebuild и down проверяются. Production down, удаляющий накопленную
историю, требует сохранённого восстановления; обычный app rollback сохраняет
совместимую добавленную схему.

#### 3. Две отдельные training populations

Обе ограничены своим company/account, доказанным capture epoch и непрерывной
историей нужных входов. Legacy gap, failed/missing load, initial backfill и
неизвестное начало наблюдения дают исключение с причиной.

**Gross delivery:** заказные когорты business_date в окне Wgross, с полным
postings coverage и известными terminal исходами всего исходного количества.
Разрешены T1, D, R, T2, P; G=D+R. Возраст от конца заказного дня должен быть
больше своего observed account p95 по ADR-029, p95 должен быть доступен;
неизвестных/открытых количеств в выбранной gross когорте0. Это правило
зрелости закрытия заказа; здесь не требуются delivered origin каждого T1/T2/P
и60-дневный возвратный хвост. До передачи знаменатель T1+G+T2+P, после G+T2+P.
Пример100G+100T1 даёт pGrossPre0.5; отбор только delivered здесь запрещён.

В новой версии account/day gross completeness проверяется до SKU/page filters.
P95 measurement использует90полных MSK order days после epoch, distinct observed
postings с known-nonterminal→first-terminal proof, до day-maturity filtering;
min30 по ADR-029. Это собственный versioned bounded measurement scope модели,
старый all-history provider и прежние отчёты не меняются. Отсутствие пригодных
наблюдений не заменяется назначенным сроком доставки.

**Return survival:** только доказанные deliveries с явным delivered observation
и предыдущим известным статусом, origins в
[T−H−L−Wreturn, T−H−L), полный H+L, receipts postings/returns и история
полей возвратной кривой. Требование delivered относится к этой population.
Близость возраста к60 и зрелость gross не создают возвратную полноту.

R связывается company/account/order/SKU. Posting из return — trace, не
доказательство физической аллокации. v1 допускает один posting либо несколько
с одинаковым наблюдённым delivery origin. Разные origins при неоднозначном
разнесении возврата дают `ambiguous_delivery_allocation`, без выдуманного возраста.

ClientReturn начинается в первой доказанной accepted revision с этим типом,
а не в первом появлении возврата любого типа. Положительные добавления quantity
дают возрастные приращения; тип/связь/decrease-коррекции, нарушающие монотонную
кривую, исключают всю затронутую order/SKU training группу, включая прежние
связи из истории. Изменение количества исходной продажи после origin также
исключает группу. R>G, отрицательный возраст и противоречивые исходы не
исправляются молчаливым clipping. Счёт исключений и coverage отражаются явно.

Возраст ClientReturn delta берётся по времени принятого наблюдения, не
visual-status timestamp. Horizon полуоткрытый `[origin,origin+60d)`:
F(a) считает события строго до origin+a, bins0..59 и boundary60. Event ровноH
не включается. Known non-ClientReturn→ClientReturn начинает R новым временем,
если source quantities/outcomes непротиворечивы; не всякий type change
автоматически нарушает monotone curve. Обратное из ClientReturn, decrease,
перенос связи или противоречие исключают затронутые группы. Same-second
события разных источников без ordering proof остаются ambiguous.

Исторические срезы mutable таблиц не признаются таким dataset. Узкий
retrospectively-stable subset имеет selection bias и маркируется отдельно;
его качество не переносится на общую population.

#### 4. Формулы и fallback

Gross обучение использует G=D+R. Для открытого заказа до передачи:
`pGross=G/(T1+G+T2+P)`, после передачи `pGross=G/(G+T2+P)`.
T1 остаётся в pre-training. Старые D-ставки/поправки ADR-032 нельзя
подставлять как новые gross. V1 использует собственные stage-only gross
rates; возрастная поправка ADR-032 сохраняет роль в прежнем отчёте.
Возрастная gross-поправка потребует отдельной версии и проверки качества.

Для пригодной return population `F(a)=R_before_age_a/G`:
`q(a)=(F(H)−F(a))/(1−F(a))` для0≤a<H. Свежий прогнозируемый D не должен
сам дозреть до H+L: этот срок уже выдержан его training population.
Для target возраста a≥H риск0 разрешён после доказанной готовности его
H+L/coverage; до неё оценка недоступна. При a<H и пустом training risk-set
положительному D оценка также недоступна. Кривая монотонна,
q в0..1; fallback целой выбранной оценки SKU→свой account→null. Числители
и знаменатели разных источников fallback не смешиваются; scope и samples
сохраняются в diagnostics. Группа без нужной выборки не получает q=0.

Количество alive postings после частичного R не всегда идентифицируемо:
multi-posting order/SKU не имеет физической R-аллокации. Initial sample
postings считается exact distinct; возрастной профиль явно называется
`atRiskPostingsLowerBound`. Порог10 не ослаблен: lowerBound>=10 доказывает
actual>=10. Для group remaining D=G−R_a bound — минимальное k, чьи k largest
original posting capacities в сумме покрывают D. Дополнительно capacity_i>R_a
даёт guaranteed posting ID. В order берётся max(distinct guaranteed ID union
по SKU, max group bound); по orders bound суммируется только при доказанном
posting→oneorder и согласованном origin. Без identity proof — unavailable.
Не суммировать minima разных SKU одного order, не выдумывать аллокацию R,
не исключать такие группы из quantity/CDF population ради min sample.
Bound и его консервативность отражаются в diagnostics; недостаточный proof
даёт nullable assessment/fallback. Метод и примеры —
[B02-posting-risk](../plan/sales-planning-tasks/B02-posting-risk.md).

```text
futureReturns = Σ currentD_i × q(age_i)
openRetained = Σ eligibleOpen_i × pGross(stage_i) × (1−F(H))
expectedNet = Σ currentD_i − futureReturns + openRetained
```

Текущий D уже уменьшен на известные R. В открытом заказе новый хвост начинается
с будущей доставки (age0), её дату модель не выдумывает. Все подтверждённые
T1/T2/P/R без оставшегося D/open дают0 без training. Ненулевой unknown или
отсутствующий нужный компонент делает полный итог null с причиной.

Для цели orders_excluding_pre_handover_cancellations прогнозное выполнение
использует текущие effective orders за вычетом ожидаемых будущих T1 открытой
pre-handover части. Retained goal использует полный expectedNet. Будущие
неразмещённые заказы и факт повторно не прибавляются. Скорость — соответствующий
полный результат / число календарных дней; начальная ручная цель неизменна.

Деньги: цена каждой исходной строки × соответствующий ожидаемый остаток,
integer/numeric и обязательная currency. Никаких float/общей средней РРЦ.
Отношения сохраняются компонентами; money вычисляется до округления quantity,
с единственным HalfUp до minor unit после суммирования. Quantity наружу —
decimal string с3 знаками; отсутствие цены/валюты даёт null денежного компонента.

#### 5. Point-in-time и выпуск

Timestamp/counter не доказывают commit visibility: транзакция может завершиться
позже времени наблюдения. Forward artifact фиксирует фактически видимые
revision IDs/значения и receipts одного read-only RR, модель/coefficients,
свои scope/цены, baseline и прогноз в момент T. Нужны неизменяемое приватное
хранение и проверка hash/версии; до их реализации историческая пригодность
не заявляется. Snapshot capture выполняет отдельный bounded application
сценарий с известным company/account, не меняя GET readonly транзакцию.

Truth привязан к каждому компоненту прогноза и его фактическому origin:
- D, известный на T: ждать deliveryOrigin+H+L и полный coverage; если этот
  срок уже прошёл, зафиксированное D(T) остаётся базой rolling target.
  Label вычитает только новые возвраты оставшихся на T штук внутри ещё
  открытого delivery H; уже известные на T R повторно не вычитаются,
  будущие события после H в target этого прогноза не включаются.
- Открытая на T часть, позже доставленная в d: ждать **d+H+L**, независимо
  от того, сколько дней прошло после T. T+H+L само по себе не закрывает её.
- Подтверждённая без доставки T1/T2/P часть: terminal observation+L,
  receipts и непротиворечивая история дают нулевой вклад.
- Открытая/unknown часть и незавершённый хвост остаются pending/censored;
  таймаут, p95 или истечение T+67 не подставляют terminal и ноль.

Агрегат получает готовый truth только после готовности всех его компонентов;
pending не входит в scored holdout и отражается отдельной долей. Например,
open на T → delivered T+20 → return T+75 (55-й день) обязан включить возврат;
готовность не раньше T+87. В T+67 это censored, даже при успешных загрузках.
Truth artifact фиксирует собственные visible revisions и coverage.
Цена для retention-money фиксируется в input T; последующая коррекция цены
учитывается отдельной диагностикой. Ошибка не вычисляется на будущих текущих
quantity/price, late revisions и поздних successful-load отметках вместо
зафиксированных входов T.

B16 до numeric rollout проверяет одинаковую пригодную holdout population,
возрасты заказов1–2/3–5/6–9/10–15 и доставок0–6/7–29/30–59, MAE, signed bias,
WAPE при ненулевой базе, money error и долю null с причинами. Базы — простая
mature return-доля и отдельно старый projectedBuyout, обе сохранённые на T.
Минимум100 разных order/SKU holdout cases, не менее30 пригодных прогнозов
на публикуемую возрастную группу и origins минимум в двух календарных неделях.
MAE/WAPE и абсолютное bias не хуже простой базы на сопоставимой population;
нарушений изоляции/as_of/quantity/money0. Нулевая/редкая выборка — insufficient,
синтетические случаи проверяют алгоритм, но не заменяют predictive accuracy.

Численный production rollout по умолчанию закрыт до B16/B17. Capture-only
выпуск не считается B18. Если пригодной истории нет, начинается накопление;
неизбежное календарное ожидание H+L не выдаётся за пройденную валидацию.
Причины nullable: source_history_unavailable, returns_coverage_missing,
delivery_observation_unknown, ambiguous_delivery_allocation, insufficient_sample,
unknown_outcome, valuation_unavailable, validation_pending. Контракт/API/UI B12–B14
передают methodology/version/H/scope/quality без ложного полного итога.

### Проверка решения и последствия

B02 проверяет accepted/noop/stale/retry/concurrent/ABA и atomic rollback,
legacy/cutover, fill-only repair, receipts empty/failure и компанию/кабинет.
B03–B10 проверяют censoring, частичные количества, несколько postings,
knownR, q bounds/fallback и70−5+25=90. B11–B14 — цены/валюты/rounding,
контракт, цель/скорость и сохранение старого buyout. B15–B18 — manifest
visibility, честный holdout, quality gate, CI и независимые два Codex CLI.

Стоимость: дополнительная запись accepted revisions/receipts и приватных
evaluation artifacts, индексы и проверка роста. Полная кривая требует
доказанного наблюдения H+L; точность заранее неизвестна. Выигрыш — модель
с явным горизонтом, происхождением данных и честной границей доступности.
