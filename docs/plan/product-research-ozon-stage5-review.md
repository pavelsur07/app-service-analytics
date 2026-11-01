# product-research-ozon — независимое ревью этапа 5

Claude CLI отсутствует. По AGENTS.md и решению владельца применена замена:
восемь новых независимых сессий Codex CLI 0.166.0 в четырёх раундах 5A/5B,
без контекста реализации. CLI stderr подтверждает gpt-6.1-sol/high,
sandbox read-only, approval on-request, auto_review только для точного чтения.
Самопроверка и субагенты заменой не использовались. Ревьюеры тесты/сеть не запускали.

Весь итоговый diff задачи: 69 файлов (первые три раунда — 68); чужие изменения, temp, секреты исключены.
Финальный reviewed HEAD a96e3322ffe211f5ad4bf4d1883b185124696cb5,
база ba062410cd318348ba3f7608e10ec69a35e0f69a.
Все раунды начаты после успешного полного fresh db-rebuild-check своего снимка.

| Раунд | 5A: новых уникальных принятых | 5B: дополнительно сверх предыдущих | Итог |
|---|---:|---:|---|
| 1 | 2 | 3 | Все пять исправлены, повторные проверки |
| 2 | 0 | 2 | Оба исправлены, повторные проверки |
| 3 | 0 | 2 | Оба исправлены, повторные проверки |
| 4 | 0 | 0 | Complete, новых findings/rule gaps/nitpicks нет |

Приняты 9 уникальных замечаний, отклонённых и пробелов правил нет:

1. Company-first индекс автора запуска добавлен в ORM и единственную миграцию Symfony.
2. State/apply/reject_run_credentials явно принимают company_id и сверяют его с lease.
3. Retry cursor восстанавливает бюджет новой страницы после успешного append без job ack.
4. Auth pause завершает ожидающий snapshot до collector, обходя его cooldown.
5. Некорректная категория (space/NUL) даёт contract_violation/422 вместо 500.
6. Durable outcome revision / acknowledgement восстанавливают сохранённый отказ
   один раз до I/O. Неизвестный исход учитывается консервативно; unacked202 не
   расходует network budget. Пятый запрос после четвёртого сохранённого отказа запрещён.
7. Все apply решения ограждены активным поколением, включая terminal error.
8. Успешная пустая страница распознаётся по committed page count и восстанавливается после crash.
9. Apply атомарно подтверждает retry_page вместе с расходом budget, без повторного сброса при ошибке manifest.

Дополнительно исправлены smoke bootstrap чистого CI (mkdir var) и старый flaky CatalogCursor test: tampered suffix теперь всегда отличается; production decoder не менялся.
Два предупреждения первого 5B о quote без точного якоря сопоставлены с полным
контекстом пакета; оба дефекта воспроизведены Red-тестами и исправлены.
В CHECKS второго пакета частные counters assertions/Go остались от предыдущего
успешного прогона; точные финальные значения находятся в отчёте приёмки и
rebuild-final4.log. Статусы PASS подтверждены, итог кода пересмотрен целиком.

## Полные заключения

### Раунд 1, 5A

Сессия: `20261101T170849.573229Z-5d0ce616f2c9-codex-9d5hi6gl`. SHA-256 полного review.json: `626eac114225b428c29a95c6680a1963ac349fe0a73e9369e146641ec769f8d8`.

