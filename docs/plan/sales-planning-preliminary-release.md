# Предварительный выпуск дневного планирования продаж

Решение владельца 2026-10-30/31: выпустить модуль без ожидания исследования
точности до мая 2027; модуль дорабатывается и накапливает данные.

## Объём и условия

A01–A50/B01–B15 приняты. B16 как исследование переносится в
[issue #298](https://github.com/pavelsur07/app-service-analytics/issues/298); не отмечается QUALITY_PASSED.
B17 заменяется технической приёмкой текущего итогового изменения:
права/изоляция, точные деньги/null, UI, действующие тесты, две независимые
Codex CLI роли и обязательный CI. B18 — предварительный production выпуск,
без заявления доказанной точности. Неизменённые принятые этапы имеют свои receipts.

Этот документ заменяет прежние release gates B16–B18 в общем плане и ADR-049
в рамках явно принятого владельцем решения. Чужие draft B17.md/B18.md не
редактируются и не включаются в пакет/коммит. Исследование не входит в
оставшийся объём выпуска и не получает фиктивный статус завершённого.

## Исполнение

1. Подключить существующий default-empty publication env в общий PHP compose.
   Разрешение — только точное совпадение с версией выпущенной модели.
2. UI для release_approved: «Предварительный прогноз. Точность ещё не проверена».
   assessed обозначает расчёт; недостающая история/выборка даёт null с причиной.
   Не менять H60/L7, минимумы выборки или деньги ради появления числа.
3. Проверить seller typecheck/lint/knip/tests/build, backend/functional сценарии
   включённой и закрытой публикации, compose default/unset/mismatch/exact.
4. Две независимые Codex CLI роли финального пакета, разбор замечаний, зелёный
   CI, PR/merge и штатная выкладка. Claude снят владельцем для текущей фичи.
5. Перед включением проверить полный HTTP путь на допустимом реальном scope
   с включённой моделью: <3s. Исторический синтетический Action16–20s не
   считается PASS; максимальная нагрузка остаётся явно указанным ограничением.
6. Изменить только NET_FORECAST_PUBLISHED_MODEL_VERSION в /opt/conwix/.env,
   сохранив владельца/права/остальные значения; атомарная запись через tempfile.
   Сначала закрытая выкладка. Затем установить exact MODEL_VERSION и recreate
   семи PHP сервисов из того же image; проверить effective env, epoch, health,
   seller/admin pings, UI asset и readonly scoped GET.
7. Откат: вернуть пустой publication value и recreate тех же PHP, проверить
   nullable ответ. БД/epoch/артефакты не удалять. Старый registered timer отключён
   по решению владельца: inactive/disabled 2026-10-31; протокол и artifacts сохранены.
   Возврат к исследованию и новый календарь — в issue #298 после стабилизации.

## Итог

Предварительный выпуск выполнен 2026-10-31: [PR299](https://github.com/pavelsur07/app-service-analytics/pull/299),
source `0b73008f09d73fe632290c739d5bd09cec919a66`, merge
`ad8ae6e11619f278446fab22d8e2c443df090e39`. Source CI41170641206 и
master CI/deploy41171412965 SUCCESS; production `727-ad8ae6e`.

Две независимые Codex CLI роли gpt-6.1-sol для каждого пакета:17+9=26finalpaths,
final COMPLETE0/0; полные ответы и hashes в
[review receipt](https://github.com/pavelsur07/app-service-analytics/pull/299#issuecomment-6539390247).
Принято0; отклонено1 предположение о callable minimatch в используемом lint rule:
actual source и negative import-boundary smoke подтверждают new Minimatch.
Последующие роли новых уникальных принятых0/0. Claude снят владельцем для фичи.

Локально PASS: unit783, integration872(4workers), functional383, targeted48;
static, seller/admin/extension types/lint/knip/tests/build (594/38/71),
APItypes и полный audit, E2E7 и extension browser storage, compose4modes×7PHP.
Ранний integration2 не прошёл resource caps; лимиты не ослаблены, repeat4 PASS.
Audit blocker GHSA-g3hv-75wg-m249 устранён обновлением существующего transitive
minimatch11.0.3, обязательные проверки сохранены.

Публикация `observed_retention_h60_v1` включена во всех7PHP, исходный epoch
hash сохранён. Все12сервисов running/healthy по предусмотренным healthchecks;
seller/admin versions и реально обслуживаемый UI marker проверены.
Реальные HTTP day/period4×200:2628.52/2257.93/1790.64/1798.26ms (<3s).
Scope: существующий разрешённый план,1SKU; это не доказательство performance
на максимальной нагрузке или точности. На текущем scope numeric components0,
`source_history_unavailable`: отсутствие пригодных данных не заменено нулём.
Временная проверочная сессия удалена; боевые планы/факты не менялись.

B17 техническая приёмка и B18 предварительный выпуск выполнены в пересмотренном
объёме. B16 остаётся отдельным исследованием issue298, quality NOT_MEASURED;
старый registered timer inactive/disabled, artifacts сохранены. Дата готовности
анализа точности не обещается.
