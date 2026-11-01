# product-research-ozon — независимое ревью этапа 2

Claude CLI недоступен: исполняемого файла нет. Применена разрешённая владельцем
замена: две новые сессии Codex CLI 5A/5B, без контекста реализации, sandbox read-only.

## Первый пакет

SHA256: `74bcb2f782b4c2648212b00dab42fcceddb16e90143918336340efdc8c22541f`.
База d6716aa, реализация 242f774. Проверены все 63 файла задачи.
5A: четыре уникальных принятых замечания; 5B: одно новое принятое и одно повторное.
Отклонённых замечаний и пробелов правил нет.

### 5A

Артефакт: `var/review/runs/20261101T083007.810290Z-74bcb2f782b4-codex-3s65ni4r/review.json`.

```json
{
  "status": "complete",
  "package_sha256": "74bcb2f782b4c2648212b00dab42fcceddb16e90143918336340efdc8c22541f",
  "summary": "Прочитан полный пакет: diff 63 файлов, правила, ADR и приложенный контекст. Найдены четыре нарушения записанных правил. Тесты и сборки не запускались, файлы не изменялись, сеть не использовалась.",
  "findings": [
    {
      "kind": "нарушение",
      "location": "api/src/ProductResearchOzon/Application/AddResearchPeriodsAction.php:39",
      "detail": "При изменении includeCurrentMonth прежнее значение перезаписывается, но аудит не содержит ни прежнего, ни нового значения флага. ADR-011 требует для правки на месте журнал с «было» и «стало». Счётчики периодов и месяцев это требование не закрывают.",
      "quote": "$this->identity->recordAuditEntry($companyId, $actor, 'product_research_ozon.periods_added', $projectId, null, json_encode(['addedPeriods' => \\count($new), 'monthCount' => \\count($preview->months)], \\JSON_THROW_ON_ERROR));",
      "failure": "Проект содержит только закрытые месяцы; add повторяет существующий период и меняет includeCurrentMonth с false на true → флаг сохраняется и версия растёт, но аудит содержит только addedPeriods=0 и неизменное monthCount. Восстановить «было» и «стало» из журнала нельзя."
    },
    {
      "kind": "нарушение",
      "location": "api/src/ProductResearchOzon/Domain/ResearchProject.php:39",
      "detail": "createdByUserId — новая scalar-ссылка на User другого модуля, однако миграция не создаёт индекс для неё. CLAUDE.md §6 прямо требует индекс в той же миграции для любого нового поля-ссылки на сущность другого модуля.",
      "quote": "#[ORM\\Column(type: 'uuid')]\n    private Uuid $createdByUserId;",
      "failure": "Применение миграции создаёт created_by_user_id без соответствующего индекса → новая межмодульная ссылка остаётся без обязательного индекса; существующий PK (company_id, id) её не покрывает."
    },
    {
      "kind": "нарушение",
      "location": "api/src/ProductResearchOzon/Infrastructure/Query/ResearchProjectsQuery.php:22",
      "detail": "Списочный Query самостоятельно выполняет запрос и возвращает массив. CLAUDE.md §5 и docs/patterns.md явно требуют возвращать QueryBuilder, оставляя выполнение и сбор результата вызывающему коду. Наличие собственного bounded keyset не отменяет это записанное требование.",
      "quote": "public function page(string $companyId, ?string $cursor, int $limit): array",
      "failure": "Вызов page для списка проектов → вызывающий слой получает уже выполненную выборку вместо обязательного QueryBuilder; установленный контракт Query-класса нарушен."
    },
    {
      "kind": "нарушение",
      "location": "api/src/ProductResearchOzon/Infrastructure/Repository/DoctrineResearchProjectRepository.php:67",
      "detail": "Для новой Entity ResearchPeriod отсутствует собственный Builder: в составе изменения есть только ResearchProjectBuilder. CLAUDE.md §9 и ADR-005 требуют Builder на каждую сущность в том же изменении. Подготовка периодов через production-репозиторий не создаёт требуемого Builder.",
      "quote": "$this->em->persist(new ResearchPeriod($project, $range, $offset + $position));",
      "failure": "Подготовка тестового проекта с периодами через ResearchProjectBuilder → ResearchPeriod создаются внутри production-репозитория, а обязательный Builder соответствующей новой Entity отсутствует."
    }
  ],
  "rule_gaps": [],
  "nitpicks": []
}
```

