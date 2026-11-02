# product-research-ozon — независимое ревью этапа 6

Дата: 02.11.2026. Claude CLI отсутствует (`command -v claude` не вернул путь).
По решению владельца применена замена: две новые независимые сессии Codex CLI,
5A (правила/ADR) и 5B (дефекты/приёмка), с read-only sandbox, отдельным cwd и без
контекста реализации. Самопроверка/субагенты заменой не являлись.
CLI 0.166.0; фактические gpt-6.1-sol / high подтверждены заголовками stderr ниже.
Адаптер metadata не извлекает модель; header сохранён отдельно без догадки.

| Раунд | 5A: новых принятых | 5B: новых сверх предыдущих | Итог |
|---|---:|---:|---|
| 1 | 0 | 2 | Оба дефекта воспроизведены Red, исправлены |
| 2 | 0 | 1 | Legacy adoption deadline воспроизведён Red, исправлен |
| 3 | 0 | 0 | Complete, новых замечаний/пробелов/стиля нет |

Приняты 3 уникальных замечания, отклонённых и пробелов правил нет.
Дополнительно автор исправил сохранение finished_at и достоверность неизвестного
legacy used_at. Оба исправления покрыты вторым и третьим полными пакетами и тестами.
Одна цитата 5B не имела полного текстового якоря; сопоставлена с restarted в
job_store.py, дефект подтверждён воспроизводимым Red-тестом.
Каждый раунд начат после зелёного полного изолированного fresh db-rebuild-check.

## Разбор

1 принято: Срок отдельного потребителя не делает готовый owner непригодным для других. При ready owner после deadline потребителя resolve сохраняет slot и отдаёт expired для завершения только текущего job. Regression test_expired_consumer_cannot_evict_ready_owner воспроизведён Red (два запроса), после исправления даёт один запрос и cache hit следующего проекта.

2 принято: При cache budget rejection generation restart больше не заменяет ошибочный snapshot. Worker исполняет collector только для collecting snapshot, терминальные ошибки применяет без I/O. Regression test_rotation_cannot_bypass_cached_budget_rejection воспроизведён Red (вызван collector), после исправления terminal error без collector/API. Цитата 5B без полного якоря сопоставлена с реальной проверкой restarted в job_store.py; воспроизведение подтверждено.

Самопроверка: повторная сверка завершённого run меняла finished_at на время сверки. Дополнительный Red PHP-тест воспроизвёл изменение 09:00:01 на 11:00:00. COALESCE сохраняет первое завершение, а terminal progress не открывается повторно queued/running. Это отдельная авторская находка, не дополнительное замечание ревьюера. Полный повторный fresh rebuild и новые 5A/5B покрывают исправления.

Самопроверка: для legacy ready API job этап 5 не сохранял used_at. Убрана недостоверная подстановка completed_at; новое поле остаётся null, v2/PHP явно поддерживают неизвестную дату API-истории. Cache/new jobs сохраняют точное время; cache-null запрещён. Контрактные/HTTP/DB regression cases проверяют эту границу.

3 принято: Adoption legacy-ready owner также выполняется независимо от deadline потребителя. Поиск выбирает общий ready job без фильтра срока, после закрепления слота resolve отдельно завершает expired consumer. Параметр legacy=True в test_expired_consumer_cannot_evict_ready_owner сначала воспроизвёл Red (повторный API), после удаления ограничения проходит Green.


## Полные заключения и метаданные

### Раунд 1, 5A

Сессия `20261102T040131.172375Z-8428a67861c0-codex-frun91ip`. SHA-256 review.json: `49685baffd5b46b0dc3bcfffdc63e07b6c463e7b5453d773ca10ccf3958e2f9a`.

```text
OpenAI Codex v0.166.0
--------
workdir: /tmp/conwix-review-session-rfnzh5c1
model: gpt-6.1-sol
provider: openai
approval: on-request
sandbox: read-only
reasoning effort: high
reasoning summaries: none
session id: 01a19a6b-6adc-7953-b59a-35cf435b4228
--------
user
```