```json
{
  "status": "complete",
  "package_sha256": "5d0ce616f2c9ae65c2423977e90e2731cd017fffd0a20fabd4c70762e9781e46",
  "summary": "Пакет полностью прочитан и сопоставлен с приложенными правилами и ADR в роли 5A. Найдены два нарушения записанных требований. Тесты, сборки и сеть не запускались; заявленные результаты проверок не перепроверялись.",
  "findings": [
    {
      "kind": "нарушение",
      "location": "api/src/ProductResearchOzon/Domain/ResearchRun.php:43",
      "detail": "Новое поле createdByUserId ссылается на пользователя Identity, но для него нет индекса ни в ORM mapping, ни в миграции Version20261101164957. CLAUDE.md §6 требует индекс для каждого нового поля-ссылки на сущность другого модуля в той же миграции. Аналогичный индекс автора у ResearchProject уже предусмотрен.",
      "quote": "    private Uuid $createdByUserId;",
      "failure": "Применение миграции создаёт ссылку запуска на пользователя другого модуля без обязательного индекса; итоговая схема нарушает CLAUDE.md §6."
    },
    {
      "kind": "нарушение",
      "location": "services/product-research-ozon/src/product_research_ozon/infrastructure/job_store.py:284",
      "detail": "Company-scoped методы state, apply и reject_run_credentials принимают первым аргументом JobLease вместо отдельного company_id. То же закреплено в RunRepository. services/python.md прямо требует: «Company-scoped методы принимают company_id первым параметром». Наличие company_id внутри lease обеспечивает явный контекст, но не выполняет записанное требование к сигнатуре.",
      "quote": "    def state(self, lease: JobLease) -> Snapshot:",
      "failure": "Чтение или изменение задания конкретной компании вызывается без обязательного первого параметра company_id; порт и реализация допускают интерфейс, запрещённый правилами Python-сервисов."
    }
  ],
  "rule_gaps": [],
  "nitpicks": []
}
```

### Раунд 1, 5B

Сессия: `20261101T170849.590587Z-5d0ce616f2c9-defects-vg6xvdim`. SHA-256 полного review.json: `c1ef3258b4b782a3899375a7b9fea21022a9541cc7cf0f1bd3a30d6856159b6c`.

```json
{
  "status": "complete",
  "package_sha256": "5d0ce616f2c9ae65c2423977e90e2731cd017fffd0a20fabd4c70762e9781e46",
  "summary": "Прочитан весь пакет: изменения 68 файлов, окружающий код, контракты и критерии приёмки. Найдены три дефекта в восстановлении retry budget, обработке auth pause и классификации некорректного ввода. Тесты и сборки не запускались; сеть не использовалась.",
  "findings": [
    {
      "kind": "дефект",
      "location": "services/product-research-ozon/src/product_research_ozon/domain/jobs.py:220",
      "detail": "Сброс network_retries после успешной страницы теряется при crash между snapshot append и JobStore.apply. При восстановлении claim берёт сохранённый network_retries из задания, но lease.cursor уже устанавливает из продвинувшегося snapshot. Поэтому политика больше не распознаёт успешно закоммиченную страницу и переносит исчерпанный бюджет на следующую страницу. Это нарушает U02 и восстановление I04/I05.",
      "quote": "if snapshot.cursor > lease.cursor:\n                network = 0",
      "failure": "Страница 0 трижды падает, четвёртая попытка успешно сохраняет первые 500 строк, затем процесс завершается до JobStore.apply. После истечения lease новый worker получает network_retries=3 и lease.cursor=500. Первая временная ошибка страницы 500 немедленно завершает месяц retry_exhausted вместо предоставления трёх повторов нового шага."
    },
    {
      "kind": "дефект",
      "location": "services/product-research-ozon/src/product_research_ozon/jobs/service.py:133",
      "detail": "Paused gate делает задания доступными независимо от next_attempt_at, однако worker обнаруживает паузу только через выполнение collector. Если у snapshot ещё действует собственный cooldown, SnapshotStore.claim возвращает false и collector возвращает collecting без authorization_failed. Задание снова ставится в queued, а paused gate немедленно выбирает его повторно. Ожидающий запуск не завершается credentials_rejected и создаёт непрерывный цикл SQL до окончания snapshot cooldown. Проверка I06 для уже ожидающих запусков этим не выполнена.",
      "quote": "if observed.status != \"ready\" and self._clock() < lease.deadline:",
      "failure": "Worker A выполняет медленный запрос. Worker B другого запуска получает rate_limited из-за занятого provider lease и сохраняет snapshot.next_attempt_at до окончания 60-секундной аренды. Запрос A отвечает 401 и ставит auth pause. Запуск B постоянно захватывается на paused gate, но его snapshot не допускает claim до сохранённого срока; worker повторяет SQL без idle wait вместо немедленного завершения запуска."
    },
    {
      "kind": "дефект",
      "location": "services/product-research-ozon/src/product_research_ozon/jobs/service.py:79",
      "detail": "RunSpec.from_payload создаёт MonthParameters, который проверяет category_path через Category. Для пустого после trim пути или пути с NUL Category выбрасывает CatalogUnavailable, а здесь перехватываются только ValueError и TypeError. Такие значения проходят Pydantic-проверку длины и приводят к internal_error/500 вместо contract_violation/422.",
      "quote": "except ValueError, TypeError:\n            raise CollectionFailure(\"contract_violation\") from None",
      "failure": "Авторизованный POST /runs с корректными идентификаторами и месяцем, но category_path=\" \", проходит RunRequest и затем вызывает неперехваченный CatalogUnavailable → HTTP 500 вместо предусмотренного отказа валидации 422."
    }
  ],
  "rule_gaps": [],
  "nitpicks": []
}
```

