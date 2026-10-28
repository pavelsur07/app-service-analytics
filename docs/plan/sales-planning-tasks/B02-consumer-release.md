# B02 — проверка и выпуск потребителя истории

## Подробный план до выпуска

Постоянное разрешение — CLAUDE «Merge, миграции и доступ к серверу».
Повторное согласование этапов не требуется; все предусловия сохраняются.
Численный прогноз и quality gate B16/B17 этим выпуском не включаются.

1. После sourcefreeze сверить собственный diff, точные paths и hashes
   performance artifacts. Сохранить чужой `securety_chek.md`. Схема и writers
   этого consumer изменения не менялись; новая миграция не планируется.
2. Проверить affected/full backend/static results, реальные5s plain queries
   и EXPLAIN с чужими/старыми10× данными и полными typed assertions. Документировать
   TIMING OFF для измерений и отдельное диагностическое профилирование.
   Partial/running/diagnosticOnly artifacts не считаются performance PASS.
3. Добавить root production env wiring и structure/report docs в итоговый
   пакет. Проверить scalar JSON default[] и explicit value всех7PHP services.
   Выполнить2actual независимых Codex CLI; принять/отклонить содержательные
   замечания, исправить принятые, повторить затронутые проверки и review.
4. После green обязательного CI точного PR HEAD проверить master/конфликты/
   blocking review threads, merge с expected head SHA. Отследить штатный
   image/deploy. Неожиданные pending migrations не применять молча.
5. Проверить новую версию public seller/internal admin, здоровье всех сервисов
   и образ api/всехPHP workers. Readonly smoke вызывает новый bounded consumer
   с заведомо отсутствующим изолированным company/account; выводить только
   метод/готовность, не реальные бизнес-значения. Marketplace API не вызывать.
6. Проверить прежнюю epoch config перед изменением. Для первого segment
   сгенерировать UUIDv7 приложением; указать accepted_state_v1,
   startUtc=2026-10-28T05:54:45Z, endUtc=null, cutoverRelease=639-6ceaa3e.
   Эта граница подтверждена source release в B02.md; время миграции или
   текущего deploy вместо неё не подставлять. Существующие segments сохранить.
7. Передать nonsensitive JSON в production env без вывода остальных env/
   секретов, обновить PHP services штатной compose конфигурацией, проверить
   parser/config fingerprint и readonly consumer smoke. Совместимый capture
   upgrade не открывает новый segment; rollback writer без capture закрывает
   segment до первого его write. Numeric feature остаётся закрытой.
8. Очистить все собственные retained benchmark scopes и подтвердить cleanup;
   сохранить PR/CI/deploy/tag/cutover/config receipts и ограничения в Markdown.
   Только затем закрыть B02. Реальная predictive accuracy остаётся B16,
   а не результатом synthetic tests/EXPLAIN.

## Чек-лист готовности

- [x] Final source hashes и все required проверки сверены.
- [x] Два Codex CLI, triage и повторные проверки завершены.
- [x] PR/CI/merge/deploy точной версии завершены без обхода гейтов.
- [x] Trusted epoch config и все PHP services проверены.
- [x] Production readonly smoke/health и собственный fixture cleanup подтверждены.
- [x] Receipts сохранены; B02 закрыта без claim numeric/accuracy готовности.

Подготовка: host compose config rendered с `/dev/null` вместо env-файла и
dummy required values; default[]/explicit JSON сохранены как scalar у7PHP
services, обе проверки PASS. Отчёт `var/sales-planning-b02-prod-env-validation.json`.
Контейнеры/БД этим rendering не запускались и production env не менялся.

## Первый независимый review — 2026-10-28

Пакет `9c1b9338765b933d242caeda43e5e66569ac54e3ff2e5b775d27a968d5fb2e3a`,
44 файла всего diff ветки от `6ceaa3e`, без чужого `securety_chek.md`.
Два реальных Codex CLI v0.164.0, модель из stderr `gpt-6.1-sol`,
разные read-only сессии вне рабочей копии, оба complete/exit0/совпавший hash.

- Соответствие: session `01a181ec-ab45-73b2-a26f-15b33c9f6fb2`,
  run `20261028T095212.467597Z-9c1b9338765b-codex-39zwiehs`: одно
  уникальное принятое замечание — diagnostic default100 заменить на50
  согласно CLAUDE§5; max200 и keyset сохранить.
- Дефекты: session `01a181ec-ab48-74b1-8afc-e6bb6e52e969`,
  run `20261028T095212.468818Z-9c1b9338765b-defects-5y77r46g`: одно
  дополнительное уникальное принятое замечание — intraday completed witness
  не доказывает полноту всего MSK business_date. Нужна успешная загрузка
  после окончания дня и регрессионная проверка; p95 maturity недостаточно.