```json
{
  "role": "codex",
  "package_sha256": "8428a67861c03c4f5357c9941755e8b8475eb10ac65b3343bc08626d2157b26c",
  "request_sha256": "7677be43fe78018e438ddc7e1f0fbd841b8fd6239dbab618dd3e2d9befb01fc6",
  "package": "/home/deploy/app-service-analytics/var/review/8428a67861c0-5o4_000m",
  "started_at": "20261102T040131.172375Z",
  "status": "complete",
  "models": [],
  "models_reported": null,
  "model_note": "Модель из метаданных Codex не извлекалась; см. stdout.txt и stderr.txt",
  "codex_approval_reviewer": "auto_review",
  "exit_code": 0,
  "findings_count": 0,
  "unanchored_count": 0,
  "nitpicks_count": 0,
  "rule_gaps_count": 0,
  "finished_at": "2026-11-02T04:02:05.805886+00:00"
}
```

```json
{
  "status": "complete",
  "package_sha256": "8428a67861c03c4f5357c9941755e8b8475eb10ac65b3343bc08626d2157b26c",
  "summary": "Пакет прочитан полностью. Проверены изменения на соответствие приложенным правилам и ADR-050/044/043: company/source scope, хранение технических метаданных, координация через БД, ORM-запись требований проекта, ограниченные чтения, миграции, неизменность v1 и отдельный read-only v2. Нарушений записанных правил не выявлено. Тесты и сборки не запускались; заявленные результаты проверок независимо не подтверждались. Поиск поведенческих дефектов относится к проходу 5B.",
  "findings": [],
  "rule_gaps": [],
  "nitpicks": []
}
```

### Раунд 1, 5B

Сессия `20261102T040131.182454Z-8428a67861c0-defects-q2wbo5ws`. SHA-256 review.json: `f5d1d3bd90c50b041535f317e001ec65dc946328dbb85f37b550ad914305956d`.

```text
OpenAI Codex v0.166.0
--------
workdir: /tmp/conwix-review-session-u68nzupo
model: gpt-6.1-sol
provider: openai
approval: on-request
sandbox: read-only
reasoning effort: high
reasoning summaries: none
session id: 01a19a6b-6adc-79b2-8867-55651dc856e3
--------
user
```

```json
{
  "role": "defects",
  "package_sha256": "8428a67861c03c4f5357c9941755e8b8475eb10ac65b3343bc08626d2157b26c",
  "request_sha256": "998d6a6be68d11b7fd39e83da8a5f19933a4960b51f2e0761598094d5e2d21e6",
  "package": "/home/deploy/app-service-analytics/var/review/8428a67861c0-5o4_000m",
  "started_at": "20261102T040131.182454Z",
  "status": "complete",
  "models": [],
  "models_reported": null,
  "model_note": "Модель из метаданных Codex не извлекалась; см. stdout.txt и stderr.txt",
  "codex_approval_reviewer": "auto_review",
  "exit_code": 0,
  "findings_count": 2,
  "unanchored_count": 1,
  "nitpicks_count": 0,
  "rule_gaps_count": 0,
  "finished_at": "2026-11-02T04:04:24.248238+00:00"
}
```

```json
{
  "status": "complete",
  "package_sha256": "8428a67861c03c4f5357c9941755e8b8475eb10ac65b3343bc08626d2157b26c",
  "summary": "Прочитан весь приложенный пакет; проверены сценарии кэша, конкуренции, восстановления, изоляции, контрактов и локальных статусов относительно критериев приёмки. Найдены два сценария лишних обращений к API, нарушающие гарантии closed cache и проверки бюджетов. Тесты и сборки не запускались.",
  "findings": [
    {
      "kind": "дефект",
      "location": "services/product-research-ozon/src/product_research_ozon/infrastructure/month_cache.py:97",
      "detail": "Готовый owner заменяется текущим job, если completed_at не укладывается в deadline текущего job. Это ограничение конкретного потребителя не делает готовый кэш непригодным для остальных. Например, более ранний job A ожидает owner B, который был принят позже и завершился после deadline A, но до собственного deadline. Просроченный A переписывает slot на себя и завершается ошибкой. Следующий проект больше не видит готовый B: поиск legacy выполняется только при INSERT нового slot.",
      "quote": "        \"UPDATE month_cache_slot SET owner_job_id=%s WHERE company_id=%s AND source_id=%s AND key_hash=%s\",\n        (job_id, company_id, source_id, key.digest),",
      "failure": "B готов после deadline ожидающего A → A заменяет готовый slot и получает deadline_exceeded → новый C повторно вызывает MPStats при наличии пригодного ready job и snapshot B. Следует сохранять готового owner, даже если его результат нельзя принять для конкретного просроченного потребителя."
    },
    {
      "kind": "дефект",
      "location": "services/product-research-ozon/src/product_research_ozon/infrastructure/job_store.py:267",
      "detail": "Отказ reuse по строгому бюджету теряется при одновременной смене поколения credentials. Ветка budget помечает собственный snapshot ошибкой budget_exceeded, но следующая проверка поколения перезапускает этот snapshot и удаляет результат проверки бюджета. Worker получает новый collecting snapshot и начинает загрузку через API. Сценарий возможен, когда job принят до ротации, а впервые захвачен после неё.",
      "quote": "            restarted = snap.generation != generation and snap.status != \"ready\"\n            if restarted:",
      "failure": "Есть готовый closed cache; новый job принят с меньшим бюджетом в generation 1 → активируется generation 2 → resolve возвращает budget → generation restart заменяет ошибочный snapshot → worker выполняет лишний API-запрос вместо отказа без I/O. Отказ по бюджету должен сохраняться независимо от необходимости перезапуска поколения."
    }
  ],
  "rule_gaps": [],
  "nitpicks": []
}
```

