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

## Второй пакет

SHA256: `484f3b790d227d5935a70d5e4bb44303fe0081cf4013a73718eba43bfb0fd9a6`.
Реализация 939153d; обе новые сессии Codex CLI 0.166.0, gpt-6.1-sol, high.
5A: 0 замечаний. 5B: 2 новых замечания, одно принято и одно отклонено с проверкой.
Предыдущие шесть замечаний признаны устранёнными обоими проходами.


### 5A

Артефакт: `var/review/runs/20261101T083957.519700Z-484f3b790d22-codex-m46wu09k/review.json`.

```json
{
  "status": "complete",
  "package_sha256": "484f3b790d227d5935a70d5e4bb44303fe0081cf4013a73718eba43bfb0fd9a6",
  "summary": "Полностью прочитан приложенный пакет: диф, правила, ADR, критерии и окружающий код. Новых нарушений записанных правил не обнаружено. Исправления всех шести прежних замечаний подтверждены чтением: before/after аудит диапазонов и флага, индекс автора в ORM и миграции, возврат QueryBuilder, отдельный ResearchPeriodBuilder, строковый MonthResponse.month в OpenAPI/TS и регрессионные проверки. Замечания 1 и 6 устранены одним исправлением. Тесты и сборки не запускались; заявленные результаты независимо не воспроизводились. Повторный db-rebuild-check итоговой миграции, полный состав независимого ревью, финальный CI и доставка остаются незавершёнными по сведениям пакета; это заключение не закрывает G10–G12.",
  "findings": [],
  "rule_gaps": [],
  "nitpicks": []
}
```

### 5B

Артефакт: `var/review/runs/20261101T083957.498929Z-484f3b790d22-defects-pcb08m62/review.json`.

```json
{
  "status": "complete",
  "package_sha256": "484f3b790d227d5935a70d5e4bb44303fe0081cf4013a73718eba43bfb0fd9a6",
  "summary": "Пакет прочитан полностью. Проверены календарь, изоляция, транзакции, конкурентные add/copy, миграция, guards и API-контракт. Исправления всех шести предыдущих замечаний подтверждены по коду, схеме и добавленным тестам. Найдены два дефекта валидации входа. Тесты и сборки не запускались. Повторный rebuild итоговой миграции и G10–G12 по представленным данным ещё не завершены.",
  "findings": [
    {
      "kind": "дефект",
      "location": "api/src/ProductResearchOzon/Ui/Controller/ListResearchProjectsController.php:47",
      "detail": "Uuid::isValid() по умолчанию принимает также представления UUID в base32/base58. Проверенный cursor передаётся в SQL без преобразования в RFC 4122, тогда как PostgreSQL сравнивает его с колонкой uuid. Допустимый для Symfony альтернативный формат вызывает ошибку БД вместо документированного отказа 422. Следует ограничить проверку форматом RFC 4122 либо нормализовать курсор перед запросом.",
      "quote": "            if (null !== $cursor && (!\\is_string($cursor) || !Uuid::isValid($cursor))) {",
      "failure": "Авторизованный seller запрашивает список с cursor=00000000000000000000000000 — base32-представлением nil UUID → проверка Symfony проходит → PostgreSQL отвергает 26-символьный параметр как неверный uuid → HTTP 500."
    },
    {
      "kind": "дефект",
      "location": "api/src/ProductResearchOzon/Ui/Request/ResearchRequest.php:150",
      "detail": "Проверка текстовых полей пропускает U+0000, который допустим внутри JSON-строки, но не поддерживается PostgreSQL в varchar/text. Например, comment с экранированным нулевым символом проходит parser и доменный конструктор, после чего сохранение падает с необработанным исключением БД. Неподдерживаемый символ нужно отвергать на границе ввода с 422.",
      "quote": "        if (!\\is_string($value) || mb_strlen($value) > $max || (!$allowEmpty && '' === trim($value))) {",
      "failure": "При включённом модуле и подтверждённой категории валидный create или copy содержит comment=\"\\u0000\" → проверки типа и длины проходят → INSERT в comment VARCHAR(2000) завершается ошибкой PostgreSQL → клиент получает 500 вместо 422."
    }
  ],
  "rule_gaps": [],
  "nitpicks": []
}
```

### Разбор второго пакета

