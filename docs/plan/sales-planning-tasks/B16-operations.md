# B16 — реальный протокол и операционный чек-лист

> Исполнитель: root, последовательно, по `superpowers:executing-plans`.
> Методика: [B16 §2–7](B16.md), [приёмка](B16-acceptance.md), ADR-049 §5.

**Цель:** зарегистрировать prospective-протокол, собрать неизменяемые прогнозы
и зрелые исходы, сравнить метод с simple и legacy, передать измеренный результат B17.
Используется выпущенный PHP/Symfony CLI, PostgreSQL publication anchors и private S3.
Изменения схемы, API, очередей, зависимостей и порогов не требуются.
Состояние после текущего исполнения: **PROTOCOL_REGISTERED**;
calibration path проверен, prospective inputs=0, quality=NOT_MEASURED.

## План и проверки

1. Проверить ветку/diff, production image/source/epoch/gate и scoped original
   baseline/SKU/currency. Сохранить приватные receipts и hashes чужих документов.
2. Зарегистрировать существующий private draft через штатный quality CLI;
   проверить readback и повтор той же операции. Оставить первоначальные окна:
   calibration 2026-10-19–2026-10-31, holdout 2026-11-01–2027-02-28;
   ежедневные origins 2027-01-18–2027-01-31, 12:00 Europe/Moscow.
   Полные ISO недели: `[2027-01-17T21:00:00Z, 2027-01-31T21:00:00Z)`.
   Truth каждого input: slot+7d и slot+100d; cutoff 2027-05-12T00:00:00Z.
3. После регистрации выполнить сегодняшний калибровочный plan-linked capture
   без prospective binding; проверить replay, неизменяемый retry и pending truth.
   Этот input не входит в final holdout и не подменяет будущий slot.
4. В registered slots выполнить capture с protocol ID/hash; записать все
   success/failure/missing/retry receipts. После окна sealed inventory и selection
   фиксируются до выбора labels. Новую автоматизацию ожидания не добавлять.
5. Собрать все registered truth receipts, seal inventory на cutoff, выполнить
   evaluator на точных refs/hashes, проверить dataset/report readback.
6. Записать exact MAE/WAPE/signed и absolute bias, null/pending denominators,
   simple/legacy comparisons, деньги отдельно по currency. Применить все
   исходные пороги и передать B17 лишь при `QUALITY_PASSED` и полной приёмке.

## Неизменные ограничения

- Один private company/account/plan/original baseline, полный scope одного SKU,
  RUB; идентификаторы и финансовые строки находятся вне Git.
- H60/L7, training 100 units/30 postings, risk 30 units/10 postings;
  100 comparable overall, 30 в каждой из семи групп, две actual origin weeks;
  money 30/currency. Параметры и budgets заданы выпущенным `NetQualityProtocol`.
- Earliest numeric/null selection, paired population, complete receipts,
  quantity/money/provenance и rolling-origin leakage проверяются без изменения
  методики после просмотра исходов. Missing/pending не превращаются в нули.
- `2027-01-03T05:54:45Z` (epoch+67d) — арифметическая нижняя граница, не
  подтверждение достаточного training. Для open→delivery+20→return+75 требуется
  не меньше origin+87d. Registered +100d также не гарантирует coverage.
- CLI/SQL timing не доказывает full HTTP<3s. B17/B18 и publication gate —
  отдельные условия; текущая задача gate не открывает.

## Чек-лист исполнения

- [x] Проверены baseline `42b570df27b8f7c59cac814b65598b9b8e0f2033`, исходный
  пустой tracked diff и отдельная ветка `ops/b16-real-protocol-20261030`.
  Чужие untracked B17/B18/security сохранены отдельно, SHA256 записаны приватно.
- [x] Production preflight 2026-10-30T05:43:25Z: `716-42b570d`, exact source
  совпадает с baseline; scope/original baseline/SKU/RUB подтверждены; epoch
  `01a18233-77aa-78ea-bdce-9d5b273a286f` от 2026-10-28T05:54:45Z открыт,
  config SHA256 `d8c29f03bb72e6b886fbd05aed5d906afa05c83a688b35ddd0750020dcd70e5c`.
  Gate CLOSED. Имеются один прежний input и один truth; это не B16 sample.
- [x] Во всех семи PHP совпали release/source/epoch hash и пустое publication
  value; `doctrine:migrations:up-to-date` exit 0. Pinned one-off preflight
  `2026-10-30T05:48:30Z` подтвердил запуск старого digest со штатным production
  env, readonly scope и closed gate без изменения рабочих контейнеров.