### Раунд 2, 5A

Сессия `20261102T042042.394758Z-7a3392f33f1f-codex-n2r1yv71`. SHA-256 review.json: `9460f865f24978ca309f53f06277229675ce49c6f7f3e999642b8a3750641a60`.

```text
OpenAI Codex v0.166.0
--------
workdir: /tmp/conwix-review-session-2ndy_n0f
model: gpt-6.1-sol
provider: openai
approval: on-request
sandbox: read-only
reasoning effort: high
reasoning summaries: none
session id: 01a19a7c-fb9a-7a32-858c-c0b2e92edb97
--------
user
```

```json
{
  "role": "codex",
  "package_sha256": "7a3392f33f1fe03de50308a62147f6f91187f5a7a0bfd093a994205aceb4439c",
  "request_sha256": "8385285f2b820c5a77ca31b553348a83164496a21a3f28fbcdd83b6a8b3e4eaf",
  "package": "/home/deploy/app-service-analytics/var/review/7a3392f33f1f-mldt2zbx",
  "started_at": "20261102T042042.394758Z",
  "status": "complete",
  "models": [],
  "models_reported": null,
  "model_note": "Модель из метаданных Codex не извлекалась; см. stdout.txt и stderr.txt",
  "codex_approval_reviewer": "auto_review",
  "exit_code": 0,
  "findings_count": 0,
  "unanchored_count": 0,
  "nitpicks_count": 0,
  "rule_gaps_count": 0,
  "finished_at": "2026-11-02T04:21:12.328934+00:00"
}
```

```json
{
  "status": "complete",
  "package_sha256": "7a3392f33f1fe03de50308a62147f6f91187f5a7a0bfd093a994205aceb4439c",
  "summary": "Полностью прочитан приложенный пакет: диф, правила, ADR и окружающий код. Проверены изоляция компаний, границы модулей и сервисов, миграции, ограниченность чтений, версионирование контрактов, provenance и границы этапа 6. Исправления принятых замечаний предыдущего прохода сверены с актуальным кодом. Нарушений записанных правил и ADR не выявлено. Тесты и сборки не запускались; сеть и файлы не изменялись.",
  "findings": [],
  "rule_gaps": [],
  "nitpicks": []
}
```

### Раунд 2, 5B

Сессия `20261102T042042.403183Z-7a3392f33f1f-defects-bdtifedc`. SHA-256 review.json: `2e83c377f8774020926990a065fffd550cb3e8722397bf6efceac39db1e599b2`.

```text
OpenAI Codex v0.166.0
--------
workdir: /tmp/conwix-review-session-xbygs62y
model: gpt-6.1-sol
provider: openai
approval: on-request
sandbox: read-only
reasoning effort: high
reasoning summaries: none
session id: 01a19a7c-fb9a-70b3-aee8-fecd5c13a8c0
--------
user
```

```json
{
  "role": "defects",
  "package_sha256": "7a3392f33f1fe03de50308a62147f6f91187f5a7a0bfd093a994205aceb4439c",
  "request_sha256": "8e51f62ec2b323ca72e1af7d92e5aa0b393006ce9d98a6447406a01c8639e8fe",
  "package": "/home/deploy/app-service-analytics/var/review/7a3392f33f1f-mldt2zbx",
  "started_at": "20261102T042042.403183Z",
  "status": "complete",
  "models": [],
  "models_reported": null,
  "model_note": "Модель из метаданных Codex не извлекалась; см. stdout.txt и stderr.txt",
  "codex_approval_reviewer": "auto_review",
  "exit_code": 0,
  "findings_count": 1,
  "unanchored_count": 0,
  "nitpicks_count": 0,
  "rule_gaps_count": 0,
  "finished_at": "2026-11-02T04:23:09.733273+00:00"
}
```

