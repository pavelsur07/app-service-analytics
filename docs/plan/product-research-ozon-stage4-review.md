# product-research-ozon — независимое ревью этапа 4

Claude CLI отсутствует. Применена замена владельца: две новые независимые сессии
Codex CLI 0.166.0, gpt-6.1-sol/high (фактические stderr), read-only, без контекста реализации. Самопроверка/субагенты не заменяют ревью.

## Первый пакет

SHA256: `5b7573dbd34385467ac9e4899305a561fb7b20a493d7d8e27188b9a54cbbae9d`.
Head `b62d75e9a2a19e106bf998325ec04775df2533fa`; 23 файла задачи.
Оба заключения complete. 5A — 2 уникальных принятых; 5B дополнительно — 2.
Пакет отправлен до завершения rebuild: это нарушение предусловия признано,
повторный пакет будет передан только после зелёного полного результата.

### 5A

Артефакт: `var/review/runs/20261101T145558.650029Z-5b7573dbd343-codex-qin5pjk2/review.json`.

```json
{
  "status": "complete",
  "package_sha256": "5b7573dbd34385467ac9e4899305a561fb7b20a493d7d8e27188b9a54cbbae9d",
  "summary": "Пакет прочитан полностью. Проверены company/source scope, ключи S3, неизменяемые bytes, Decimal, техническая схема, fencing, общий gate и границы этапа. Найдены два нарушения записанных требований. Тесты и сборки не запускались; сеть не использовалась.",
  "findings": [
    {
      "kind": "нарушение",
      "location": "services/product-research-ozon/src/product_research_ozon/collection/service.py:141",
      "detail": "MonthCollector вызывает emit без уровня, поэтому используется logging.INFO даже при outcome=deferred для rate_limited/report_pending. Это расходится с services/AGENTS.md §8: ожидаемая пауза лимита должна записываться на уровне warning.",
      "quote": "            emit(\n                self._logger,",
      "failure": "MPStats возвращает 429 или общий gate отклоняет запрос → ожидание сохраняется, но событие получает INFO → при production-пороге warning пауза лимита отсутствует в журнале вопреки обязательному правилу."
    },
    {
      "kind": "нарушение",
      "location": "docs/plan/product-research-ozon-stage4-report.md:73",
      "detail": "Пакет передан на ревью без завершённого зелёного db-rebuild-check. CLAUDE.md, раздел «Миграции и изменения схемы», прямо требует его зелёный результат к моменту ревью. Отдельный baseline-прогон не заменяет полный rebuild; M01 пока не подтверждён.",
      "quote": "причина общего прогона не объявляется доказанной. Выполняется полный повтор\nс TEST_WORKERS=4 (rebuild-final.log), без изменения лимитов/утверждений.",
      "failure": "В изменении присутствует новая миграция, предыдущий полный rebuild завершился отказами, повтор ещё выполняется → обязательное предусловие ревью схемы не выполнено и проверка окончательной схемы с чистой базы не подтверждена."
    }
  ],
  "rule_gaps": [],
  "nitpicks": []
}
```

### 5B

Артефакт: `var/review/runs/20261101T145558.620557Z-5b7573dbd343-defects-jgq9puqq/review.json`.

```json
{
  "status": "complete",
  "package_sha256": "5b7573dbd34385467ac9e4899305a561fb7b20a493d7d8e27188b9a54cbbae9d",
  "summary": "Пакет прочитан полностью. Проверены полнота страниц, сохранение bytes, Decimal, company/source scope, аренды, generation fencing, provider gate и публикация ready. Найдены два дефекта. Тесты и сборки не запускались; успешный полный rebuild и итоговый CI приложенным отчётом пока не подтверждены.",
  "findings": [
    {
      "kind": "дефект",
      "location": "services/product-research-ozon/src/product_research_ozon/domain/month.py:202",
      "detail": "Decimal может выбросить decimal.InvalidOperation при разборе JSON-числа с чрезмерно большим показателем степени. Это исключение не перехватывается здесь и не обрабатывается MonthCollector.step, который ожидает CollectionFailure. Нужно преобразовывать такой отказ в безопасный invalid_source.",
      "quote": "except ValueError, TypeError, UnicodeError, RecursionError:",
      "failure": "Ответ с корректными counts/id и полем revenue:1e999999999999999999999 → необработанный decimal.InvalidOperation вместо CollectionFailure; снимок остаётся collecting с занятой арендой, ошибка источника не фиксируется."
    },
    {
      "kind": "дефект",
      "location": "services/product-research-ozon/src/product_research_ozon/snapshots/manifest.py:60",
      "detail": "Манифест сериализует completed_at непосредственно из UTC-часов, а read_manifest пересобирает его из timestamptz PostgreSQL. Psycopg возвращает timestamptz в часовом поясе SQL-сессии; SnapshotStore не фиксирует UTC и не нормализует результат. Поэтому одинаковый момент может получить другое строковое представление и сделать исправный готовый манифест нечитаемым. Нужна единая нормализация времени при сериализации и чтении.",
      "quote": "\"completed_at\": completed_at.isoformat(),",
      "failure": "PostgreSQL работает с TimeZone=Europe/Moscow, стандартные часы коллектора возвращают UTC → манифест сохраняет completed_at с +00:00, чтение из БД возвращает +03:00 → read_manifest стабильно выдаёт corrupt_raw для успешно опубликованного снимка."
    }
  ],
  "rule_gaps": [],
  "nitpicks": []
}
```