### Раунд 2, 5A

Сессия: `20261101T172231.384452Z-c8b8cd33d04b-codex-249v8jwo`. SHA-256 полного review.json: `8cf29575bc67bff33ea1f4d70a72cec96826fc9b98fe561d404c91616e3ecfc1`.

```json
{
  "status": "complete",
  "package_sha256": "c8b8cd33d04b6fa3c4e8b95c2146365dc0d874bf244598cd92428bcd9ba1c1b5",
  "summary": "Полностью прочитаны пакет, актуальный diff и приложенный контекст. Проверены соответствие записанным правилам и ADR, изоляция компаний, ORM intent/outbox, миграции, границы PHP/Python, неизменность v1 и границы этапов. Все пять ранее принятых замечаний исправлены: индекс автора присутствует в mapping и миграции; company_id передаётся явно и сверяется с lease; retry_cursor восстанавливает бюджет следующей страницы; auth_paused обрабатывается до collector; CatalogUnavailable преобразуется в contract_violation. Новых нарушений записанных правил не выявлено. Тесты и сборки не запускались; результаты проверок и состояние CI независимо не подтверждались. Незавершённый обязательный CI остаётся препятствием для merge.",
  "findings": [],
  "rule_gaps": [],
  "nitpicks": []
}
```

### Раунд 2, 5B

Сессия: `20261101T172231.397325Z-c8b8cd33d04b-defects-mob9k76r`. SHA-256 полного review.json: `7db48087ec0f99d9fd8926a6a8c793a03fb2dc1cc64d17e3623d2ad5f5a28b98`.

```json
{
  "status": "complete",
  "package_sha256": "c8b8cd33d04b6fa3c4e8b95c2146365dc0d874bf244598cd92428bcd9ba1c1b5",
  "summary": "Прочитаны весь актуальный diff, приложенный контекст и матрица приёмки. Все пять заявленных исправлений присутствуют, включая регрессионные проверки. Найдены два дополнительных дефекта восстановления и fencing поколения. Тесты и сборки не запускались; результаты автора и незавершённый CI независимо не подтверждались.",
  "findings": [
    {
      "kind": "дефект",
      "location": "services/product-research-ozon/src/product_research_ozon/infrastructure/job_store.py:403",
      "detail": "Расход network budget фиксируется только в JobStore.apply, отдельной транзакцией после сохранения результата шага в snapshot. Если worker падает после четвёртого сетевого отказа и успешного SnapshotStore.release, но до apply, в задании остаётся network_retries=3. После истечения аренды claim не обнаруживает продвижения cursor и повторяет запрос ещё раз. Сохранённый source_unavailable у collecting snapshot при восстановлении не учитывается. Поэтому даже один crash позволяет превысить три повтора страницы; retry_cursor исправляет восстановление после успешного append, но не после сохранённого отказа.",
      "quote": "\"\"\"UPDATE research_job SET status=%s,next_attempt_at=%s,network_retries=%s,completeness_retries=%s,",
      "failure": "Страница уже получила три отказа → четвёртый запрос также получает 503 → ошибка snapshot сохранена → crash до apply → аренда истекла → выполняется пятый запрос, который может завершить месяц ready вместо retry_exhausted."
    },
    {
      "kind": "дефект",
      "location": "services/product-research-ozon/src/product_research_ozon/infrastructure/job_store.py:369",
      "detail": "JobStore.apply проверяет active_generation только для решения restart. Остальные решения старого worker принимаются после ротации, пока действует его job lease. Например, worker поколения 1 с исчерпанным network budget получает 503 от запроса, во время которого активировано поколение 2. SnapshotStore.release проверяет только job lease, а apply затем сохраняет terminal retry_exhausted без проверки поколения. Новый worker уже не подберёт это задание для предусмотренного restart, поскольку claim выбирает только queued/running.",
      "quote": "if decision.restart:",
      "failure": "Активный запрос поколения 1 с network_retries=3 → ротация на поколение 2 → ответ 503 → старый worker записывает job.status=error → worker поколения 2 не создаёт новый snapshot и не возобновляет месяц."
    }
  ],
  "rule_gaps": [],
  "nitpicks": []
}
```