```json
{
  "status": "complete",
  "package_sha256": "7a3392f33f1fe03de50308a62147f6f91187f5a7a0bfd093a994205aceb4439c",
  "summary": "Пакет прочитан полностью. Проверены координация кэша, бюджеты и ротация, provenance v2, неизменность finished_at, статусы проекта и reconciliation. Предыдущие исправления проверены; найден оставшийся сценарий потери повторного использования legacy-кэша.",
  "findings": [
    {
      "kind": "дефект",
      "location": "services/product-research-ozon/src/product_research_ozon/infrastructure/month_cache.py:52",
      "detail": "При первом создании slot поиск legacy-owner по-прежнему ограничен deadline текущего потребителя. Если готовый legacy job завершился после этого deadline, он исключается, slot закрепляется за просроченным потребителем, который затем завершается ошибкой. Следующий потребитель заменяет ошибочного owner без повторного поиска legacy и заново загружает уже готовый месяц. Исправление сохранения ready-owner не покрывает эту ветку: legacy-owner следует выбирать независимо от deadline потребителя, а пригодность для текущего job проверять после закрепления slot.",
      "quote": "AND s.status='ready' AND j.status='ready' AND s.completed_at<%s",
      "failure": "После обновления есть legacy ready job B, завершённый до собственного deadline, но после deadline более раннего job A; slot ещё отсутствует → первым захватывается просроченный A, исключает B из adoption и становится ошибочным owner → новый job C выполняет лишний API-запрос вместо reuse готового B."
    }
  ],
  "rule_gaps": [],
  "nitpicks": []
}
```

### Раунд 3, 5A

Сессия `20261102T042810.432893Z-9dc1984adb05-codex-1kl7mlbh`. SHA-256 review.json: `2de29adc19f63d9aff210c40d10c6e2640acd87fed25451a9582bcd2c32f7ea1`.

```text
OpenAI Codex v0.166.0
--------
workdir: /tmp/conwix-review-session-hcsq1bn9
model: gpt-6.1-sol
provider: openai
approval: on-request
sandbox: read-only
reasoning effort: high
reasoning summaries: none
session id: 01a19a83-d253-7251-9cd9-53ab76dfceee
--------
user
```

```json
{
  "role": "codex",
  "package_sha256": "9dc1984adb05110ab84f056d39245ee0f4ad9dd5b9d3ecfbd951d6f7e8d183af",
  "request_sha256": "68634d4613837413d5732e9be832bb854cbfb0a2696ce41e263928f6bdb3c072",
  "package": "/home/deploy/app-service-analytics/var/review/9dc1984adb05-py504s48",
  "started_at": "20261102T042810.432893Z",
  "status": "complete",
  "models": [],
  "models_reported": null,
  "model_note": "Модель из метаданных Codex не извлекалась; см. stdout.txt и stderr.txt",
  "codex_approval_reviewer": "auto_review",
  "exit_code": 0,
  "findings_count": 0,
  "unanchored_count": 0,
  "nitpicks_count": 0,
  "rule_gaps_count": 0,
  "finished_at": "2026-11-02T04:28:33.026778+00:00"
}
```

```json
{
  "status": "complete",
  "package_sha256": "9dc1984adb05110ab84f056d39245ee0f4ad9dd5b9d3ecfbd951d6f7e8d183af",
  "summary": "Пакет прочитан полностью. Проверены изменения на соответствие записанным правилам и ADR-050/044/043: изоляция компаний, семантический ключ кэша, границы сервисов и слоёв, миграции, версионирование контрактов, provenance и ограниченная company-scoped сверка. Исправления трёх принятых замечаний присутствуют. Нарушений записанных правил не обнаружено. Тесты и сборки не запускались, сеть не использовалась.",
  "findings": [],
  "rule_gaps": [],
  "nitpicks": []
}
```

### Раунд 3, 5B

Сессия `20261102T042810.424813Z-9dc1984adb05-defects-3bav83nf`. SHA-256 review.json: `20ce3b2c257c37bbd44f7288204c513bebef27657a28860652f35f648801fc60`.

