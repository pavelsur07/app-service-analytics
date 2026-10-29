# B16 — чек-лист приёмки и готовности к B17

**Состояние на 2026-10-29: PLAN_READY; B16 OPEN.**
План: [B16.md](B16.md). Методика и точные пороги — его §2–7 и
[ADR-049 §5](../../adr/0049-net-sales-forecast-observations-and-horizon.md).
Этот файл — форма приёмки: незаполненное поле или неотмеченный пункт не PASS.
Сейчас не предъявлены реальные protocol/inventory/dataset/report refs,
sample counts и predictive metrics. Новый evaluator ещё не реализован.

## 1. Статусы и смысл завершения

| Состояние | Достаточное свидетельство | Позволяет считать B16 готовым к B17 |
|---|---|---|
| PLAN_READY | Сверенный план и этот чек-лист | Нет |
| TOOLING_VERIFIED | Реальный код, проверки, оба CLI reviews, CI и capture-only release | Нет |
| COLLECTING | Sealed protocol до captures, реальные slot receipts | Нет |
| WAITING_FOR_TRUTH | Sealed selection, pending/censored counts, условия дозревания | Нет |
| EVALUATED | Полный проверенный dataset/report и воспроизводимое решение | Только при условиях следующей строки |
| B16_ACCEPTED_FOR_B17 | Все обязательные пункты ниже, QUALITY_PASSED, evidence/versions pinned | Да, с явным performance status; B17/B18 и gate остаются отдельными |

При INVALID_EVIDENCE, INSUFFICIENT_DATA или QUALITY_FAILED B16 остаётся OPEN.
Неуспешная оценка — сохранённый результат исследования, не готовность прогноза.
QUALITY_PASSED само по себе не открывает gate. В строках приёмки указывать
проверяющего, UTC, exact commit/ref/hash и результат; сведения о клиентах —
только в приватном реестре, в этом файле безопасные ссылки и сводные counts.

## 2. Вход и единое согласование

- [ ] Зафиксированы baseline и финальный source commit B16, git status/task diff;
  чужие B17/B18/security файлы не включены в diff/package.
- [ ] Сверены B14 release receipt PR285/CI685–686/production686-a3c0489 и B15
  PR286–287/production693-6770cf2/migrationVersion20261029041000; результаты
  нового production preflight записаны отдельно от старых receipts.
- [ ] Документальное расхождение открытого B15 checkbox с его release receipt
  разобрано явно; основанием служат реальные проверки и выпуск B15.
- [ ] Одним решением согласованы два пункта B16 §0: kind5→20 с прежним PK
  и одна migration; optional protocol binding в существующем capture Facade.
- [ ] Private scope установлен: одна компания/кабинет, plan/original baseline,
  полный набор<=50SKU,<=184 order days, целевые валюты и существующий epoch.
- [ ] Проверены private storage access, anonymous denial, retention/lifecycle,
  tenant deletion inventory и recovery limitations для quality kinds.
  Не требуется объявлять весь bucket WORM или обещать защиту от администратора.

## 3. Механическая готовность инструментария

- [ ] Quality enum/Entity/migration/reader согласованы; прежние input/truth
  читаются и replay дают тот же результат. Parent DAG и глубина<=6 проверены.
- [ ] `down()` с quality rows останавливается до сужения; rollback приложения
  не удаляет evidence. Empty DB rebuild, up/down/up и schema validation PASS.
- [ ] Winner/retry/lost response/requestHash conflict, два соединения и rollback
  проверены тестами; S3 IO вне DB transaction, tenant/account explicit.
- [ ] Protocol проверяется до capture RR, ref входит в новый request hash
  и sealed input. Без protocol сохраняется прежний B15 hash; retrospective
  привязка не получает prospective eligibility.
- [ ] Hash/version/size/cross-tenant tamper, неверный parent/cycle, missing input
  или обязательный receipt приводят к invalid/unavailable, без успешного усечения.
- [ ] Earliest selection не зависит от numeric/null/ошибки; delivery mask и
  whole-order leakage проверены. Изменение live sources/Clock score не меняет.
- [ ] Exact signed fractions, money rounding, null denominators, zero truth,
  все границы count/week/age/precision и приоритет решений покрыты тестами.
- [ ] Normal resource profile PASS:<=200 input refs,<=200 truth refs,
  <=20000 candidate case-observations,<=512MiB фактических read bytes,
  <=120s общего deadline,96MiB peak,48MiB additional allocation,
  <=130MiB private spool; output<=128 chunks по<=1MiB.
  B15 model/capture/replay caps сохранены; повторные readback/parent GET учтены,
  concurrent dataset/report spools не удвоили общий лимит.