### Раунд 3, 5A

Сессия: `20261101T173958.682910Z-ddf0ce8a59f2-codex-58k0x5_0`. SHA-256 полного review.json: `b05f123188bfc131d019d732c49b2598999e5ca526d48eccea923b0042a6719e`.

```json
{
  "status": "complete",
  "package_sha256": "ddf0ce8a59f28ef2d9c6e853cea8081966052d1b362d890f9b8477beaed0f3e9",
  "summary": "Прочитан весь приложенный пакет: diff 68 файлов, контекст, правила и ADR. Проверены ORM intent/outbox, company-first порты и индексы, изоляция, неизменность v1, границы этапов 6–8 и соответствие исправлений семи принятых замечаний записанным требованиям. Новых нарушений правил и ADR не выявлено. Консервативный учёт неизвестного исхода описан в плане и README. Тесты не запускались; заявленные PASS и текущий CI независимо не подтверждались. Merge требует зелёного обязательного CI.",
  "findings": [],
  "rule_gaps": [],
  "nitpicks": []
}
```

### Раунд 3, 5B

Сессия: `20261101T173958.682280Z-ddf0ce8a59f2-defects-cg6jpvgs`. SHA-256 полного review.json: `7c5887c6be10943f153d0dee0b033bd610d7c76f3ed515496088697a6c3ec304`.

```json
{
  "status": "complete",
  "package_sha256": "ddf0ce8a59f28ef2d9c6e853cea8081966052d1b362d890f9b8477beaed0f3e9",
  "summary": "Полный пакет прочитан, включая исправления семи замечаний, миграции, outbox, изоляцию, контракты и границы этапов. Найдены два оставшихся дефекта восстановления и учёта retry budget. Тесты и сборки не запускались.",
  "findings": [
    {
      "kind": "дефект",
      "location": "services/product-research-ozon/src/product_research_ozon/infrastructure/job_store.py:251",
      "detail": "Успешная пустая страница не продвигает cursor, поэтому после crash её commit ошибочно классифицируется как неизвестный отказ. После трёх сетевых отказов четвёртый запрос может успешно вернуть total=0, append сохранит total=0/pages=1/cursor=0, а процесс упадёт перед публикацией manifest. При восстановлении budget не сбрасывается и unknown_outcome приводит к terminal retry_exhausted вместо завершения готового пустого снимка. Для распознавания успеха требуется учитывать также commit пустой страницы, например продвижение pages.",
      "quote": "and snap.status == \"collecting\"\n                and snap.cursor == typed(row[4], int)",
      "failure": "network_retries=3 → успешный ответ total=0 → append commit → crash перед finish/apply → истечение lease → unknown_outcome=True → retry_exhausted при уже сохранённой успешной странице."
    },
    {
      "kind": "дефект",
      "location": "services/product-research-ozon/src/product_research_ozon/infrastructure/job_store.py:434",
      "detail": "apply подтверждает новый outcome и сохраняет рассчитанный network_retries, но оставляет retry_cursor на позиции до выполнения страницы. Если append прошёл успешно, а последующая запись manifest завершилась storage_unavailable, policy уже сбрасывает бюджет за продвижение страницы и сохраняет первую ошибку manifest. Следующий claim повторно обнаруживает то же продвижение и обнуляет уже сохранённый расход. При постоянном отказе manifest получается пять попыток вместо начальной и трёх повторов. Подтверждение решения должно атомарно сохранять соответствующий текущий retry_cursor.",
      "quote": "error_code=%s,row_count=%s,snapshot_ack_revision=%s,lease_id=NULL,lease_until=NULL WHERE company_id=%s AND job_id=%s",
      "failure": "append cursor 0→1 → первый отказ manifest → apply сохраняет network_retries=1 и retry_cursor=0 → следующий claim повторно сбрасывает network_retries=0 → terminal наступает только после пятого отказа manifest."
    }
  ],
  "rule_gaps": [],
  "nitpicks": []
}
```