### 5B

Артефакт: `var/review/runs/20261101T083007.820421Z-74bcb2f782b4-defects-chhoxvej/review.json`.

```json
{
  "status": "complete",
  "package_sha256": "74bcb2f782b4c2648212b00dab42fcceddb16e90143918336340efdc8c22541f",
  "summary": "Пакет прочитан полностью. Проверены календарь, tenant scope, транзакции, конкурентные add/copy, guards, миграция и публичный контракт. Найдены два дефекта: неверный тип месяца в OpenAPI/TS и потеря истории изменения includeCurrentMonth в аудите. Заявленные результаты тестов независимо не воспроизводились; G10–G12 остаются незакрытыми.",
  "findings": [
    {
      "kind": "дефект",
      "location": "api/src/ProductResearchOzon/Ui/Response/MonthResponse.php:18",
      "detail": "Аргумент конструктора $month совпадает с именем строкового свойства. Генератор описал MonthResponse.month как объект MonthSlice: это явно видно в приложенных OpenAPI и schema.d.ts. Реальный JSON содержит строку YYYY-MM. Проверка синхронности генерации такого расхождения не обнаруживает. Нужно исправить источник описания и перегенерировать контракт.",
      "quote": "            month: components[\"schemas\"][\"MonthSlice\"];",
      "failure": "Успешный preview возвращает months[0].month = \"2026-01\" → ответ не соответствует опубликованной схеме; клиент, написанный по сгенерированному типу, ожидает объект вместо строки."
    },
    {
      "kind": "дефект",
      "location": "api/src/ProductResearchOzon/Application/AddResearchPeriodsAction.php:40",
      "detail": "Add допускает изменение includeCurrentMonth при повторной передаче существующего периода. Флаг перезаписывается, но аудит сохраняет только addedPeriods/monthCount и previousValue=null. Прежнее и новое значения флага отсутствуют, поэтому историю этой правки восстановить невозможно.",
      "quote": "            $this->identity->recordAuditEntry($companyId, $actor, 'product_research_ozon.periods_added', $projectId, null, json_encode(['addedPeriods' => \\count($new), 'monthCount' => \\count($preview->months)], \\JSON_THROW_ON_ERROR));",
      "failure": "Проект содержит только закрытые месяцы и includeCurrentMonth=false → запрос с существующим периодом и includeCurrentMonth=true меняет флаг и версию, но оставляет лишь addedPeriods=0 и прежний monthCount; обратное переключение создаёт такую же запись без сведений о значениях флага."
    }
  ],
  "rule_gaps": [],
  "nitpicks": []
}
```

## Исправления

1. Аудит add хранит прежние и новые нормализованные диапазоны и includeCurrentMonth.
   Новый тест сначала падал на previous_value=null, затем прошёл для переключения
   false→true→false без добавления диапазонов. No-op по-прежнему не пишет аудит.
2. Индекс (company_id, created_by_user_id) добавлен в Entity и единственную миграцию.
3. Query возвращает QueryBuilder; контроллеры выполняют запрос и собирают readonly DTO.
   Список выбирает только шесть нужных полей, detail сохраняет один MVCC SELECT.
4. Добавлен неизменяемый ResearchPeriodBuilder и проверено сохранение периода
   вместе с company-scoped проектом через ORM.
5. Устранена коллизия аргумента конструктора MonthResponse с именем свойства.
   OpenAPI/TS перегенерированы. Новый тест проверяет настоящий сериализованный ответ
   против публичной схемы: Red до исправления, Green после.
6. Повтор замечания 1 в 5B; исправлено тем же изменением, вторым уникальным не считается.

## Повторное ревью

Будет выполнено по новому полному пакету с обоими предыдущими заключениями
и явным разбором. До его завершения merge и закрытие не допускаются.