```text
OpenAI Codex v0.166.0
--------
workdir: /tmp/conwix-review-session-sllxl5hf
model: gpt-6.1-sol
provider: openai
approval: on-request
sandbox: read-only
reasoning effort: high
reasoning summaries: none
session id: 01a19a83-d255-79c2-9fd4-81431bfbee9b
--------
user
```

```json
{
  "role": "defects",
  "package_sha256": "9dc1984adb05110ab84f056d39245ee0f4ad9dd5b9d3ecfbd951d6f7e8d183af",
  "request_sha256": "6b7e18fe097df3eb80de1c4e075d1e4816a1df8f7542ab7e78b2296081250411",
  "package": "/home/deploy/app-service-analytics/var/review/9dc1984adb05-py504s48",
  "started_at": "20261102T042810.424813Z",
  "status": "complete",
  "models": [],
  "models_reported": null,
  "model_note": "Модель из метаданных Codex не извлекалась; см. stdout.txt и stderr.txt",
  "codex_approval_reviewer": "auto_review",
  "exit_code": 0,
  "findings_count": 0,
  "unanchored_count": 0,
  "nitpicks_count": 0,
  "rule_gaps_count": 0,
  "finished_at": "2026-11-02T04:30:06.743879+00:00"
}
```

```json
{
  "status": "complete",
  "package_sha256": "9dc1984adb05110ab84f056d39245ee0f4ad9dd5b9d3ecfbd951d6f7e8d183af",
  "summary": "Пакет прочитан полностью. Проверены координация closed cache, дедлайны потребителей, бюджеты при ротации credentials, legacy adoption, provenance и неизвестный used_at, неизменность finished_at, frozen requirements, статусы проекта и keyset reconciliation завершённых запусков. Исправления трёх предыдущих замечаний соответствуют описанным сценариям. Новых подтверждённых дефектов не обнаружено. Проверка статическая; тесты, сборки и сеть не запускались. UI и production-активация обоснованно оставлены этапам 7–8.",
  "findings": [],
  "rule_gaps": [],
  "nitpicks": []
}
```

## Файлы снимка перед отчётными Markdown-изменениями