Отклонённых замечаний нет. Исправление выполняется в рамках исходного
объёма B02; после него обязательны повторные проверки, performance proof
с новыми SQL hashes и два прохода изменившихся частей. Merge/deploy pending.
Локальные JSON/metadata/stdout/stderr находятся в `var/review/runs/<run>/`;
это рабочие артефакты, постоянные итоги и идентификаторы сохранены здесь.

Исправления первого review завершены до повторного пакета: diagnostic
default50 и full-MSK-day completed witness. Новый regression сначала
воспроизвёл intraday дефект (RED), после guard GREEN30tests/207assertions.
Полный integration768/18148 и lint/stan/Deptrac/structure PASS.
Повторный actual strict5s noisy benchmark COMPLETE с текущим SourceSql
SHA256 `b272ddabf14c3b5b7fa84e06ee76614cc535c8543c6c5d93711464b193a8ea3a`:
plain gross4726ms/returns4251ms, EXPLAIN TIMING OFF4263.463/4448.988ms;
все4SQLsourcehashes совпали, default planner, typed counts/profile assertions
PASS. Артефакт `api/var/net-training-benchmark-sixth.json.foreign-and-older-10x-recheck-attempt1.json`.
Source re-frozen; повторный review/CI/deploy ещё pending.

## Второй независимый review — 2026-10-28

Пакет `de0c9e759762d75c5847344cb46ba2c8e6aac962e1836ef62ae3a37e600dea63`.
Оба actual Codex CLI v0.164.0 complete/exit0/совпавший hash, модель из
stderr `gpt-6.1-sol`. Первый проход: 0 новых замечаний, session
`01a181f8-7245-7ce1-ada9-2b9245e9bbb4`, run
`20261028T100504.861992Z-de0c9e759762-codex-ml763h7c`.
Второй: 1 дополнительное уникальное принятое замечание, session
`01a181f8-7256-79c0-bd97-6076a7ed1853`, run
`20261028T100504.860824Z-de0c9e759762-defects-cn5magjs`.

`maturity_postings` учитывал recognized terminal status без known canonical
outcome. Проверено по ADR02947–58 и существующему BuyoutMaturityQuery:
29known postings +одна unknown Cancellation могли открыть p95 и зрелость
другого дня. Замечание принято; T1/T2/P с известным исходом должны оставаться
в измерении, delivered-only отбор недопустим. Исправление, regression и новый
current-source benchmark выполняются до третьего review. Всего принятых
уникальных замечаний по consumer3, отклонённых0; merge/deploy pending.

Исправление третьего finding завершено: bounded membership по
`canonical_outcomes.outcome IS NOT NULL` до day maturity filtering.
RED воспроизвёл ошибочный30-й observation; GREEN31/216 доказывает исключение
unknown Cancellation и восстановление p95 известной T1. Fullintegration
769/18156, lint/stan/Deptrac/structure PASS. Strict5s noisy recheck текущего
кода COMPLETE: plain4234/4266ms, EXPLAIN TIMING OFF4319.628/4468.939ms.
GrossTrainingAggregateQuery SHA256
`aea7843fe9a8801890046f1d77b3de55973ff9f6bbbadc82e2b667b7963d8aa0`,
все4SQLhashes совпали. Root сохранил отдельную immutable копию:
`api/var/net-training-b02-final-aea7843fe9a8.json`, artifact SHA256
`c2c97df7ed0d4e604ddc6f69f7d604d0626be2eb8d3839ee331171e7e2bf53fe`.
Предыдущий recheck-attempt1 filename использован helper повторно; прежние
замеры зафиксированы выше по времени проверки, final source proof относится
к этой отдельной копии. Никаких metric/порогов/planner overrides не ослабляли.

Третий пакет сохраняет весь diff44files и все предыдущие findings/triage.
Применимые backend/test/prod разделы patterns/structure приложены точными
выдержками с SHA256 полных источников; исторические журналы сверх20KB
прикладываются дифом, ADR и код сохранены полностью. Это убирает дублируемую
историю предыдущих релизов, scope итогового кода остаётся полным.

## Третий и повторный проходы — 2026-10-28

Пакет3 `0adad595b11d1957f2b9d33b769a785bdb32472c1d3905f8a30b88fcbf94aba0`:
conformity `20261028T102435.848834Z-0adad595b11d-codex-qzpve8r0` complete0.
Defects `20261028T102435.856999Z-0adad595b11d-defects-mnsrxk3x` вернул
complete/findings0, но wrapper после ответа обнаружил изменение HEAD автором
из-за преждевременного commit26118f5→3cdf024. Итог failed, review.json нет;
этот ответ НЕ засчитан, source bytes не менялись. Это ошибка порядка работы
автора. Новый пакет собран на стабильном committed HEAD, merge не выполнялся.

