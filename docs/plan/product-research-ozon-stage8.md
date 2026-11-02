# product-research-ozon — этап 8: завершение разработки

Дата: 02.11.2026. Статус: **завершён как разработка**.
Основание: решение владельца «Закрыть этап 8 как разработку», ADR-050.

## Цель и границы

Завершить код, офлайн-приёмку и подготовку к эксплуатации. Код Symfony выложен
за закрытым флагом, runtime image Python опубликован. Включение Python,
эксплуатационная готовность production и live-приёмка выделены в
[этап 9](product-research-ozon-stage9.md). Они не объявлены выполненными.
Архитектура, контракты и требования ADR-050 перед включением сохраняются.

## 8.1. Допуск компаний и конфигурация

Цель: случайное включение общего флага не открывает research всем компаниям.

Задачи выполнены: единый allowlist Symfony для availability/data routes,
независимые auth/membership и Python allowlist; opt-in production topology
с выключенным профилем; runtime image с проверкой source/version и наличия CLI
проверки ревизии. Автоматического применения миграций нет.

- [x] A01: global false, empty allowlist, foreign company закрыты; разрешённый
  pilot доступен в тестовом контуре при true флаге.
- [x] A02: невалидные ID/config не дают unrestricted mode; ошибки не раскрывают секреты.
- [x] A03: auth/directional tokens проверены тестами; публичный internal route закрыт.
- [x] A04: dry-run topology и disabled deploy не требуют настоящих MPStats/S3 секретов.

## 8.2. Инструменты восстановления и диагностики

Цель: проверить код чтения технического состояния и связи DB → неизменяемый raw.

Задачи выполнены: bounded company/source диагностика последних <=200 jobs,
проверка manifest/pages/hash, runbook backup/rollback и настоящий local
pg_dump/pg_restore drill. Автоматическое удаление сырья не добавлялось.

- [x] O01: диагностика ограничена company/source и sample; показывает cache/errors/late.
- [x] O02: dump → isolated restore → exact raw read + tenant denial подтверждены локально.

Это не подтверждение production backup/restore обеих БД, retention или доставки
оповещений. Их операционная проверка включена в этап 9.

## 8.3. Офлайн-приёмка и ресурсы

Цель: проверить обработку сохранённых эталонов без изменения ожидаемых значений.

Задачи выполнены: ручной/API CSV февраля 2025 сверен офлайн по 62148 SKU,
1080978 продажам и 596919032 RUB; январские 102 JSON страницы проведены через
collector/DB/S3, bytes/hash/counts/суммы проверены. Измерена постраничная обработка:
Python heap peak 8966590 bytes при 133878270 bytes raw; collection занял 32 s локально.
Это не RSS, не latency MPStats и не production-квота/максимальная категория.

- [x] D01: офлайн эталоны воспроизводимы; расхождения полей описаны без округления.
- [x] D02: месяц обрабатывается постранично, ресурсы fixture-набора измерены.

## 8.4. Проверки и поставка разработки

- [x] G01: PHP/Python/ops, contract/audit/E2E и обязательный CI кода зелёные.
- [x] G02: fresh local rebuild схем, PHP/Python/Go и межъязыковой smoke пройдены.
- [x] G03: независимые новые CLI5A/5B завершены; полные ответы и hashes сохранены.
- [x] G04: код выложен; disabled production smoke подтверждён.

Код: [PR #317](https://github.com/pavelsur07/app-service-analytics/pull/317),
merge `48fcb8dee5056e07aefe6797c1c341e715942618`.
[Полный CI 41409789938](https://github.com/pavelsur07/app-service-analytics/actions/runs/41409789938),
[master deploy 41411507612](https://github.com/pavelsur07/app-service-analytics/actions/runs/41411507612)
и последующая документальная поставка #318 подтверждены.
[Отчёт](product-research-ozon-stage8-report.md),
[неизменённые заключения независимого ревью](product-research-ozon-stage8-review.md).

## Перенос оставшихся условий без отметки PASS

| Исходный критерий этапа 8 | Новый критерий этапа 9 | Состояние |
|---|---|---|
| O03: production rollback/retention без потери DB/raw | P03 | Не выполнен, перенесён |
| O04: предупреждения и подтверждённая доставка | P04 | Не выполнен, перенесён |
| D03: текущий тариф/RPM/API объём и budgets | P01 | Не выполнен, перенесён |
| D04: новая операторская сверка MPStats/CSV | P05 | Не выполнен, перенесён |
| G05: production pilot start → ready → cache и выключение | P06 | Не выполнен, перенесён |

Реквизиты, собственные DB/S3 права и включение standalone stack с release/migration
гейтами также входят в этап 9 (P01/P02). Пилот подтверждён и сохранён только в
защищённой серверной конфигурации. Общий флаг false; Python stack не запущен.
Закрытие разработки не разрешает обход эксплуатационных условий и не означает
готовность live-выпуска. Аналитика ниши по-прежнему находится за границами MVP.