```json
[
  {
    "path": "api/src/ProductResearchOzon/Application/AddResearchPeriodsAction.php",
    "sha256": "5f8ed276882ec793b6a449550b3e355f828a95517b38be3ab1a3a985068270cb"
  },
  {
    "path": "api/src/ProductResearchOzon/Application/CopyResearchProjectAction.php",
    "sha256": "275b3a113b03ea82201d0312ccf942f31949f79ec6d6d1df6825ab2ae7876ae1"
  },
  {
    "path": "api/src/ProductResearchOzon/Application/CreateResearchProjectAction.php",
    "sha256": "f30b371fbeadd0b7094d7ba68c07725d59a66a960af95ae89438e9ebc199ad7a"
  },
  {
    "path": "api/src/ProductResearchOzon/Application/StartResearchRunAction.php",
    "sha256": "60ce8cdef8ec2401969eb2093b1e9f60bf557a68beabad3f383d26ad1892df73"
  },
  {
    "path": "api/src/ProductResearchOzon/Application/SynchronizeResearchRunAction.php",
    "sha256": "789e04ca33b7fe4afc8c4ddd1ec0b56ec70651333f989a91574b67a10d35bc8b"
  },
  {
    "path": "api/src/ProductResearchOzon/Domain/ResearchProject.php",
    "sha256": "e95a1974802b8a506b5bfbafcea3ed29d42f8ba4d1cedd86f0b9328c052675bb"
  },
  {
    "path": "api/src/ProductResearchOzon/Domain/ResearchRun.php",
    "sha256": "8996506f3cfcb8625f0105e42e891c6a4bfd9f758c2140a4895737418e5d04ae"
  },
  {
    "path": "api/src/ProductResearchOzon/Domain/ResearchRunMonth.php",
    "sha256": "77648befb4be312bf82377254b42fa53a35251137e71bd7f2ec1888ab2dc5af0"
  },
  {
    "path": "api/src/ProductResearchOzon/Domain/RunDeliveryRepository.php",
    "sha256": "9efc30237118c3008d64539289015008f09ec0aba87fa33b220ff32e354a8da2"
  },
  {
    "path": "api/src/ProductResearchOzon/Domain/ValueObject/RunMonthState.php",
    "sha256": "f0429df6c098e1ff71fdc2b31c6dee0a21f7021a3b3c645f7f4b1a8b38eb6b25"
  },
  {
    "path": "api/src/ProductResearchOzon/Domain/ValueObject/RunProgress.php",
    "sha256": "059807d845d65ed9c2b64da4921e4090315be924e8510836b4068ad49c842e4a"
  },
  {
    "path": "api/src/ProductResearchOzon/Infrastructure/Api/HttpRunSource.php",
    "sha256": "c8e25a1ca7f40c63e50478960034ae56902130c11aebdf346b63514128ee7b35"
  },
  {
    "path": "api/src/ProductResearchOzon/Infrastructure/Query/ResearchProjectRow.php",
    "sha256": "49769f3511c7647ec487c7e167d380b45726ad7058bbd960850c4f43203a1262"
  },
  {
    "path": "api/src/ProductResearchOzon/Infrastructure/Query/ResearchProjectSummaryRow.php",
    "sha256": "16f4e589e15e061f305c201062ba154d9c7de0663fe32da83213f6725957160d"
  },
  {
    "path": "api/src/ProductResearchOzon/Infrastructure/Query/ResearchProjectsQuery.php",
    "sha256": "be66c5c80bf90e38d1609812a474c36845fcfcb80a1e0b074e4a1ba4a0a08284"
  },
  {
    "path": "api/src/ProductResearchOzon/Infrastructure/Query/ResearchRunsQuery.php",
    "sha256": "e9c09d580258cec2990ad8a847790f0729146e91f34f9f0e27d6fb3bb0ff945a"
  },
  {
    "path": "api/src/ProductResearchOzon/Infrastructure/Repository/DbalRunDeliveryRepository.php",
    "sha256": "2431e84c2d916219b7d322b639b4bd8d501f95313a71e899ce4777e946bbcecd"
  },
  {
    "path": "api/src/ProductResearchOzon/Ui/Controller/AddResearchPeriodsController.php",
    "sha256": "f6be3907920e40e3475e5e764fb491a3177963a39f0cf461a2882f5a7a1e0734"
  },
  {
    "path": "api/src/ProductResearchOzon/Ui/Controller/CopyResearchProjectController.php",
    "sha256": "6509cf006fd87f4d19aabb72bab494183cd8e28c7063e00c6b4c1970d39c5675"
  },
  {
    "path": "api/src/ProductResearchOzon/Ui/Controller/CreateResearchProjectController.php",
    "sha256": "64d8a07bd6eaf2691e7e3666062dbed0d700ccded7875b3f70ff63cb6d679bec"
  },
  {
    "path": "api/src/ProductResearchOzon/Ui/Response/ProjectResponse.php",
    "sha256": "10a7b3d75d5e282373fda275a1e62752d45130dd57f4b3cb1e222f09d12ba8bc"
  },
  {
    "path": "api/src/ProductResearchOzon/Ui/Response/ProjectSummaryResponse.php",
    "sha256": "843f58020af6f68d61942311d92d445f8399d140a8fc0f9b2d9148f8a9ebca82"
  },
  {
    "path": "api/src/ProductResearchOzon/Ui/Response/RunMonthResponse.php",
    "sha256": "120adb7b4b79162bf69627ecd308d7fd1bf06c89f71676a3fa36a105495daa3f"
  },
  {
    "path": "api/tests/Functional/ProductResearchOzon/ResearchProjectsControllerTest.php",
    "sha256": "ab0e2bb5c60337b07b33cdcb7a251fecc362483e697ebbb93401b2c71197d240"
  },
  {
    "path": "api/tests/Integration/ProductResearchOzon/ResearchRunsTest.php",
    "sha256": "e8cb56ecb1d9b23220cd68409cc1204a6a9f297fc2ed179cdd7af43dea44bac9"
  },
  {
    "path": "api/tests/Unit/ProductResearchOzon/RunClientTest.php",
    "sha256": "b9b3bc0d9e49935b6235af63c4fbeff113c9a889a2af18ad8c2c8227b8a75dd7"
  },
  {
    "path": "docs/structure.md",
    "sha256": "de4ff7cb8924b72da4518f1049568d03ec95629fa74a5733d19e195f2a9891b8"
  },
  {
    "path": "packages/api-schema/openapi.json",
    "sha256": "9ed4c594597ae49a1cc702b07a09f30cc5c9a5fac98e62660c7dbfdc8caea49e"
  },
  {
    "path": "packages/api-schema/src/schema.d.ts",
    "sha256": "b2e2daac6cec4b938bca2bc38ecfe7d096d32eb9beee5525349b75c1888bf6b8"
  },
  {
    "path": "services/product-research-ozon/AGENTS.md",
    "sha256": "a3917b885b6e947a19224343d3fe960c14aafa714d8a124b47b36b3782234982"
  },
  {
    "path": "services/product-research-ozon/README.md",
    "sha256": "fd8f2c9164e94698d7b4982b324d443a57bb2cd22f0609f0eaeebee29cb6807e"
  },
  {
    "path": "services/product-research-ozon/src/product_research_ozon/api.py",
    "sha256": "ffc89a75b401124e42b9606807be41566c690daba636b881c852425e4d816800"
  },
  {
    "path": "services/product-research-ozon/src/product_research_ozon/infrastructure/job_store.py",
    "sha256": "d6d9147e571584c1f3f09da92fe259f56c0bbdf0f2a71e72a720e1e548f1c690"
  },
  {
    "path": "services/product-research-ozon/src/product_research_ozon/infrastructure/readiness.py",
    "sha256": "fa482603364795aeba28e24d0faee05d945ddf30c1869a97da22cd2e57822f28"
  },
  {
    "path": "services/product-research-ozon/src/product_research_ozon/jobs/service.py",
    "sha256": "f57ab93dc1bb0e60102b914ccbed0d78f233108c6875c171c0aef122a7070961"
  },
  {
    "path": "services/product-research-ozon/tests/unit/test_contract.py",
    "sha256": "264778b6a9e3572a8cc003227db225d5038e8731ae5690e795041686ec959db1"
  },
  {
    "path": "services/product-research-ozon/typings/jsonschema/__init__.pyi",
    "sha256": "7eea6d056f58e1448c44e790599a7e8a7c7532ef4ce847e2299f81a08504cff4"
  },
  {
    "path": "api/migrations/Version20261102033959.php",
    "sha256": "4d53dc21baf2429c4a013ed6fba51e23270ad11748242487c8566fada4af9b5a"
  },
  {
    "path": "api/src/ProductResearchOzon/Application/ReconcileResearchRunsAction.php",
    "sha256": "13a8386dea90d4017263b9d892e61446574254afb2d978a4f9323248c10126b1"
  },
  {
    "path": "api/src/ProductResearchOzon/Application/ReconciliationResult.php",
    "sha256": "d4a34f92207f230b8e2b54ac8b59209bdb5a3d12e5ab7cbddb7d81e90d65253b"
  },
  {
    "path": "api/src/ProductResearchOzon/Domain/ValueObject/RunProvenance.php",
    "sha256": "41138f8c306ca635b40d77ef7ce2cdbe7ff9d965105581554dfc66cb2e3af1fa"
  },
  {
    "path": "api/src/ProductResearchOzon/Infrastructure/Query/ProjectStatusSql.php",
    "sha256": "7e76738dc2bfe66effbf7ff2f9752942a3a72592f0471fe01092720382f67d6d"
  },
  {
    "path": "api/src/ProductResearchOzon/Ui/Command/ReconcileResearchRunsCommand.php",
    "sha256": "525485cc18798fa4db29b40de27a4ef8f17b267eb7d7945d7025053b7ea1c10a"
  },
  {
    "path": "docs/plan/product-research-ozon-stage6.md",
    "sha256": "a37868930a9e4be52a3660bcd162e8a0b01fa5d7456b340a41e095b9ed9bcd39"
  },
  {
    "path": "services/_contracts/product-research-ozon/v2/CHANGELOG.md",
    "sha256": "97e086e8a2640ec0633f9fb18ddf6db9ffd5208682cd542ce905d77bfa849e6f"
  },
  {
    "path": "services/_contracts/product-research-ozon/v2/README.md",
    "sha256": "10d284b888de73936c65f22200f4272008e6bfb42a2edc2d591518080a77e3c5"
  },
  {
    "path": "services/_contracts/product-research-ozon/v2/examples.json",
    "sha256": "edd842bcc27e19b821920ecc3e535743ad9fff97652dda5d257f45d4cae9ae31"
  },
  {
    "path": "services/_contracts/product-research-ozon/v2/schema.json",
    "sha256": "cd97f6c76721506d37e82da491bb154e95c8ea6379c06d990124b79f5000dbca"
  },
  {
    "path": "services/product-research-ozon/migrations/versions/0005_month_cache.py",
    "sha256": "545a519d3e0eb0feb30535e83dfc5598ab46aceefb6bccd2ac5c087b42bbb03a"
  },
  {
    "path": "services/product-research-ozon/src/product_research_ozon/domain/month_cache.py",
    "sha256": "2d47076cacf7e647991900b15e15750640f2fb5a59310bfaef6f207a5a37129b"
  },
  {
    "path": "services/product-research-ozon/src/product_research_ozon/infrastructure/month_cache.py",
    "sha256": "b37794e06ff712ecc406a3c295aba1feba5d8e6914992865c855d2cc78e946d4"
  },
  {
    "path": "services/product-research-ozon/tests/integration/test_month_cache.py",
    "sha256": "e0c0104a6dad8ea0605e5553af209e55ea08bee5cfdb4c9f340bc43dd3e04b65"
  },
  {
    "path": "services/product-research-ozon/tests/unit/test_month_cache_key.py",
    "sha256": "3317178be0bb0d4c4f6418a79ba66e1795278d4c68b05207d1afe85ecbe30874"
  }
]
```