### Раунд 4, 5A

Сессия: `20261101T174931.823752Z-8eb4e20094c1-codex-7vt_wa31`. SHA-256 полного review.json: `d0f65b064126cf99e5f2d7644420bb555c1dbaf2d3cbed55d4a9fc3a7256d70d`.

```json
{
  "status": "complete",
  "package_sha256": "8eb4e20094c1589cf99e361c0625cbd40c1156ad1ed4f63d298cf1a6790488cc",
  "summary": "Полностью прочитан diff 69 файлов и приложенный контекст; проверено соответствие записанным правилам и ADR-050/044/043. Сверены исправления девяти принятых замечаний, включая company-first сигнатуры, индекс автора, committed page count, атомарное сохранение retry_page/outcome ack/budget, восстановление до I/O, generation fence и paused gate. Новых нарушений не обнаружено. Границы этапов 6–8 и неизменность опубликованного v1 соблюдены; production decoder курсора не изменён. Тесты, сборки и сеть не запускались; текущий CI независимо не проверялся.",
  "findings": [],
  "rule_gaps": [],
  "nitpicks": []
}
```

### Раунд 4, 5B

Сессия: `20261101T174931.815306Z-8eb4e20094c1-defects-t9ajwn07`. SHA-256 полного review.json: `3ce2236e729e0b808887ae3dfde2da47e889204639e1a217365ffd30431e335a`.

```json
{
  "status": "complete",
  "package_sha256": "8eb4e20094c1589cf99e361c0625cbd40c1156ad1ed4f63d298cf1a6790488cc",
  "summary": "Прочитан полный diff 69 файлов и приложенный контекст. Проверены исправления всех 9 замечаний, восстановление retry budget по committed pages включая total=0, атомарность retry_page/outcome ack/budget, обработка сохранённого и неизвестного исхода до I/O, unacked 202, generation fencing и paused cooldown. Проверены Symfony outbox, идемпотентность, frozen months, изоляция компаний, неизменность v1 и границы этапов 6–8. Новых подтверждённых дефектов не обнаружено. Тесты и сборки не запускались; текущий CI независимо не проверялся.",
  "findings": [],
  "rule_gaps": [],
  "nitpicks": []
}
```

## Точный разбор автора

Первый пакет 5d0ce616f2c9ae65c2423977e90e2731cd017fffd0a20fabd4c70762e9781e46, роль 5A.
1 принято: для createdByUserId ResearchRun добавить company-first индекс автора в ORM и единственную итоговую миграцию задачи, проверить schema:validate/fresh rebuild.
2 принято: company-scoped методы RunRepository/JobStore state/apply/reject_run_credentials принимают явный company_id первым параметром. Добавить проверку совпадения с lease, обновить worker и интеграционные вызовы; неверный tenant отвергать до SQL.
Дополнительно по CI41329635806: bootstrap smoke-скрипта должен создавать var перед TemporaryDirectory. Ошибка воспроизведена чистым checkout CI; исправление проверяется отдельным smoke и повторным обязательным CI. Остальные пять job первого CI зелёные, merge не выполнялся.

