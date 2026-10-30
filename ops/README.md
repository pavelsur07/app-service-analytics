# Эксплуатационные конфигурации Conwix

`ops/` — общий каталог декларативных конфигураций внешних ресурсов продукта.
В корне монорепозитория он находится рядом с остальными эксплуатационными
каталогами; конкретный файл сохраняет связь с ответственным модулем или сервисом.
Файлы группируются по ресурсу: используются `storage/` и `systemd/`.

Правила размещения — [CLAUDE.md → «Эксплуатационные конфигурации»](../CLAUDE.md#эксплуатационные-конфигурации).
Карта репозитория — [docs/structure.md](../docs/structure.md).

## Текущая конфигурация

| Файл | Ответственный модуль | Назначение |
|---|---|---|
| [storage/net-forecast-lifecycle.json](storage/net-forecast-lifecycle.json) | Ingestion, приватные артефакты net-forecast (ADR-049) | S3 lifecycle для префикса `conwix/monolith/net-forecast/v1/`: удаление noncurrent-версий старше 90 дней; current-версии сохраняются бессрочно |

Правило распространяется на неактуальные версии объектов. Оно сохраняет
уникальные current-объекты, включая неопубликованные попытки; очистка таких
объектов остаётся отдельной эксплуатационной задачей.

## Проверка, применение и откат

Команды и подтверждённые результаты находятся в
[docs/operations-checklist.md → «Хранилище сырья (ADR-024)»](../docs/operations-checklist.md#хранилище-сырья-adr-024).
Основание политики — [ADR-049](../docs/adr/0049-net-sales-forecast-observations-and-horizon.md).

1. До применения прочитать и сохранить текущую конфигурацию ресурса.
   Для этого lifecycle-правила проверить включённое версионирование S3.
2. Подготовить итоговый набор правил с сохранением существующих:
   `put-bucket-lifecycle-configuration` заменяет конфигурацию бакета целиком.
3. Применить конфигурацию в рамках действующих разрешений проекта и перечитать
   её из S3: проверить prefix, Enabled, 90 дней и отсутствие current expiration.
4. При откате восстановить сохранённый набор правил и проверить чтением.
   Откат политики не возвращает уже удалённые версии объектов; их восстановление
   возможно только из доступной резервной копии.

Сам JSON не подключён к автоматическому применению при запуске приложения
или выкладке. Изменение файла и его применение к ресурсу фиксируются отдельно.

## Разовые запуски B16 — systemd

`systemd/conwix-net-forecast-quality@.service` и `.timer` — шаблоны конечного
исследования B16, владелец root, предметная ответственность Ingestion.
Это [разрешённое владельцем исключение](../docs/plan/sales-planning-tasks/B16-schedule.md)
из operator-only B16 и обычного контейнерного расписания; host cron не вводится.
Python runner запускает существующий PHP CLI в preregistered image по digest.
`systemd/net-forecast-quality.compose.yml` отключает daemon logging только у
operator one-off, сбрасывая унаследованные journald options (`!override`);
штатный api/worker logging не меняется. Bridge
`api/bin/net-forecast-quality-bridge.php` монтируется read-only и не
меняет код модели внутри image. Он проверяет S3 protocol/hash, source/epoch,
company/account, registered IDs и фактическое UTC перед операциями.

Timer получает только конечные `OnCalendar` строки через per-instance drop-in,
сгенерированный `bin/net-forecast-quality.py calendar <private automation.json>`.
Manifest и append-only attempt/receipt файлы лежат вне Git, каталог 0700,
файлы 0600. SIGTERM и ExecStopPost останавливают собственный активный контейнер
по marker/name + protocol label; при ошибке marker сохраняется. State привязан
к SHA256 всего manifest; изменение manifest после
первого выполненного задания блокируется. Численный gate не изменяется.

Проверки, установка, контроль и откат —
[операционный checklist](../docs/operations-checklist.md#b16--разовые-запуски-исследования).
Проверки review/CI обязательны до установки. Для rollback отключить только
собственный timer и остановить соответствующий oneshot; сохранить manifest,
receipts, S3 objects и DB anchors. Удаление evidence откатом не является.
После завершения исследование не создаёт новых заданий автоматически.