1. Отклонено: утверждение, что Uuid::isValid по умолчанию принимает base32/base58,
   неверно для установленного symfony/uid. В vendor/symfony/uid/Uuid.php параметр
   по умолчанию FORMAT_RFC_9562; transformToRfc9562 отвергает другие форматы.
   Прямой вызов с 00000000000000000000000000 даёт false. HTTP-регрессия проверяет
   base32 nil и base58 nil: оба возвращают 422 уже до правки production-кода.
   Код установленной библиотеки включён в контекст следующего ревью.
2. Принято: U+0000 не должен доходить до PostgreSQL. В этом окружении тесты
   воспроизвели 201/200 и обрезание строк драйвером, а не описанный ревьюером 500.
   Общая текстовая валидация теперь отвергает NUL до записи, одинаково для
   comment/name/categoryPath/имени периода. Три HTTP-регрессии create/copy/add:
   Red до исправления; затем 422, без новых проектов/аудита и без смены версии.

## Третий пакет

SHA256: `03a420b3bf0db9b5aec91ddb4a78bb92025cf9772287835bf9abdefb3b263fae`.
Head 4427122, все 67 файлов задачи; оба прохода complete, exit_code=0,
findings=[], rule_gaps=[], nitpicks=[]. Codex CLI 0.166.0, gpt-6.1-sol/high,
sandbox read-only, approvals_reviewer=auto_review; файлы не изменялись,
сеть и тесты ревьюеры не запускали. Отклонение cursor подтверждено обоими.
Новых принятых замечаний: 5A — 0, 5B сверх 5A — 0.
Схема БД после второго пакета не менялась; повторный rebuild итоговой миграции
завершён успешно, её guard/down/up/schema validation также прошли.

### 5A

Артефакт: `var/review/runs/20261101T084413.048132Z-03a420b3bf0d-codex-da_uy0o3/review.json`.

```json
{
  "status": "complete",
  "package_sha256": "03a420b3bf0db9b5aec91ddb4a78bb92025cf9772287835bf9abdefb3b263fae",
  "summary": "Полностью прочитан приложенный пакет: итоговый diff, правила, ADR, критерии приёмки и окружающий код. Нарушений записанных правил не обнаружено. Прежние исправления подтверждены. Общая текстовая граница отвергает U+0000; добавлены регрессии create/copy/add с проверкой отсутствия записей и изменения версии. Отклонение замечания о cursor обосновано приложенной установленной реализацией Symfony: Uuid::isValid по умолчанию использует FORMAT_RFC_9562 и не принимает base32/base58. Тесты и сборки не запускались, заявленные результаты независимо не воспроизводились. G10–G12 остаются открытыми до завершения обязательного ревью, финального CI и доставки.",
  "findings": [],
  "rule_gaps": [],
  "nitpicks": []
}
```

### 5B

Артефакт: `var/review/runs/20261101T084413.042562Z-03a420b3bf0d-defects-di6cgwf8/review.json`.

```json
{
  "status": "complete",
  "package_sha256": "03a420b3bf0db9b5aec91ddb4a78bb92025cf9772287835bf9abdefb3b263fae",
  "summary": "Пакет прочитан полностью. Проверены календарь, tenant scope, транзакции и аудит, конкурентные add/copy, миграция, guards, пагинация и API-контракт. Отклонение замечания о cursor подтверждено приложенной установленной реализацией Symfony: isValid по умолчанию отвергает base32/base58. Общая текстовая проверка запрещает U+0000; регрессии create/copy/add проверяют 422 без записи и смены версии. Новых дефектов не обнаружено. Заявленные результаты тестов и rebuild независимо не воспроизводились; G10–G12 обоснованно остаются открытыми до завершения ревью, итогового CI и доставки. Инструменты и сеть не использовались, файлы не изменялись.",
  "findings": [],
  "rule_gaps": [],
  "nitpicks": []
}
```

Итого: первый пакет — 5 уникальных принятых замечаний (4 в 5A, ещё 1 в 5B);
второй — ещё 1 принятое в 5B и 1 отклонённое с подтверждением; третий — 0.
Все шесть уникальных принятых исправлены. Код итогового head после третьего
пакета не менялся; документационное закрытие не требует нового ревью по порогу AGENTS.md.
Заключения сохранены здесь целиком, локальный var/review не считается архивом.

Дополнительная read-only проверка драйвера подтвердила обрезание NUL:
вход before\0after — 12 байтов, SELECT octet_length(CAST(:value AS text)) — 6.
Лог: var/product-research-ozon-stage2/null-driver-proof.txt.