## Разбор

1. Принято (5A): ожидание лимита/202 теперь WARNING; ошибка — ERROR, обычный
   успешный шаг — INFO. Отдельные tests проверяют обе severity и отсутствие токена.
2. Принято (5A): полный rebuild должен быть зелёным к моменту ревью схемы.
   Первый пакет передан раньше. Повтор с четырьмя процессами прошёл полностью:
   807 unit /9134, 889 integration /23333, 419 functional /4129, Go и 115 Python.
   После исправлений полный прогон также успешен: 807/9134, 889/23326,
   419/4129, Go и 119 Python; rebuild-reviewed.log. Новый пакет — после успеха.
3. Принято (5B): JSON с чрезмерным exponent вызывал decimal.InvalidOperation.
   DecimalException преобразуется в безопасный invalid_source, как прочие
   malformed source ответы; число не округляется и исходные bytes не меняются.
4. Принято (5B): ready manifest был зависим от SQL TimeZone. Технические события
   нормализуются в aware UTC при маппинге, SQL-session закреплена UTC, serialization
   манифеста также нормализует UTC. Настоящий тест принудительно меняет session
   на Europe/Moscow, публикует и читает manifest — без corrupt_raw.

Четыре новых regression cases (Decimal, WARNING, ERROR, PostgreSQL TimeZone)
сначала упали на прежнем коде, затем прошли на исправленном.
Логи: var/product-research-ozon-stage4/review-red.log и review-green.log.
Отклонённых замечаний нет. Общие дефекты PHP-набора не исправлялись попутно;
отдельный baseline и полный успешный повтор отражены в отчёте этапа.

## Повторный пакет — итоговое ревью

SHA256: `348afe32cdcdbcedd36a0cbe33f8949a50d19eddefdb834bc1f8fd9e9c74451d`.
Head `de084c8d6e9dd42c28da7cdf621ef6cbeb0ad162`; 24 файла задачи.
Подготовлен после успешного fresh rebuild окончательного кода и всех локальных
гейтов. Предыдущие заключения и явный разбор включены в пакет. Во время ревью
код не менялся. Оба прохода complete, exit 0, новых замечаний/пробелов/nitpicks нет.

### 5A

Артефакт: `var/review/runs/20261101T151005.165713Z-348afe32cdcd-codex-ox0_uk80/review.json`.

```json
{
  "status": "complete",
  "package_sha256": "348afe32cdcdbcedd36a0cbe33f8949a50d19eddefdb834bc1f8fd9e9c74451d",
  "summary": "Пакет прочитан полностью. Проверены соответствие правилам и ADR-050/044/043, company/source scope, неизменяемость raw bytes, полнота, бюджеты, CAS/fencing, безопасные отказы, UTC и границы этапа 4. Принятые замечания исправлены: severity задана явно, DecimalException преобразуется в invalid_source, время снимков и манифеста нормализуется в UTC; успешный полный rebuild окончательного кода зафиксирован в отчёте. Нарушений записанных требований не обнаружено. Этапы 5–8 не расширены; CI нового head остаётся предусловием merge. Тесты и сборки не запускались, сеть не использовалась.",
  "findings": [],
  "rule_gaps": [],
  "nitpicks": []
}
```

### 5B

Артефакт: `var/review/runs/20261101T151005.196901Z-348afe32cdcd-defects-17ogthug/review.json`.

```json
{
  "status": "complete",
  "package_sha256": "348afe32cdcdbcedd36a0cbe33f8949a50d19eddefdb834bc1f8fd9e9c74451d",
  "summary": "Пакет прочитан полностью. Матрица U01–U08, C01, I01–I10, R01–R03, M01 сопоставлена с реализацией и тестами. Проверены неизменяемость raw bytes, полнота, company/source scope, CAS и fencing, UTC, безопасные отказы и публикация ready. Четыре принятых замечания учтены: severity исправлена, DecimalException обрабатывается, время нормализуется в UTC, успешный окончательный rebuild отражён в отчёте. Расширения этапов 5–8 не обнаружено. Новых подтверждённых дефектов не найдено. Тесты и сборки не запускались, сеть не использовалась; результаты прогонов оценены по предоставленным сведениям. CI нового head остаётся предусловием merge.",
  "findings": [],
  "rule_gaps": [],
  "nitpicks": []
}
```

## Итог

Четыре новые независимые сессии Codex CLI 0.166.0, gpt-6.1-sol/high;
версия/модель/effort взяты из stderr каждого фактического запуска.
Уникальные принятые: первый 5A — 2; первый 5B дополнительно — 2;
повторный 5A — 0; повторный 5B дополнительно — 0. Всего четыре: три исправления
кода и одно процессное. Все устранены и перепроверены; отклонённых нет.
Claude заменён по причине отсутствующего CLI согласно решению владельца.
Самопроверка и субагенты заменой не являлись.

Ревьюеры тесты и сборки не запускали; их заключения не подменяют результаты
автора и CI. Фактические финальные проверки и доставка — в
[отчёте этапа](product-research-ozon-stage4-report.md).