## Контрольные суммы проверок

```json
[
  {
    "path": "var/product-research-ozon-stage6/rebuild-review3-final.log",
    "sha256": "ebbd9e84e2efb5ae01ccf51f5887a2534584970fa43608eadcba09869406b0f7"
  },
  {
    "path": "var/product-research-ozon-stage6/ci-fixed-php-gates.log",
    "sha256": "114fed8e3ae87bacd29d7afcfa347db64c63d4a31b3dceff65cac2707eef71f0"
  },
  {
    "path": "var/product-research-ozon-stage6/legacy-time-python-gates.log",
    "sha256": "9848a6a4b02a4a84311ddf5c311ce4670d19370e6481045ac700028f63017ce3"
  },
  {
    "path": "var/product-research-ozon-stage6/legacy-time-php-tests-final.log",
    "sha256": "2730f1908a86ff326b20e81c0c043548e198ec3c05bb9749a721a319228e35b3"
  },
  {
    "path": "var/product-research-ozon-stage6/review1-cache-red.log",
    "sha256": "2ddea9c2aef16a508b0b9d33b1e811e5d93add7a8861f234c3fdb8b61b9698a5"
  },
  {
    "path": "var/product-research-ozon-stage6/review1-cache-green.log",
    "sha256": "51a23452975962b3f663cb0431d67a5992c1494f0f8244f0ad86fc9853b16390"
  },
  {
    "path": "var/product-research-ozon-stage6/reconcile-time-red.log",
    "sha256": "b3faeb23b211b4f040b1099051fca3d0a84dcf638b59a96d31665302e93e4df2"
  },
  {
    "path": "var/product-research-ozon-stage6/python-static-last.log",
    "sha256": "d55b78e8c7554928b1181244a1db610885af45b1d1854f43184283c027b94aee"
  },
  {
    "path": "var/product-research-ozon-stage6/api-types-final.log",
    "sha256": "2fc64bac02ac4cf39207db106f663d18a74456f7927f87f437c2d041aa095a98"
  },
  {
    "path": "var/product-research-ozon-stage6/doctrine-down.log",
    "sha256": "d95e4bcc5fb8aa21ddfcfb5c571bfa66006c5d379b43c474a501f41b39935cad"
  },
  {
    "path": "var/product-research-ozon-stage6/doctrine-up-again.log",
    "sha256": "9e4ae804574a5354fa90fd0ea3689453ab1075d32bd2e500e70f9f38c0b8fe2b"
  },
  {
    "path": "var/product-research-ozon-stage6/review2-red.log",
    "sha256": "53464780a8b0a1f0a4de243df01497b9b6f2435f29e2eb35c694973d120f811b"
  },
  {
    "path": "var/product-research-ozon-stage6/review2-green.log",
    "sha256": "f8893dae9673e21a5cd62c504f2fe7ea76d8bf88b50d70af817eaa220752d2c2"
  },
  {
    "path": "var/product-research-ozon-stage6/review2-python-static.log",
    "sha256": "09452a595b591e536a8a61fea6980a11ed979da5e08d32e6e51305e911c3dfe2"
  },
  {
    "path": "var/product-research-ozon-stage6/runtime-final.log",
    "sha256": "9823f43b7543b0d665be730431151ce1440e2cf5762eae3513e22c6957c93e8c"
  }
]
```
