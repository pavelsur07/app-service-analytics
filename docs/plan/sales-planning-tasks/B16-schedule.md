# B16 — план исполнения зарегистрированных запусков

> Исполнитель: root, последовательно, по `superpowers:executing-plans`.
> **APPROVED 2026-10-30:** владелец разрешил исключение: «добавляй я разрешаю
> как исключение». Разовые systemd-запуски этого protocol разрешены;
> новые app services/таблицы/очереди и изменение методики не разрешались.

**Цель:** выполнить уже зарегистрированные 14 captures и 28 truth observations,
сохранить все receipts, затем seal inventories/selection и получить quality report.
**Основание:** [протокол и операционный чек-лист](B16-operations.md),
[методика B16](B16.md), ADR-049 §5. Методика и immutable protocol не меняются.

## Проверенные ограничения и выбор способа

- Production `worker-scheduler` выполняет только `schedule-ozon-sync`;
  универсального запуска команд у него нет. Изменять его для исследования
  или вручную запускать синхронизацию кабинета не требуется.
- На сервере есть systemd, Python 3 и Docker Compose. Зарегистрированных
  forecast units/timers нет. Pinned producer digest по-прежнему доступен.
- `docs/patterns.md` требует версионируемую контейнерную топологию для обычных
  периодических задач. Host cron не используется. Предлагается отдельное
  узкое исключение для конечного исследования, с unit template в Git:
  systemd запускает существующий PHP CLI в pinned одноразовом контейнере.
- Новых таблиц, transport/message routing, сервисов приложения, пакетов,
  marketplace requests и новых прогнозных алгоритмов не добавляется.
  Фоновая инфраструктура вне этого protocol не создаётся.
- Capture/truth IDs, окна, source commit, epoch, параметры и quality thresholds
  берутся из sealed protocol. Clock и прочитанные исходы не подменяются.
- B16 закрывается только по настоящему `QUALITY_PASSED` и полной приёмке.
  Установленный timer или синтетическая проверка этого не заменяют.

## План исполнения

1. Реализовать узкий операторский runner: читать защищённый protocol manifest,
   проверить его immutable ref/hash и pinned source/epoch; вызывать только
   существующие capture/truth/quality CLI, сохранять приватные stdout/stderr,
   exit code, actual UTC, refs/hashes. Не исполнять произвольный shell из JSON.
2. Задать конечные calendar triggers из registered slots и двух cutoff:
   seal capture inventory + selection после закрытия capture window;
   seal truth inventory + evaluate после truth cutoff. Пропуски и неуспехи
   сохранять; запуск после пропуска не выдавать за прежний прогноз.
3. Проверить без production writes: будущее задание не выполняется;
   tampered protocol/чужой scope не принимается; concurrent invocation
   сериализуется; lost response/retry сохраняет UUID и verified winner;
   missing input блокирует его truth; gate не меняется; secrets не печатаются.
4. Выполнить два реальных Codex CLI review с точным diff/context, разобрать
   замечания, повторить затронутые проверки. Новые операционные файлы описать
   в `ops/README.md`, `docs/structure.md`, `docs/operations-checklist.md`.
5. После green CI и merge установить проверенный runner/units через
   `conwix-prod`; private manifest держать отдельно от Git. Проверить unit
   syntax, exact calendar, права файлов, pinned digest/source и dry-run:
   сейчас eligible jobs=0, никакие будущие observations не создаются.
6. Сохранить фактические installed/enabled/next-trigger receipts и rollback:
   отключить только собственный timer, сохранив protocol/history/receipts.
   В январе и мае задачи выполняются зарегистрированным механизмом;
   итог report не открывает numerical gate и не закрывает B17/B18 автоматически.

## Контроль изменений

Файлы: `bin/net-forecast-quality.py`, `api/bin/net-forecast-quality-bridge.php`,
`bin/tests/net_forecast_quality_test.py`, `bin/tests/net-forecast-quality.test.sh`,
`api/tests/Unit/Ingestion/NetQualityOperatorRefTest.php`,
`ops/systemd/conwix-net-forecast-quality@.service` и `.timer`, перечисленные
`ops/systemd/net-forecast-quality.compose.yml` (private daemon logging),
выше операционные документы. Host Python вызывает только pinned PHP bridge,
который проверяет sealed protocol перед штатными Application/CLI операциями.
Повторные попытки captures/truth допускаются 15 минут после slot; затем только
recovery уже опубликованного UUID, без создания запоздалого input/truth.
Контрольные стадии начинаются в cutoff+1/2/3 минуты; их ошибки сохраняются и
блокируют результат. Persistent timer после downtime фиксирует пропуски.
SIGTERM/ExecStopPost завершают собственный контейнер по marker и protocol label;
при недоступном Docker cleanup не скрывает ошибку и не удаляет marker.
Private identifiers, request payloads и receipts остаются вне Git/review package.
Чужие untracked B17/B18/security документы не изменять.

Текущий независимый preflight: проверка штатного training reader на
закреплённом producer; результаты в private `var/b16-readiness-20261030/`.
Даже достаточное обучение сегодня не создаёт будущий holdout или зрелый truth.