- [ ] Реальные loaded-source/adapter hashes, GET/bytes, wall/heap/peak/spool
  measurements и cleanup приложены; overflow tests не заменяют normal profile.
- [ ] Dataset публикуется до report; partial failure не помечается EVALUATED;
  deterministic dataset request и verified winner обеспечивают честный retry.
- [ ] CLI печатает только refs/hashes/status и агрегаты, без order/source payload;
  отсутствуют all-tenants/latest/as-of modes, marketplace requests и gate writes.

## 4. Регистрация протокола до исходов

- [ ] Есть immutable `quality-protocol` ref/hash, code/model/schema/codec versions,
  actual epoch ID/start, timezone policy и подтверждение freeze до final captures.
- [ ] Закреплены H60d/L7d/Wreturn90d/Wgross90d, training100units/30postings,
  risk-set30units/10postings, baseline `simple_mature_return_linear_h60_v1`.
- [ ] Calibration/final holdout order intervals не пересекаются целыми orders;
  все scope/currency/baseline/thresholds заданы до просмотра outcomes.
- [ ] Capture schedule12:00 MSK, заранее назначенные request UUID/slots,
  future origin window из полных ISO недель>=2, truth schedule/cutoff/coverage
  policy закреплены; actual winning readAt учитывается отдельно от slot time.
- [ ] Зарегистрированы все7 групп: order1–2/3–5/6–9/10–15 и
  delivery0–6/7–29/30–59. Weak slice не удалён после просмотра метрик.
- [ ] Правила earliest selection, tie-break, paired masks, candidate/ready
  denominators, missing slots, repeated orders и rolling-origin overlap закреплены.
- [ ] Точные thresholds §6 включены в protocol; изменённая методика/порог
  требует новой версии и нового prospective holdout, прежний отчёт сохраняется.

## 5. Реальные наблюдения и truth

- [ ] Capture inventory sealed: все planned slots и каждый success/null/failure/
  missing/retry отражены; нет отбора первых100 удачных или замены раннего null.
- [ ] Selection sealed до выбора labels: exact input IDs/hashes, earliest cases,
  masks, group assignments и actual origin weeks воспроизводимы.
- [ ] Truth inventory sealed на registered cutoff: полный набор запланированных
  observations/receipts. Выбран earliest fully-ready `(truthReadAtUtc,truthId)`;
  отсутствие ready остаётся pending/censored, не нулём.
- [ ] Все scored components готовы по B15; open→deliveredT+20→returnT+75
  не объявлено ready вT+67. KnownR не вычтен повторно, after-H не добавлен
  как новый forecast-tail return; unknown/ambiguous не превращены в terminal0.
- [ ] Quantity/money/priceT/source IDs соответствуют input и truth; новые
  corrections/invalidation отражены отдельными artifact/report событиями.
- [ ] Проверены actual training membership и leakage; допустимые known-on-T
  gross/rolling overlap показаны отдельно. Строгая независимость whole-order
  holdout не заявлена там, где используется rolling-origin design.
- [ ] Dataset и report sealed, все refs/hashes/versions проверены повторным
  чтением/replay; нет unresolved invalidation или integrity violation.

## 6. Пороги качества — обязательны все

| Проверка | Условие PASS | Фактический результат |
|---|---|---|
| Инварианты | 0 tenant/account/as_of/provenance/quantity/money нарушений | NOT_MEASURED |
| Overall sample | >=100 distinct primary comparable order/SKU cases | NOT_MEASURED |
| Возрастные samples | >=30 primary comparable cases в каждой из7 групп | NOT_MEASURED |
| Временной охват | >=2 actualReadAt ISO weeks overall и в каждой группе | NOT_MEASURED |
| Quantity denominator | Σtruth>0 overall и в каждой обязательной группе | NOT_MEASURED |
| Quantity accuracy | MAE, WAPE, absoluteBias метода<=simple на одинаковых paired rows, tolerance0, overall и все7 групп | NOT_MEASURED |
| Availability | Method-null-share<=simple-null-share на одном ready denominator | NOT_MEASURED |
| Деньги | Каждая registered currency:>=30 distinct overall paired cases,Σ\|truthMoney\|>0 | NOT_MEASURED |
| Money accuracy | Rounded-minor MAE и absoluteBias метода<=simple, tolerance0, по каждой registered currency | NOT_MEASURED |
| Legacy | Есть сравнение или доказанные scope/target limitations и unavailable reasons; нет fabricated per-case net | NOT_MEASURED |