Второй проход 5B того же пакета, три новых уникальных замечания сверх 5A:
3 принято: budget нового page должен восстанавливаться после append commit без job ack. В единственную Alembic-миграцию добавлен retry_cursor; claim атомарно сравнивает его с authoritative snapshot cursor и сбрасывает network budget при продвижении/новом поколении. Регрессионный тест сначала RED: 3 неудачи первой страницы → успешный append → crash → первая ошибка новой страницы; после исправления должен завершиться ready.
4 принято: paused provider должен завершать job даже при snapshot cooldown. JobLease несёт auth_paused из атомарного claim; worker вызывает guarded reject_run_credentials до collector, сохраняя ready. Регрессионный тест ожидающего snapshot с provider lease сначала RED.
5 принято: CatalogUnavailable из проверки category_path преобразуется в contract_violation на границе RunService.submit. HTTP cases space/NUL сначала RED (500), ожидается 422 без утечки input.
Все 5 замечаний приняты, отклонённых и пробелов правил нет. 5A:2 уникальных, 5B дополнительно:3. Два предупреждения «без якоря» проверены по приложенному полному контексту: условия worker и except находятся в jobs/service.py и есть в исходном пакете; дефекты воспроизведены.

Пакет c8b8cd33d04b6fa3c4e8b95c2146365dc0d874bf244598cd92428bcd9ba1c1b5: повторный 5A — 0 новых, повторный 5B — ещё 2 уникальных.
6 принято: crash после сохранённого failure release не должен давать пятый запрос. Snapshot outcome_revision и job snapshot_ack_revision обеспечивают replay сохранённого исхода один раз до I/O; неизвестное истёкшее исполнение консервативно учитывается как один временный отказ. Два основных cases 5B сначала RED; дополнительно проверяются неизвестный исход и unacked202 без расхода budget. Успех append по-прежнему сбрасывает новый page budget.
7 принято: все JobStore.apply решения, включая terminal error, ограждены текущим поколением; source gate проверяется и блокируется FOR SHARE до run/job mutation. Старый worker не закрывает job после rotation, новый создаёт новый snapshot. Regression сначала RED, затем ожидается GREEN с успешным поколением2.
Итого первых двух раундов: 7 уникальных принятых, отклонённых0; дополнительные проходы 5A=0/5B=2 сверх исходных 2+3. Пакет второго раунда в CHECKS содержал частные счётчики assertions/Go предыдущего успешного прогона; точные финальные числа берутся из итогового rebuild лога. Все заявленные PASS подтверждены.

Пакет ddf0ce8a59f28ef2d9c6e853cea8081966052d1b362d890f9b8477beaed0f3e9: третий 5A — 0 новых, третий 5B — ещё 2 уникальных.
8 принято: успешный total0 append учитывается по числу страниц, а не продвижению row cursor. Job retry_page и JobLease.pages / RetryPolicy используют committed page count; crash после пустого append с предыдущим исчерпанным budget восстанавливает ready без API. Regression сначала RED.
9 принято: apply атомарно подтверждает текущий retry_page вместе с outcome ack и network_retries; тот же append не сбрасывает второй раз уже потраченный budget manifest. Persistent manifest failure regression сначала RED; ожидается ровно 4 попытки.
Дополнительно CI41332297452 обнаружил flaky старого CatalogCursor test: token[:-2]+AA иногда равнялся исходному token. Выбирается гарантированно иной suffix без ослабления проверок; production cursor код не меняется.
Итого: 9 уникальных принятых, отклонённых0, пробелов0. Каждый дополнительный 5A после первого добавил0; второй/третий5B добавили2/2.

Итоговый пакет 8eb4e20094c1589cf99e361c0625cbd40c1156ad1ed4f63d298cf1a6790488cc: четвёртые 5A и 5B complete, новых findings/rule gaps/nitpicks нет. Дополнительно принятых 0/0 сверх предыдущих, все9 ранее принятых устранены. Финальный CI41333118709 полностью PASS до merge.

