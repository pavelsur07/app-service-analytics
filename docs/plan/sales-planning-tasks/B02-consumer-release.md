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

- [ ] Final source hashes и все required проверки сверены.
- [ ] Два Codex CLI, triage и повторные проверки завершены.
- [ ] PR/CI/merge/deploy точной версии завершены без обхода гейтов.
- [ ] Trusted epoch config и все PHP services проверены.
- [ ] Production readonly smoke/health и собственный fixture cleanup подтверждены.
- [ ] Receipts сохранены; B02 закрыта без claim numeric/accuracy готовности.

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