Коммит `3cdf024ef6a8cdedd401be25b0ac7a34e3eb8cc9` опубликован в draft
[PR273](https://github.com/pavelsur07/app-service-analytics/pull/273).
[CI40672542070](https://github.com/pavelsur07/app-service-analytics/actions/runs/40672542070)
полностью GREEN: backend/frontend/services/e2e/contract+audit/path filters;
image/deploy пропущены на feature branch штатно. Чужой securety_chek.md
не staged/не включён в commit.

Пакет4 `f3ff2c0c63e8ddf5b733b605e9b111a44c334d42ebc15c5badb9fb7921a81367`,
оба actual Codex v0.164.0 complete/exit0/совпавший hash; модель из stderr
`gpt-6.1-sol`. Conformity
`20261028T103103.345188Z-f3ff2c0c63e8-codex-uyxdyo24`, session
`01a18210-3934-72e3-9219-83ae763fe66c`: 0 новых замечаний. Defects
`20261028T103103.336002Z-f3ff2c0c63e8-defects-3x8v0vfs`, session
`01a18210-3931-7d52-ba15-a9542bb0d3ec`: 1 новое уникальное принятое
замечание — Cancellation quantity overflow при cancelledsale1/Cancellation2
молча обрезался canonical allocation и допускал T1 в gross training.
ClientReturn overflow guard уже есть, Cancellation capacity нужно доказать
отдельно. Всего уникальных принятых4, отклонённых0.

Scoped correction выполняется с RED/GREEN, static/fullintegration и новым
current-source performance proof. После correction root сначала commit/push,
затем package+2CLI на неизменном HEAD; прежний GREEN CI не подменяет новый.
PR остаётся draft, merge/deploy pending.

Finding4 исправлен shared typed current-state capacity proof. Отдельные
ClientReturn/delivered и Cancellation/cancelled balances исключают bad
order/SKU из gross-дня, p95 measurement и return population; diagnostics
возвращает contradictory_quantity. Корректные multiple-posting mixed groups
и known Cancellation→ClientReturn onset сохраняются при допустимых текущих
quantities; исторического type-change запрета не добавлено.

Real RED воспроизвёл cancellation clipping и mixed group41vs31. GREEN34/246
покрывает оба независимых capacity axes, maturity minimum, diagnostics и
положительные mixed/onset cases. Полный integration772/18184 и все static
gates PASS. Strict noisy current-source proof COMPLETE: plain4068/4403ms,
EXPLAIN TIMING OFF4174.584/4546.598ms, defaultplanner, typed counts/profile
и все4SQLsourcehashes подтверждены. Immutable JSON:
`api/var/net-training-b02-final-quantity-711b773b7c8b.json`, artifact SHA256
`a94ea743ace26682b186a1ab3694f82474f1ad062a2aea200ddd2a5f637fd548`.
Gross SHA256 `1f77766c53a55cd7f4d2a7fb9500064264b3d08b2bd7bf3af173f2abe4466c1f`,
Source SQL SHA256 `6cd9fbcf1967241711a670a2a6a8dd7ab2d99b8d8559515d447c8008acd2ee07`.
Следуют committed snapshot, новый CI и actual2CLI; старый GREEN CI не
объявляется проверкой нового diff. Merge/deploy пока не выполнялись.

## Итоговое независимое ревью — 2026-10-28

Committed HEAD `fc49a9cb485aa52c1fff0b275dbc587ca9787e72` создан и опубликован
ДО подготовки пакета; весь HEAD/source/context не менялся до обоих ответов.
Пакет `c4b0198609acda9a025f4e8b982a6258ba00cd4d60c5ab75235c1100e01a4036`.
Оба реальных Codex CLI v0.164.0 завершились complete/exit0 с совпавшим
SHA256; фактическая модель из stderr `gpt-6.1-sol`.

- Conformity `20261028T105604.457389Z-c4b0198609ac-codex-njrnixzf`,
  session `01a18227-206f-7c63-8e90-c8b123ff3f7d`: 0 новых замечаний.
- Defects `20261028T105604.447541Z-c4b0198609ac-defects-j94voth5`,
  session `01a18227-2067-7c11-bc21-2ff1c728e298`: 0 новых замечаний.

Все4 уникальных ранее принятых findings исправлены; отклонённых0.
Additional unique accepted counts по последовательным валидным проходам:
round1 conformity1/defects1, round2 conformity0/defects1, round3 conformity0
и invalid defects не засчитан, round4 conformity0/defects1, final0/0.
Нумерация и triage всех действительных предыдущих прогонов приложены.
После завершения обоих CLI exact-blob audit подтвердил35/35 non-MD файлов
всего branch diff относительно6ce; missing0/errors0. Рабочий артефакт
`var/sales-planning-review-coverage.json`, постоянный результат сохранён здесь.

Новый [CI40675962337](https://github.com/pavelsur07/app-service-analytics/actions/runs/40675962337)
проверяет exact fc49a9c; его завершение, retained fixture cleanup и выпуск
пока ожидаются. Root stdin37816 недоступен в собственной process namespace;
cleanup поручен тому же child, которому принадлежит retained session.
Это локальная тестовая очистка, production не затрагивается.

## Приёмка исходников и merge — 2026-10-28

Exact fc49a9c [CI40675962337](https://github.com/pavelsur07/app-service-analytics/actions/runs/40675962337)
SUCCESS: все обязательные backend/frontend/services/e2e/contract+audit/filters.
Сборка image/deploy на feature branch штатно skipped. Перед merge проверены
ready PR, CLEAN/MERGEABLE, base6ce, exact headfc49 и0 review threads.
Expected-head squash merge [PR273](https://github.com/pavelsur07/app-service-analytics/pull/273)
выполнен `2026-10-28T11:00:40Z`, merge commit
`446126efe3dc99fb87daec0a5e2ea40a8e6d4ec2`. Обходов CI/review/admin/force
не использовали; master локально не checkout.

Benchmark cleanup выполнен владельцем session37816: cleanup/finally exit0,
manifest cleaned. Readonly scoped EXISTS по7таблицам×2ownscopes — все14false,
данные fixture отсутствуют. Proof `/tmp/b02-training-cleanup-proof.json`,
проверено root; production этим cleanup не затрагивался. Никаких reset
базы/томов, globalfactCOUNT и чужих удалений не было.

Штатный master image/deploy и physical epoch consumer activation ещё
ожидаются; B02 и глобальный счётчик51/68 пока не закрыты. Эти фактические
post-merge receipts пока локальные и будут сохранены с продолжением плана.

## Production verification и закрытие B02 — 2026-10-28

[Master CI40676598749](https://github.com/pavelsur07/app-service-analytics/actions/runs/40676598749),
run643/attempt1, merge446126e — SUCCESS, включая image и штатный deploy.
Новых миграций consumer не принёс; штатный up-to-date gate пройден.
Все12сервисов running, все настроенные healthchecks healthy. API и все6PHP
workers используют image `643-446126e`, ozon-fetcher также с этим тегом.

До config readonly consumer smoke подтвердил empty-default failclosed:
нет trusted epoch, source_history_unavailable, транзакция закрыта.
Затем приложение в новом image сгенерировало UUIDv7
`01a18233-77aa-78ea-bdce-9d5b273a286f`. Существующей epoch строки в.env не
было. Атомарно добавлена только несекретная NET_FORECAST_CAPTURE_EPOCHS_JSON,
остальное содержимое/права сохранены; после этого штатно обновлены7PHPservices.

Epoch: accepted_state_v1, startUtc `2026-10-28T05:54:45Z`, endUtc null,
cutoverRelease `639-6ceaa3e`. Сохранена исходная подтверждённая граница всех
capture writers; совместимый consumer deploy не начал историю заново.
Fingerprint конфигурации
`d8c29f03bb72e6b886fbd05aed5d906afa05c83a688b35ddd0750020dcd70e5c`
проверен одинаковым у всех7PHPservices вместе с parsedepoch/версией/start/release.

Финальный bounded readonly RR smoke нового action прошёл для заведомо
отсутствующих company/account: account/SKU scope rows, unavailable gross
при отсутствии maturity, пустая return population без выдуманной вероятности,
закрытая транзакция. Реальные бизнес-значения не читались/не выводились,
marketplace API и synthetic production writes не выполнялись. После refresh
nginx конфиг проверен/перечитан; public seller ping и internal admin ping
`2026-10-28T11:11:39Z`/`11:11:40Z` подтвердили `643-446126e`.

Readiness завершён, B02 закрыта; общий прогресс52/68. Числовой нетто-прогноз
и predictive accuracy этим выпуском не объявляются готовыми. Следующая B03
работает с проверенными count/profile inputs, B15 historical manifest и
B16/B17 quality gate остаются отдельными задачами. Локальные receipts:
`var/sales-planning-b02-consumer-{master-ci-final,services-epoch,release-receipt}.json`,
smoke-preconfig/final.log, health-preconfig/final.txt, seller/admin-ping.json,
epoch-config.log и config-refresh.log; основные результаты сохранены здесь.