- [x] Protocol sealed `01a188a2-c93b-7c94-8588-b6edf822cf21`, freeze
  `2026-10-30T05:44:47.566603Z`, artifact SHA256
  `e593a367159957b8c5e7d6951f54ea72e3bf8dce5c46aa227098857139a6f253`.
  Reader проверил полный protocol; повтор freeze вернул тот же ref/hash.
  Зарегистрированы 14 input и 28 truth slots.
- [x] Калибровочный capture/replay/retry/truth выполнены. Input
  `01a18b57-4f9c-703d-b17c-638d32d7d03c`, readAt
  `2026-10-30T05:45:17.626356Z`, artifact SHA256
  `9b88f4e1caf1c78c0ca8260dc89c0e53005149773a6558e757036b76fe80d608`;
  66 chunks, CLI wall 9572ms. Replay 5604ms, неизменяемый capture retry
  2699ms, truth 11248ms, все exit 0. Truth parent/hash/chunks проверены;
  summary `pending/components_not_ready`, componentGroups=0, component rows=0.
  Это проверка рабочего пути, 0 prospective inputs, 0 scored cases;
  из пустых components качество не выводится.
- [ ] Выполнены все 14 registered capture slots; отсутствующие отражены явно.
- [ ] Sealed capture inventory и selection, hashes проверены.
- [ ] Выполнены все 28 registered truth slots, sealed inventory на cutoff.
- [ ] Sealed dataset/report, replay и exact counts/metrics/simple/legacy comparisons.
- [ ] Выполнен [полный чек-лист приёмки B16](B16-acceptance.md), результат
  `QUALITY_PASSED`; refs/versions/limitations переданы B17.

## Продолжение и evidence

Владелец исполнения — root. Private рабочий реестр:
`var/b16-live-20261030/` (права каталога 0700, файлов 0600, вне Git);
durable protocol/input/truth публикуются штатно в private S3 с DB anchors.
Копия request/receipts, SHA256 manifest и 42 операторские команды сохранены
на production в `/opt/conwix/private/net-forecast-quality/`
`01a188a2-c93b-7c94-8588-b6edf822cf21/registration-20261030.tar.gz`;
каталог 0700, архив 0600, SHA256
`aea4b61c56a4d664eed9e7123ccf3922d99cfc23e9bcdb6f0709dd0fe40ffe89`
совпал после передачи. Это отдельная серверная копия, её backup не проверялся;
immutable S3+DB evidence остаётся основным источником protocol/input/truth.
Локальный реестр не является постоянным архивом. Публикуемые receipts содержат
только безопасные hashes/UTC/counts, без customer IDs и payload.

Следующий основной capture: **2027-01-18T09:00:00Z**; последний —
2027-01-31T09:00:00Z. Truth +7d: 25 января–7 февраля;
truth +100d: 28 апреля–11 мая, ежедневно 09:00 UTC.
Регистрация протокола не создаёт scheduled job: штатный CLI запускает оператор
в разрешённом production-контуре. После паузы сначала сверить deployed
model/schema/capture semantics, immutable protocol и сохранность epoch;
изменение версии/семантики требует отдельной оценки совместимости.

Protocol закрепляет **точный source**, поэтому основной capture запускается
через pinned image, даже если production обновился. Producer:
`ghcr.io/pavelsur07/app-service-analytics/api@sha256:af3d670bff47c8bfd20f559bfaf9c4d8e423c5dbb5aad0c51969c87e90b4e127`,
APP_VERSION `716-42b570d`, APP_SOURCE_COMMIT
`42b570df27b8f7c59cac814b65598b9b8e0f2033`. На сервере из `/opt/conwix`
используется существующий `IMAGE=<pinned image> docker compose -f
docker-compose.prod.yml run --rm --no-deps -T api php bin/console ...`;
для каждого slot — его registered request UUID, полный registered period,
`--quality-protocol=<id> --quality-protocol-hash=<hash>`.
Не подменять APP_VERSION/APP_SOURCE_COMMIT переменными поверх нового кода.
Доступность digest и совместимость будущей схемы проверять до slots;
при несовместимости — новый prospective protocol, старый не переписывать.

До реальных slots и зрелых truth качество `NOT_MEASURED`, B16/B17/B18 OPEN.
Если capture/truth не состоялся, сохранить факт пропуска; ретроспективный
пересчёт и новый удобный input не восстанавливают потерянное observation.

Изменение репозитория ограничено Markdown: новый операционный план и ссылки/
текущий статус в B16, acceptance и общем плане. Код, пороги и конфигурация
не менялись; отдельное внешнее ревью по AGENTS.md не требуется. Исторические
code reviews не объявляются новыми проходами B17.