- [ ] Для каждой строки приведены exact numerators/denominators/counts и ссылка
  на sealed report; equality проходит, rounded display не определяет gate.
- [ ] Отчёт включает signed bias, exact money metrics, candidate/ready null
  shares, pending/censored/ineligible counts, distinctOrders, reason counts и
  состав paired set. Missing input slots отдельно, без выдуманного числа cases.
- [ ] Method/simple сравнены на одном наборе; legacy intersection имеет свои
  method metrics и denominator. Null legacy не уменьшает primary sample.
- [ ] QUALITY_PASSED вычислен без смены criteria после outcomes. При нехватке
  данных decision=INSUFFICIENT_DATA, при integrity/protocol failure=INVALID_EVIDENCE,
  при достаточных данных и регрессии=QUALITY_FAILED; failed metrics сохранены.

## 7. Проверки, независимое ревью и приёмка кода

Команды ниже — будущий обязательный прогон реализации, **сейчас не запускались**.

- [ ] `make lint stan deptrac structure-check` — PASS.
- [ ] `make test-unit test-int test-func TEST_WORKERS=2` — PASS.
- [ ] `make db-rebuild-check TEST_WORKERS=2` — PASS на подтверждённых LOCAL томах;
  уничтожение локальных volumes не затронуло чужое окружение/данные.
- [ ] `make api-types-check` — PASS, public API/OpenAPI/TS не изменены;
  при реальном изменении source contract — regeneration и проверки потребителей.
- [ ] Финальный task diff полностью покрыт exact review package; два отдельных
  Codex CLI `review-codex` и `review-defects` complete/hash-valid. Claude не нужен
  по [исключению владельца этой фичи](../sales-planning-review-rule.md).
- [ ] Все замечания разобраны, принятые исправлены, затронутые проверки/ревью
  повторены. Записаны уникальные принятые findings каждого дополнительного
  прохода сверх предыдущих, модели/session IDs и постоянные refs полных ответов.
- [ ] Обязательный CI полного branch diff зелёный, включая audit/E2E;
  точный merged/deployed source совпадает с проверенным.
- [ ] Capture-only tooling release/migration проверены на production; старый
  replay работает, public gate закрыт. Это не выпуск численного прогноза B18.

## 8. Итоговый протокол приёмки и передача

Заполнить фактическими значениями; `не предъявлен`/`не измерено` остаются
блокерами соответствующего уровня, а не трактуются как успешные проверки.

| Поле | На момент подготовки плана |
|---|---|
| Baseline B16 | `fac86b636c2bbcd0156c65555abbe69113272c9d` |
| Final source / tooling release / migration / CI refs | Не предъявлены |
| Private scope ref | Не предъявлен |
| Protocol ref/hash/freeze UTC | Не предъявлены |
| Capture inventory / selection refs+hashes | Не предъявлены |
| Truth inventory / dataset / report refs+hashes | Не предъявлены |
| Counts overall /7 групп/валюты/недели | Не измерены |
| Mechanical validation / review receipts | NOT_RUN |
| Predictive decision | NOT_MEASURED |
| Performance validation | NOT_MEASURED; B14 full Action wall16.17–20.60s не HTTP proof |
| B16 acceptance | OPEN |
| B17 / B18 / numeric gate | OPEN / OPEN / CLOSED |
| Проверяющий / UTC / ограничения | Не заполнено |

- [ ] Подтверждены все обязательные пункты2–7; reproducible report даёт
  QUALITY_PASSED. Объём доказанных claims совпадает с registered scope.
- [ ] Performance status записан честно: для численного допуска нужен полный
  HTTP<3s, ровно3s не PASS. Нельзя подменять его внутренним compute<5s,
  CLI runtime или mocked/unit latency. Pending/failed HTTP явно передан B17/B18.
- [ ] B17 получил final commits, protocol/inventory/selection/dataset/report
  hashes, counts/metrics/decision, reviews/CI и ограничения. Его финальные
  проверки/два CLI прохода не объявлены выполненными этапными ревью B16.
- [ ] Numeric publication остаётся CLOSED; отдельное решение B18 впереди.
  Только после этого B16 отмечается `B16_ACCEPTED_FOR_B17` в общем плане.

Если данных ещё нет: сохранить состояние COLLECTING/WAITING_FOR_TRUTH,
точные реальные counts/reasons, следующий зарегистрированный slot или
maturity condition и immutable refs. Дата epoch+67d — условная нижняя граница
первого возможного maturity, не обещанный срок готовности B16.
