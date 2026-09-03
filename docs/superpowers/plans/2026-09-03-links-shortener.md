# Links Shortener Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Добавить в монолит короткие отслеживаемые ссылки на `lin.conwix.com` и админский экран с дневной статистикой за выбранный месяц.

**Architecture:** Новый модуль `Links` хранит редактируемый `ShortLink` как ORM Entity с optimistic locking, а append-only `ShortLinkClick` пишет и агрегирует через DBAL. Публичный host-bound controller всегда отдаёт найденную цель, даже если запись клика не удалась; админские правки атомарно пишутся в существующий аудит через узкий `IdentityAdminFacade`.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM/DBAL 4, PostgreSQL 16, React 19, TypeScript 5.9, TanStack Query 5, Traefik 3.5, nginx 1.27, PHPUnit 13, Vitest 4, Playwright 1.62.

**Spec:** `docs/superpowers/specs/2026-09-03-links-shortener-design.md`

## Global Constraints

- Новых Composer/npm-зависимостей, языков, кэшей, очередей и контейнеров не добавлять.
- Код ссылки — ровно семь случайных base62-символов `0-9A-Za-z`; unique-индекс в PostgreSQL — окончательная защита.
- Цель — только абсолютный `http`/`https` URL до 2048 символов без userinfo; название после `trim` — 1–120 символов.
- Публичный ответ — `302` и `Cache-Control: no-store`; отказ записи click не блокирует redirect.
- IP не сохранять; `User-Agent` ограничить 1024, `Referer` — 2048; статистика считает только `is_bot=false`.
- Списки читать DBAL в `readonly` DTO; не гидрировать Entity для экрана; лимит 50 по умолчанию и 200 максимум.
- Любая правка `ShortLink` принимает version; конфликт даёт `409` и не оставляет AuditRecord.
- Admin API доступен обеим ролям через `#[IsGranted('ROLE_ADMIN')]`; ни один слой Links не импортирует Entity из Identity.
- Все query keys в `apps/admin` строить через `adminQueryKey`; API response types брать только из сгенерированной OpenAPI-схемы.
- На lin-vhost в php-fpm проксировать только `^/[0-9A-Za-z]{7}$`; `/api/**` на этом хосте всегда даёт `404`.
- Rate limit для lin HTTPS-router: average 20 за `1s` на source IP, burst 50.
- Не применять production-миграцию, не менять DNS и не выкладывать код без отдельного указания человека.
- Рабочее дерево уже содержит несвязанные правки. Сохранять их побайтово, не включать в Links-коммиты и не склеивать с ними миграцию Links.
- В текущей среде `.git` read-only. На каждом commit-шаге попытаться закоммитить только перечисленные файлы; если блокировка сохранилась, зафиксировать точный commit message в отчёте и продолжить без staging чужих файлов.

## File Structure

### Backend domain and persistence

- `api/src/Links/Domain/ShortLink.php` — ORM Entity, invariants, versioned human edits.
- `api/src/Links/Domain/ShortLinkStatus.php` — `active|disabled`.
- `api/src/Links/Domain/ShortLinkClick.php` — append-only click Entity.
- `api/src/Links/Domain/ShortLinkRepository.php` — insert/load/flush contract.
- `api/src/Links/Domain/ShortLinkClickRepository.php` — append contract.
- `api/src/Links/Domain/ShortCodeGenerator.php` — randomness seam.
- `api/src/Links/Domain/BotDetector.php` — pure User-Agent classifier.
- `api/src/Links/Domain/ShortLinkAuditAction.php` — Links-owned audit action strings.
- `api/src/Links/Infrastructure/Persistence/DoctrineShortLinkRepository.php` — conflict-safe insert plus ORM edit persistence.
- `api/src/Links/Infrastructure/Persistence/DoctrineShortLinkClickWriter.php` — one DBAL insert per public request.
- `api/src/Links/Infrastructure/RandomShortCodeGenerator.php` — unbiased base62 generator.
- `api/src/Links/Infrastructure/Query/ActiveShortLinkQuery.php` and `RedirectTarget.php` — public code lookup.
- `api/src/Links/Infrastructure/Query/AllShortLinksForAdminQuery.php` and `AdminShortLinkRow.php` — paginated admin list.
- `api/src/Links/Infrastructure/Query/MonthlyClicksQuery.php` and `DailyClicksRow.php` — bounded daily aggregate.

### Backend use cases and HTTP

- `api/src/Links/Application/CreateShortLinkAction.php`, `ShortCodeGenerationFailed.php` — five-attempt creation.
- `api/src/Links/Application/UpdateShortLinkAction.php`, `ChangeShortLinkStatusAction.php`, `ShortLinkMutationOutcome.php`, `ShortLinkMutationResult.php` — versioned audited edits.
- `api/src/Links/Application/BuildMonthlyClicksAction.php`, `MonthPeriod.php`, `MonthlyClicks.php` — strict month handling and zero-day fill.
- `api/src/Identity/Application/Facade/IdentityAdminFacade.php` — the only Links→Identity boundary.
- `api/src/Links/Ui/AdminActorId.php` — converts the authenticated `UserInterface` identifier into admin UUID via the facade.
- `api/src/Links/Ui/Request/{CreateShortLinkRequest,UpdateShortLinkRequest,ChangeShortLinkStatusRequest}.php` — JSON trust boundary.
- `api/src/Links/Ui/Response/{ShortLinkResponse,ShortLinkListResponse,DailyClicksResponse,MonthlyClicksResponse}.php` — OpenAPI response DTOs.
- `api/src/Links/Ui/Controller/{Create,List,Update,ChangeStatus,ListMonthlyClicks,RedirectShortLink}Controller.php` — five admin routes and one public host-bound route.

### Frontend and infrastructure

- `apps/admin/src/features/links/model/*.ts` — TanStack queries/mutations and month helpers.
- `apps/admin/src/features/links/ui/*.tsx` — creation, link table/editing, month navigation and daily table.
- `apps/admin/src/app/{Root,Sidebar}.tsx` — `/links` route and navigation item.
- `apps/admin/tests/e2e/admin.spec.ts` — extend the single admin scenario.
- `api/migrations/Version20260903090000.php`, `api/config/packages/doctrine.yaml`, `api/config/services.yaml`, `api/deptrac.php` — schema, DI and module boundaries.
- `api/.env`, `api/.env.test`, `docker-compose.prod.yml` — `LINKS_PUBLIC_BASE_URL`.
- `traefik/{dynamic,dynamic.prod}.yml`, `docker/nginx/{default,prod}.conf`, `docker-compose.yml` — the third host and rate limit.
- `packages/api-schema/{openapi.json,src/schema.d.ts}` — generated contract artifacts.
- `docs/adr/0022-links-shortener-module.md`, `docs/adr/README.md`, `CLAUDE.md`, `docs/structure.md`, `docs/operations-checklist.md` — decision and repository/operations maps.

---

### Task 1: Record ADR-022 and build the domain model

**Files:**
- Create: `docs/adr/0022-links-shortener-module.md`
- Modify: `docs/adr/README.md`
- Create: `api/src/Links/Domain/ShortLinkStatus.php`
- Create: `api/src/Links/Domain/ShortLink.php`
- Create: `api/src/Links/Domain/ShortLinkClick.php`
- Create: `api/src/Links/Domain/ShortLinkRepository.php`
- Create: `api/src/Links/Domain/ShortLinkClickRepository.php`
- Create: `api/src/Links/Domain/ShortCodeGenerator.php`
- Create: `api/tests/Support/Builder/ShortLinkBuilder.php`
- Create: `api/tests/Support/Builder/ShortLinkClickBuilder.php`
- Test: `api/tests/Unit/Links/Domain/ShortLinkTest.php`

**Interfaces:**
- Produces: `ShortLink::create(string $code, string $name, string $targetUrl, Uuid $createdByAdminId, DateTimeImmutable $at): ShortLink`.
- Produces: `ShortLink::changeDetails(string $name, string $targetUrl, DateTimeImmutable $at): bool` and `changeStatus(ShortLinkStatus $status, DateTimeImmutable $at): bool`.
- Produces: `ShortLink` getters `id(): Uuid`, `code(): string`, `name(): string`, `targetUrl(): string`, `status(): ShortLinkStatus`, `version(): int`, `createdAt(): DateTimeImmutable`, `updatedAt(): DateTimeImmutable`.
- Produces: `ShortLinkClick::record(Uuid $shortLinkId, DateTimeImmutable $clickedAt, ?string $userAgent, ?string $referer, bool $isBot): ShortLinkClick` plus getters for those six persisted fields including its generated `id(): Uuid`.
- Produces: `ShortLinkRepository::tryAdd(ShortLink $link): bool`, `get(Uuid $id): ?ShortLink`, `save(): void`.
- Produces: `ShortLinkClickRepository::record(ShortLinkClick $click): void` and `ShortCodeGenerator::generate(): string`.

- [ ] **Step 1: Save the user's ADR under its real number**

Copy the supplied decision text into `docs/adr/0022-links-shortener-module.md`, change its heading to `ADR-022`, keep `Proposed`, and omit the trailing conversational command «выполни задачу». Add the registry row:

```markdown
| 022 | Сокращатель ссылок как модуль монолита | Proposed |
```

- [ ] **Step 2: Write the failing domain test**

```php
public function testHumanEditsAreExplicitAndNoOpsStayNoOps(): void
{
    $createdAt = new \DateTimeImmutable('2026-09-03 09:00:00');
    $link = ShortLink::create('0Ab9Zxy', 'September email', 'https://conwix.com/start', Uuid::v7(), $createdAt);

    self::assertSame(ShortLinkStatus::Active, $link->status());
    self::assertSame(1, $link->version());
    self::assertFalse($link->changeDetails('September email', 'https://conwix.com/start', $createdAt));
    self::assertTrue($link->changeDetails('September follow-up', 'https://conwix.com/follow-up', $createdAt->modify('+1 hour')));
    self::assertTrue($link->changeStatus(ShortLinkStatus::Disabled, $createdAt->modify('+2 hours')));
    self::assertFalse($link->changeStatus(ShortLinkStatus::Disabled, $createdAt->modify('+3 hours')));
}
```

- [ ] **Step 3: Run the focused unit test and observe the missing classes**

Run: `docker compose exec php-cli php bin/phpunit tests/Unit/Links/Domain/ShortLinkTest.php`

Expected: FAIL because `App\Links\Domain\ShortLink` does not exist.

- [ ] **Step 4: Implement both Entities and their contracts**

Use attribute mapping and non-final Entity classes. The essential state transitions must be:

```php
enum ShortLinkStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}

public static function create(string $code, string $name, string $targetUrl, Uuid $createdByAdminId, \DateTimeImmutable $at): self
{
    return new self(Uuid::v7(), $code, $name, $targetUrl, ShortLinkStatus::Active, 1, $createdByAdminId, $at, $at);
}

public function changeDetails(string $name, string $targetUrl, \DateTimeImmutable $at): bool
{
    if ($this->name === $name && $this->targetUrl === $targetUrl) {
        return false;
    }
    $this->name = $name;
    $this->targetUrl = $targetUrl;
    $this->updatedAt = $at;
    return true;
}

public function changeStatus(ShortLinkStatus $status, \DateTimeImmutable $at): bool
{
    if ($this->status === $status) {
        return false;
    }
    $this->status = $status;
    $this->updatedAt = $at;
    return true;
}
```

Mark `ShortLink::$version` with `#[ORM\Version]`. `ShortLinkClick::record(...)` generates UUIDv7 and has getters only; it never exposes a mutator. Builders clone on every `with*()` call and use fixed 2026 dates as defaults.

- [ ] **Step 5: Run the focused test**

Run: `docker compose exec php-cli php bin/phpunit tests/Unit/Links/Domain/ShortLinkTest.php`

Expected: PASS.

- [ ] **Step 6: Commit the decision and domain slice**

```bash
git add docs/adr/0022-links-shortener-module.md api/src/Links/Domain api/tests/Support/Builder/ShortLinkBuilder.php api/tests/Support/Builder/ShortLinkClickBuilder.php api/tests/Unit/Links/Domain/ShortLinkTest.php
git add -p docs/adr/README.md
git commit -m "Adds Links domain model"
```

### Task 2: Add the two-table schema and persistence

**Files:**
- Create: `api/migrations/Version20260903090000.php`
- Modify: `api/config/packages/doctrine.yaml`
- Create: `api/src/Links/Infrastructure/Persistence/DoctrineShortLinkRepository.php`
- Create: `api/src/Links/Infrastructure/Persistence/DoctrineShortLinkClickWriter.php`
- Test: `api/tests/Integration/Links/DoctrineShortLinkRepositoryTest.php`
- Test: `api/tests/Integration/Links/DoctrineShortLinkClickWriterTest.php`

**Interfaces:**
- Consumes: `ShortLinkRepository`, `ShortLinkClickRepository`, `ShortLink`, `ShortLinkClick` from Task 1.
- Produces: autowireable DBAL/ORM implementations; `tryAdd()` returns false only for a code collision.

- [ ] **Step 1: Write failing repository tests**

Cover a successful insert, `tryAdd()` returning false for the same code, loading by UUID, and one stored click:

```php
$first = ShortLinkBuilder::aShortLink()->withCode('AbC0123')->withCreatedByAdminId($administrator->id())->build();
self::assertTrue($repository->tryAdd($first));
self::assertFalse($repository->tryAdd(ShortLinkBuilder::aShortLink()->withCode('AbC0123')->withCreatedByAdminId($administrator->id())->build()));
self::assertSame('AbC0123', $repository->get($first->id())?->code());

$click = ShortLinkClickBuilder::aClick()->forLink($first)->asBot(false)->build();
$clicks->record($click);
self::assertSame('1', $connection->fetchOne('SELECT count(*) FROM short_link_click WHERE short_link_id = ?', [$first->id()->toRfc4122()]));
```

- [ ] **Step 2: Run the tests before the migration**

Run: `docker compose exec php-cli php bin/phpunit tests/Integration/Links/DoctrineShortLinkRepositoryTest.php tests/Integration/Links/DoctrineShortLinkClickWriterTest.php`

Expected: FAIL because the tables and services do not exist.

- [ ] **Step 3: Add the Links Doctrine mapping and migration**

Add mapping block `Links` pointing at `src/Links/Domain`. Create tables with explicit constraints and indexes:

```sql
CREATE TABLE short_link (
    id UUID NOT NULL,
    code VARCHAR(7) NOT NULL,
    name VARCHAR(120) NOT NULL,
    target_url VARCHAR(2048) NOT NULL,
    status VARCHAR(16) NOT NULL,
    version INT NOT NULL,
    created_by_admin_id UUID NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT chk_short_link_status CHECK (status IN ('active', 'disabled')),
    CONSTRAINT fk_short_link_created_by FOREIGN KEY (created_by_admin_id) REFERENCES administrator (id)
);
CREATE UNIQUE INDEX uq_short_link_code ON short_link (code);
CREATE INDEX idx_short_link_created ON short_link (created_at DESC, id DESC);
CREATE INDEX idx_short_link_created_by ON short_link (created_by_admin_id);

CREATE TABLE short_link_click (
    id UUID NOT NULL,
    short_link_id UUID NOT NULL,
    clicked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    user_agent VARCHAR(1024) DEFAULT NULL,
    referer VARCHAR(2048) DEFAULT NULL,
    is_bot BOOLEAN NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_short_link_click_link FOREIGN KEY (short_link_id) REFERENCES short_link (id)
);
CREATE INDEX idx_short_link_click_link_time ON short_link_click (short_link_id, clicked_at);
```

`down()` drops `short_link_click` before `short_link`.

- [ ] **Step 4: Implement conflict-safe persistence**

`DoctrineShortLinkRepository::tryAdd()` uses this statement and returns `$affected > 0`:

```sql
INSERT INTO short_link
    (id, code, name, target_url, status, version, created_by_admin_id, created_at, updated_at)
VALUES
    (:id, :code, :name, :targetUrl, :status, :version, :createdByAdminId, :createdAt, :updatedAt)
ON CONFLICT (code) DO NOTHING
```

`get()` uses an ORM query by `id`; `save()` calls a single EntityManager `flush()`. `DoctrineShortLinkClickWriter::record()` executes one plain `INSERT`, with no catch and no transaction of its own; only the public controller may suppress its failure.

- [ ] **Step 5: Apply the test migration and run the repository tests**

Run: `make api-migrate-test`

Run: `docker compose exec php-cli php bin/phpunit tests/Integration/Links/DoctrineShortLinkRepositoryTest.php tests/Integration/Links/DoctrineShortLinkClickWriterTest.php`

Expected: PASS.

- [ ] **Step 6: Commit schema and persistence**

```bash
git add api/migrations/Version20260903090000.php api/config/packages/doctrine.yaml api/src/Links/Infrastructure/Persistence api/tests/Integration/Links/DoctrineShortLinkRepositoryTest.php api/tests/Integration/Links/DoctrineShortLinkClickWriterTest.php
git commit -m "Stores short links and raw clicks"
```

### Task 3: Generate collision-safe codes and create links

**Files:**
- Create: `api/src/Links/Infrastructure/RandomShortCodeGenerator.php`
- Create: `api/src/Links/Application/CreateShortLinkAction.php`
- Create: `api/src/Links/Application/ShortCodeGenerationFailed.php`
- Test: `api/tests/Unit/Links/Infrastructure/RandomShortCodeGeneratorTest.php`
- Test: `api/tests/Integration/Links/CreateShortLinkActionTest.php`

**Interfaces:**
- Consumes: `ShortCodeGenerator::generate(): string`, `ShortLinkRepository::tryAdd()`.
- Produces: `CreateShortLinkAction::__invoke(string $name, string $targetUrl, string $actorAdminId): ShortLink`.

- [ ] **Step 1: Write failing generator and collision tests**

```php
public function testGeneratesExactlySevenBase62Characters(): void
{
    $generator = new RandomShortCodeGenerator();
    for ($i = 0; $i < 100; ++$i) {
        self::assertMatchesRegularExpression('/^[0-9A-Za-z]{7}$/', $generator->generate());
    }
}
```

In the integration test, pass an in-test `ShortCodeGenerator` yielding `Taken01`, `Taken02`, `Fresh03`; preinsert the first two and assert the action stores `Fresh03`. A second generator yielding five occupied codes must raise `ShortCodeGenerationFailed` after exactly five calls.

- [ ] **Step 2: Run focused tests**

Run: `docker compose exec php-cli php bin/phpunit tests/Unit/Links/Infrastructure/RandomShortCodeGeneratorTest.php tests/Integration/Links/CreateShortLinkActionTest.php`

Expected: FAIL because the generator/action do not exist.

- [ ] **Step 3: Implement unbiased base62 and bounded retry**

```php
final class RandomShortCodeGenerator implements ShortCodeGenerator
{
    private const string ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function generate(): string
    {
        $code = '';
        while (7 > strlen($code)) {
            foreach (unpack('C*', random_bytes(7)) ?: [] as $byte) {
                if ($byte >= 248) {
                    continue;
                }
                $code .= self::ALPHABET[$byte % 62];
                if (7 === strlen($code)) {
                    break;
                }
            }
        }
        return $code;
    }
}
```

```php
public function __invoke(string $name, string $targetUrl, string $actorAdminId): ShortLink
{
    for ($attempt = 1; $attempt <= 5; ++$attempt) {
        $link = ShortLink::create($this->codes->generate(), $name, $targetUrl, Uuid::fromString($actorAdminId), new \DateTimeImmutable());
        if ($this->links->tryAdd($link)) {
            return $link;
        }
    }
    throw new ShortCodeGenerationFailed();
}
```

- [ ] **Step 4: Run focused tests**

Run: `docker compose exec php-cli php bin/phpunit tests/Unit/Links/Infrastructure/RandomShortCodeGeneratorTest.php tests/Integration/Links/CreateShortLinkActionTest.php`

Expected: PASS.

- [ ] **Step 5: Commit creation use case**

```bash
git add api/src/Links/Application api/src/Links/Infrastructure/RandomShortCodeGenerator.php api/tests/Unit/Links/Infrastructure/RandomShortCodeGeneratorTest.php api/tests/Integration/Links/CreateShortLinkActionTest.php
git commit -m "Creates unique short link codes"
```

### Task 4: Add the narrow admin identity facade and audited edits

**Files:**
- Create: `api/src/Identity/Application/Facade/IdentityAdminFacade.php`
- Create: `api/src/Links/Domain/ShortLinkAuditAction.php`
- Create: `api/src/Links/Application/ShortLinkMutationOutcome.php`
- Create: `api/src/Links/Application/ShortLinkMutationResult.php`
- Create: `api/src/Links/Application/UpdateShortLinkAction.php`
- Create: `api/src/Links/Application/ChangeShortLinkStatusAction.php`
- Modify: `api/deptrac.php`
- Test: `api/tests/Integration/Links/ShortLinkEditingTest.php`

**Interfaces:**
- Produces: `IdentityAdminFacade::administratorId(string $identifier): string`.
- Produces: `IdentityAdminFacade::recordAuditEntry(string $actorAdminId, string $action, string $subjectId, ?string $previousValue, ?string $newValue, DateTimeImmutable $occurredAt): void`.
- Produces: both edit actions return `ShortLinkMutationResult` with outcome `Saved|Unchanged|NotFound|VersionConflict` and nullable `ShortLink`.

- [ ] **Step 1: Write failing integration tests for edits**

Cover all of these assertions in `ShortLinkEditingTest`:

```php
self::assertSame(ShortLinkMutationOutcome::Saved, $update($id, 'New name', 'https://conwix.com/new', 1, $adminId)->outcome);
self::assertSame('2', $connection->fetchOne('SELECT version FROM short_link WHERE id = ?', [$id]));
self::assertSame('short_link.details_changed', $connection->fetchOne('SELECT action FROM audit_record WHERE subject_id = ?', [$id]));
self::assertSame(ShortLinkMutationOutcome::VersionConflict, $update($id, 'Stale', 'https://conwix.com/stale', 1, $adminId)->outcome);
self::assertSame('1', $connection->fetchOne('SELECT count(*) FROM audit_record WHERE subject_id = ?', [$id]));
```

Also assert: same current values return `Unchanged` without version/audit; disable and re-enable each write exactly one status audit; unknown UUID returns `NotFound`.

- [ ] **Step 2: Run the editing integration test**

Run: `docker compose exec php-cli php bin/phpunit tests/Integration/Links/ShortLinkEditingTest.php`

Expected: FAIL because the facade/actions do not exist.

- [ ] **Step 3: Implement the single Identity boundary**

```php
final readonly class IdentityAdminFacade
{
    public function __construct(private AdministratorRepository $administrators, private AuditRecordRepository $auditRecords) {}

    public function administratorId(string $identifier): string
    {
        $administrator = $this->administrators->findByEmail($identifier);
        if (null === $administrator) {
            throw new \LogicException('Authenticated administrator is no longer available.');
        }
        return $administrator->id()->toRfc4122();
    }

    public function recordAuditEntry(string $actorAdminId, string $action, string $subjectId, ?string $previousValue, ?string $newValue, \DateTimeImmutable $occurredAt): void
    {
        $this->auditRecords->addToUnitOfWork(AuditRecord::recordByAdmin(null, Uuid::fromString($actorAdminId), $action, Uuid::fromString($subjectId), $previousValue, $newValue, $occurredAt));
    }
}
```

- [ ] **Step 4: Implement result types and audited actions**

In each action: load, compare version before changing anything, return `VersionConflict` on mismatch, return `Unchanged` before queuing audit, queue deterministic old/new values, then call `save()` once. Catch `OptimisticLockException` and return `VersionConflict`.

```php
if ($link->version() !== $expectedVersion) {
    return new ShortLinkMutationResult(ShortLinkMutationOutcome::VersionConflict, null);
}
$previous = json_encode(['name' => $link->name(), 'targetUrl' => $link->targetUrl()], JSON_THROW_ON_ERROR);
if (!$link->changeDetails($name, $targetUrl, $at)) {
    return new ShortLinkMutationResult(ShortLinkMutationOutcome::Unchanged, $link);
}
$this->identity->recordAuditEntry($actorAdminId, ShortLinkAuditAction::DetailsChanged, $link->id()->toRfc4122(), $previous, json_encode(['name' => $name, 'targetUrl' => $targetUrl], JSON_THROW_ON_ERROR), $at);
try {
    $this->links->save();
} catch (OptimisticLockException) {
    return new ShortLinkMutationResult(ShortLinkMutationOutcome::VersionConflict, null);
}
return new ShortLinkMutationResult(ShortLinkMutationOutcome::Saved, $link);
```

- [ ] **Step 5: Encode the boundary in Deptrac**

Exclude `IdentityAdminFacade` from broad `IdentityFacade`; add an `IdentityAdminFacade` class layer. Add four Links layers. Permit `LinksUi` and `LinksApplication` to access only the narrow admin facade, and do not grant any Links layer direct access to `IdentityDomain` or broad `IdentityApplication`.

- [ ] **Step 6: Run editing and architecture checks**

Run: `docker compose exec php-cli php bin/phpunit tests/Integration/Links/ShortLinkEditingTest.php`

Run: `make deptrac`

Expected: both PASS.

- [ ] **Step 7: Commit audited editing**

```bash
git add api/src/Identity/Application/Facade/IdentityAdminFacade.php api/src/Links/Application api/src/Links/Domain/ShortLinkAuditAction.php api/deptrac.php api/tests/Integration/Links/ShortLinkEditingTest.php
git commit -m "Audits concurrent short link edits"
```

### Task 5: Resolve public links and record clicks without blocking redirect

**Files:**
- Create: `api/src/Links/Domain/BotDetector.php`
- Create: `api/src/Links/Infrastructure/Query/RedirectTarget.php`
- Create: `api/src/Links/Infrastructure/Query/ActiveShortLinkQuery.php`
- Create: `api/src/Links/Ui/Controller/RedirectShortLinkController.php`
- Test: `api/tests/Unit/Links/Domain/BotDetectorTest.php`
- Test: `api/tests/Functional/Links/RedirectShortLinkControllerTest.php`

**Interfaces:**
- Produces: `BotDetector::isBot(?string $userAgent): bool`.
- Produces: `ActiveShortLinkQuery::find(string $code): ?RedirectTarget`.
- Produces: `RedirectTarget` with readonly `id: string`, `code: string`, and `targetUrl: string`.
- Produces: host-bound `GET /{code}`.

- [ ] **Step 1: Write failing bot detector tests**

```php
#[DataProvider('agents')]
public function testClassifiesAgents(?string $agent, bool $expected): void
{
    self::assertSame($expected, (new BotDetector())->isBot($agent));
}

public static function agents(): iterable
{
    yield 'missing' => [null, true];
    yield 'crawler' => ['Googlebot/2.1', true];
    yield 'mail scanner' => ['Proofpoint URL Defense', true];
    yield 'cli' => ['curl/8.10.1', true];
    yield 'browser' => ['Mozilla/5.0 Chrome/130.0 Safari/537.36', false];
}
```

- [ ] **Step 2: Write failing functional redirect tests**

Create an active link through the builder/repository. Request with `HTTP_HOST=lin.conwix.localhost` and assert `302`, exact `Location`, `Cache-Control` containing `no-store`, and a stored nonbot click. Assert the same code on `admin.conwix.localhost` is `404`; disabled and unknown codes are `404`.

Replace `ShortLinkClickRepository::class` in the test container with an implementation whose `record()` throws `RuntimeException`; assert the response remains `302`.

- [ ] **Step 3: Run focused tests**

Run: `docker compose exec php-cli php bin/phpunit tests/Unit/Links/Domain/BotDetectorTest.php tests/Functional/Links/RedirectShortLinkControllerTest.php`

Expected: FAIL because classifier/query/controller do not exist.

- [ ] **Step 4: Implement classifier and active lookup**

Lowercase the User-Agent and classify it as bot when blank or containing one of:

```php
private const array BOT_MARKERS = [
    'bot', 'crawler', 'spider', 'slurp', 'preview', 'scanner', 'headless',
    'proofpoint', 'barracuda', 'safelinks', 'mimecast', 'curl/', 'wget/',
    'python-requests', 'go-http-client', 'okhttp', 'libwww-perl', 'java/',
];
```

`ActiveShortLinkQuery` selects only `id`, `code`, `target_url` with `WHERE code = :code AND status = 'active'` and `setMaxResults(1)`.

Bind the controller route to the three Links hosts at Symfony level as well as nginx:

```php
#[Route(
    '/{code}',
    name: 'links_redirect',
    host: 'lin.conwix.{suffix}',
    requirements: ['code' => '[0-9A-Za-z]{7}', 'suffix' => 'com|localhost|internal'],
    methods: ['GET'],
)]
```

- [ ] **Step 5: Implement the fail-open click boundary**

```php
$target = $this->links->find($code);
if (null === $target) {
    throw new NotFoundHttpException();
}
$userAgent = $this->header($request->headers->get('User-Agent'), 1024);
$referer = $this->header($request->headers->get('Referer'), 2048);
try {
    $this->clicks->record(ShortLinkClick::record(Uuid::fromString($target->id), new \DateTimeImmutable(), $userAgent, $referer, $this->bots->isBot($userAgent)));
} catch (\Throwable $failure) {
    $this->logger->warning('Short link click was not recorded.', ['link_id' => $target->id, 'code' => $target->code, 'exception' => $failure::class]);
}
$response = new RedirectResponse($target->targetUrl, Response::HTTP_FOUND);
$response->headers->set('Cache-Control', 'no-store');
return $response;
```

The private `header()` trims, maps empty to null, and uses `mb_substr($value, 0, $limit)`.
The existing `Shared\Ui\RequestContextProcessor` adds `request_id` to every log record, so do not duplicate it in this warning context.

- [ ] **Step 6: Run focused tests**

Run: `docker compose exec php-cli php bin/phpunit tests/Unit/Links/Domain/BotDetectorTest.php tests/Functional/Links/RedirectShortLinkControllerTest.php`

Expected: PASS.

- [ ] **Step 7: Commit public redirect**

```bash
git add api/src/Links/Domain/BotDetector.php api/src/Links/Infrastructure/Query/ActiveShortLinkQuery.php api/src/Links/Infrastructure/Query/RedirectTarget.php api/src/Links/Ui/Controller/RedirectShortLinkController.php api/tests/Unit/Links/Domain/BotDetectorTest.php api/tests/Functional/Links/RedirectShortLinkControllerTest.php
git commit -m "Redirects and records short link clicks"
```

### Task 6: Add bounded list and monthly statistics queries

**Files:**
- Create: `api/src/Links/Infrastructure/Query/AdminShortLinkRow.php`
- Create: `api/src/Links/Infrastructure/Query/AllShortLinksForAdminQuery.php`
- Create: `api/src/Links/Infrastructure/Query/DailyClicksRow.php`
- Create: `api/src/Links/Infrastructure/Query/MonthlyClicksQuery.php`
- Create: `api/src/Links/Application/MonthPeriod.php`
- Create: `api/src/Links/Application/MonthlyClicks.php`
- Create: `api/src/Links/Application/BuildMonthlyClicksAction.php`
- Test: `api/tests/Unit/Links/Application/MonthPeriodTest.php`
- Test: `api/tests/Integration/Links/LinksReadQueriesTest.php`

**Interfaces:**
- Produces: `AllShortLinksForAdminQuery::build(): QueryBuilder`, `countAll(): int`, constants `DEFAULT_LIMIT=50`, `MAX_LIMIT=200`.
- Produces: `AllShortLinksForAdminQuery::mapRow(array $row): AdminShortLinkRow`; the row has `id, code, name, targetUrl, status, version, createdAt, updatedAt` as typed public readonly properties.
- Produces: `MonthlyClicksQuery::linkExists(string $linkId): bool` and `fetch(string $linkId, DateTimeImmutable $start, DateTimeImmutable $endExclusive): list<DailyClicksRow>`; each row exposes `date: string` and `clicks: int`.
- Produces: `MonthPeriod::fromString(string $month, DateTimeImmutable $now): MonthPeriod` with `start`, `endExclusive`, `lastIncludedDay`, `value`.
- Produces: `BuildMonthlyClicksAction::__invoke(string $linkId, string $month, DateTimeImmutable $now): ?MonthlyClicks`; null means unknown link. `MonthlyClicks` exposes `linkId: string`, `month: string`, and `items: list<array{date: string, clicks: int}>`. The controller supplies current UTC time; tests supply a fixed instant.

- [ ] **Step 1: Write failing month tests**

```php
$current = MonthPeriod::fromString('2026-09', new \DateTimeImmutable('2026-09-03 12:00:00 UTC'));
self::assertSame('2026-09-01', $current->start->format('Y-m-d'));
self::assertSame('2026-09-03', $current->lastIncludedDay->format('Y-m-d'));

$past = MonthPeriod::fromString('2026-02', new \DateTimeImmutable('2026-09-03 12:00:00 UTC'));
self::assertSame('2026-03-01', $past->endExclusive->format('Y-m-d'));
self::assertSame('2026-02-28', $past->lastIncludedDay->format('Y-m-d'));
```

Assert invalid shape and future month throw `InvalidArgumentException('month_invalid')` and `InvalidArgumentException('month_in_future')` respectively.

- [ ] **Step 2: Write failing DBAL query tests**

Insert two links and clicks on two dates, including a bot. Assert list ordering/pagination, and assert the monthly result contains every date from month start to `now`, zeros included, with bot clicks excluded.

- [ ] **Step 3: Run focused tests**

Run: `docker compose exec php-cli php bin/phpunit tests/Unit/Links/Application/MonthPeriodTest.php tests/Integration/Links/LinksReadQueriesTest.php`

Expected: FAIL because query/action classes do not exist.

- [ ] **Step 4: Implement list and aggregate SQL**

The list query selects only response fields and orders by `created_at DESC, id DESC`. The aggregate query remains bounded by a link and half-open interval:

```sql
SELECT CAST(clicked_at AS DATE) AS day, COUNT(*) AS clicks
FROM short_link_click
WHERE short_link_id = :linkId
  AND clicked_at >= :start
  AND clicked_at < :endExclusive
  AND is_bot = FALSE
GROUP BY CAST(clicked_at AS DATE)
ORDER BY day ASC
```

Add `MonthlyClicksQuery::linkExists(string $linkId): bool` using `SELECT EXISTS(...)`. `BuildMonthlyClicksAction` passes its explicit `$now` to `MonthPeriod::fromString()`, maps returned rows by date and fills the at-most-31-day range with zeroes in PHP.

- [ ] **Step 5: Run focused tests**

Run: `docker compose exec php-cli php bin/phpunit tests/Unit/Links/Application/MonthPeriodTest.php tests/Integration/Links/LinksReadQueriesTest.php`

Expected: PASS.

- [ ] **Step 6: Commit read models**

```bash
git add api/src/Links/Application/BuildMonthlyClicksAction.php api/src/Links/Application/MonthPeriod.php api/src/Links/Application/MonthlyClicks.php api/src/Links/Infrastructure/Query api/tests/Unit/Links/Application/MonthPeriodTest.php api/tests/Integration/Links/LinksReadQueriesTest.php
git commit -m "Reports daily short link clicks"
```

### Task 7: Expose the authenticated admin API and OpenAPI contract

**Files:**
- Modify: `api/.env`
- Modify: `api/.env.test`
- Modify: `api/config/services.yaml`
- Modify: `docker-compose.prod.yml`
- Create: `api/src/Links/Ui/AdminActorId.php`
- Create: `api/src/Links/Ui/Request/CreateShortLinkRequest.php`
- Create: `api/src/Links/Ui/Request/UpdateShortLinkRequest.php`
- Create: `api/src/Links/Ui/Request/ChangeShortLinkStatusRequest.php`
- Create: `api/src/Links/Ui/Response/ShortLinkResponse.php`
- Create: `api/src/Links/Ui/Response/ShortLinkListResponse.php`
- Create: `api/src/Links/Ui/Response/DailyClicksResponse.php`
- Create: `api/src/Links/Ui/Response/MonthlyClicksResponse.php`
- Create: `api/src/Links/Ui/Controller/CreateShortLinkController.php`
- Create: `api/src/Links/Ui/Controller/ListShortLinksController.php`
- Create: `api/src/Links/Ui/Controller/UpdateShortLinkController.php`
- Create: `api/src/Links/Ui/Controller/ChangeShortLinkStatusController.php`
- Create: `api/src/Links/Ui/Controller/ListMonthlyClicksController.php`
- Test: `api/tests/Unit/Links/Ui/Request/ShortLinkRequestTest.php`
- Test: `api/tests/Functional/Links/AdminLinksControllerTest.php`
- Modify generated: `packages/api-schema/openapi.json`
- Modify generated: `packages/api-schema/src/schema.d.ts`

**Interfaces:**
- Produces: the five `/api/admin/links` routes from the spec.
- Produces: `ShortLinkResponse` fields `id, code, shortUrl, name, targetUrl, status, version, createdAt, updatedAt`.
- Produces: `MonthlyClicksResponse` fields `linkId, month, items[{date, clicks}]`.

- [ ] **Step 1: Write failing request parsing tests**

Test valid trim plus every trust-boundary rejection. Representative assertions:

```php
self::assertSame('Campaign', CreateShortLinkRequest::fromJson('{"name":" Campaign ","targetUrl":"https://conwix.com/a"}')->name);

foreach (['ftp://conwix.com/a', '/relative', 'https://user:secret@conwix.com/a'] as $url) {
    try {
        CreateShortLinkRequest::fromJson(json_encode(['name' => 'Campaign', 'targetUrl' => $url], JSON_THROW_ON_ERROR));
        self::fail('Invalid URL was accepted.');
    } catch (\InvalidArgumentException $error) {
        self::assertSame('target_url_invalid', $error->getMessage());
    }
}
```

Also reject malformed JSON, blank/121-char name, missing/nonpositive version, and status outside `active|disabled`.

- [ ] **Step 2: Write failing functional API tests**

Use the existing admin login/builders. Cover: unauthenticated `401`; create `201`; list pagination and response fields; update; stale update `409`; disable/enable; current and past month; future month `422`; unknown link `404`; five forced collisions `503` by replacing `ShortCodeGenerator`.

- [ ] **Step 3: Run focused tests**

Run: `docker compose exec php-cli php bin/phpunit tests/Unit/Links/Ui/Request/ShortLinkRequestTest.php tests/Functional/Links/AdminLinksControllerTest.php`

Expected: FAIL because request DTOs and controllers do not exist.

- [ ] **Step 4: Implement trust-boundary DTOs and actor adapter**

Centralize name/URL checks in `CreateShortLinkRequest`; `UpdateShortLinkRequest` reuses static normalization methods and adds version parsing. URL validation must require `FILTER_VALIDATE_URL`, `http|https`, nonempty host, and null user/pass from `parse_url`.

```php
public function __invoke(): string
{
    $user = $this->security->getUser();
    if (null === $user) {
        throw new \LogicException('ROLE_ADMIN route has no authenticated user.');
    }
    return $this->identity->administratorId($user->getUserIdentifier());
}
```

- [ ] **Step 5: Implement response mapping and controllers**

Bind `string $linksPublicBaseUrl: '%env(LINKS_PUBLIC_BASE_URL)%'` in services. Set dev/test values to `http://lin.conwix.localhost`, production common env to `https://lin.conwix.com`. Build short URLs with `rtrim($base, '/').'/'.$code`.

Map mutation outcomes exactly: `Saved|Unchanged` → 200 full response, `NotFound` → 404 `link_not_found`, `VersionConflict` → 409 `version_conflict`. Map invalid requests/month/page/limit to 422. Add Nelmio/OpenAPI attributes for every response.

The monthly controller calls the action with `new \DateTimeImmutable('now', new \DateTimeZone('UTC'))`; the action itself never reads the system clock.

- [ ] **Step 6: Run focused tests and regenerate the contract**

Run: `docker compose exec php-cli php bin/phpunit tests/Unit/Links/Ui/Request/ShortLinkRequestTest.php tests/Functional/Links/AdminLinksControllerTest.php`

Run: `make api-doc-export api-types api-types-check`

Expected: tests PASS and `api-types-check` reports no diff.

- [ ] **Step 7: Commit API contract**

```bash
git add api/.env api/.env.test api/config/services.yaml docker-compose.prod.yml api/src/Links/Ui api/tests/Unit/Links/Ui/Request/ShortLinkRequestTest.php api/tests/Functional/Links/AdminLinksControllerTest.php
git add -p packages/api-schema/openapi.json packages/api-schema/src/schema.d.ts
git commit -m "Exposes short links to administrators"
```

### Task 8: Build typed frontend data hooks and month helpers

**Files:**
- Create: `apps/admin/src/features/links/model/month.ts`
- Create: `apps/admin/src/features/links/model/month.test.ts`
- Create: `apps/admin/src/features/links/model/useLinks.ts`
- Create: `apps/admin/src/features/links/model/useCreateLink.ts`
- Create: `apps/admin/src/features/links/model/useUpdateLink.ts`
- Create: `apps/admin/src/features/links/model/useSetLinkStatus.ts`
- Create: `apps/admin/src/features/links/model/useMonthlyClicks.ts`

**Interfaces:**
- Produces: `currentUtcMonth(now?: Date): string`, `shiftMonth(month: string, delta: -1|1): string`, `isCurrentMonth(month: string, now?: Date): boolean`, `formatMonthLabel(month: string): string`.
- Produces: typed TanStack hooks backed only by generated `components` types.

- [ ] **Step 1: Write failing month helper tests**

```ts
expect(currentUtcMonth(new Date('2026-09-03T23:00:00Z'))).toBe('2026-09')
expect(shiftMonth('2026-01', -1)).toBe('2025-12')
expect(shiftMonth('2026-12', 1)).toBe('2027-01')
expect(isCurrentMonth('2026-09', new Date('2026-09-03T00:00:00Z'))).toBe(true)
expect(formatMonthLabel('2026-09')).toBe('сентябрь 2026')
```

- [ ] **Step 2: Run the focused frontend unit test**

Run: `make front-test APP=admin`

Expected: FAIL because `month.ts` does not exist.

- [ ] **Step 3: Implement helpers with UTC arithmetic**

Parse `${month}-01T00:00:00Z`, use `setUTCMonth`, and format the wire value with `getUTCFullYear()` and `getUTCMonth()`. Build the label without locale-dependent `г.` by formatting only the month and appending the numeric year:

```ts
const date = new Date(`${month}-01T00:00:00Z`)
const name = new Intl.DateTimeFormat('ru-RU', {
  month: 'long',
  timeZone: 'UTC',
}).format(date)
return `${name} ${String(date.getUTCFullYear())}`
```

- [ ] **Step 4: Implement typed query and mutation hooks**

Use these exact cache keys:

```ts
adminQueryKey('links', { page })
adminQueryKey('links', 'clicks', linkId, month)
```

Map hooks to the contract without a second client abstraction: `useLinks(page)` → `GET /api/admin/links?page=...`; `useCreateLink()` → `POST /api/admin/links`; `useUpdateLink(id)` → `POST /api/admin/links/{id}`; `useSetLinkStatus(id)` → `POST /api/admin/links/{id}/status`; `useMonthlyClicks(linkId, month)` → `GET /api/admin/links/{id}/clicks?month=...`. Request bodies use `name`, `targetUrl`, `version`, and `status` exactly as declared in Task 7.

Mutations call `apiPost` and invalidate the broad prefix `adminQueryKey('links')`, which covers both list and click queries; do not build a literal key array. `useMonthlyClicks` sets `enabled: linkId !== null` and URL-encodes both id and month.

- [ ] **Step 5: Run admin frontend checks**

Run: `make front-typecheck front-lint front-test front-knip APP=admin`

Expected: PASS.

- [ ] **Step 6: Commit frontend model layer**

```bash
git add apps/admin/src/features/links/model
git commit -m "Adds short link frontend data model"
```

### Task 9: Build the admin Links screen

**Files:**
- Create: `apps/admin/src/features/links/ui/CreateLinkForm.tsx`
- Create: `apps/admin/src/features/links/ui/EditLinkForm.tsx`
- Create: `apps/admin/src/features/links/ui/LinksTable.tsx`
- Create: `apps/admin/src/features/links/ui/MonthlyClicksTable.tsx`
- Create: `apps/admin/src/features/links/ui/LinksPage.tsx`
- Modify: `apps/admin/src/app/Root.tsx`
- Modify: `apps/admin/src/app/Sidebar.tsx`
- Modify: `apps/admin/tests/e2e/admin.spec.ts`

**Interfaces:**
- Consumes: Task 8 hooks and helpers.
- Produces: protected `/links` route, Sidebar item `Ссылки`, full MVP screen.

- [ ] **Step 1: Extend the existing admin scenario and observe the missing screen**

Inside the existing `SuperAdmin входит и заводит Admin` test, after account status is restored and before opening administrators, add one Links flow: open «Ссылки», create `E2E Campaign ${stamp}`, select it, assert today's UTC date exists with zero clicks, edit its name, disable it, and enable it again. Keep this inside the existing test; do not add a second Playwright test.

Run: `make front-build APP=admin && make test-e2e`

Expected: FAIL because the «Ссылки» navigation item and screen do not exist.

- [ ] **Step 2: Add the route and navigation shell**

```tsx
{ path: '/links', element: <LinksPage /> }
```

Add a `NavLink` using `Link2` from `lucide-react`, the same `ITEM/ITEM_ACTIVE/ITEM_IDLE` constants, and label `Ссылки` for both roles.

- [ ] **Step 3: Implement the creation and editing forms**

Use `react-hook-form`, `Input`, `Button`, and visible `role="alert"`/`role="status"` feedback. Creation resets on success and calls `onCreated(link.id)`. Editing initializes from the selected generated response type, submits the exact current `version`, and on `ApiError.status === 409` keeps typed values visible while the hook invalidates/refetches the list.

- [ ] **Step 4: Implement link selection, copy, status, and month table**

`LinksPage` owns `page`, `selectedLinkId`, and `month`. Selection defaults to the first loaded item only when no current selection exists; successful creation selects the new id. Copy uses `navigator.clipboard.writeText(link.shortUrl)` from a click handler. `MonthlyClicksTable` renders every API item without calculating missing days; right arrow is disabled when `isCurrentMonth(month)`.

Use a button rather than an anchor to select a link for statistics, preserving keyboard accessibility. Status mutations pass the row's current version. Display all pending/error/empty states and mutation failures explicitly.

- [ ] **Step 5: Run admin frontend checks and the focused E2E flow**

Run: `make front-typecheck front-lint front-test front-knip front-build APP=admin`

Run: `make test-e2e`

Expected: both PASS.

- [ ] **Step 6: Commit the Links screen**

```bash
git add apps/admin/src/features/links/ui apps/admin/src/app/Root.tsx apps/admin/src/app/Sidebar.tsx apps/admin/tests/e2e/admin.spec.ts
git commit -m "Adds short links admin dashboard"
```

### Task 10: Add the third Traefik/nginx host and prove host isolation

**Files:**
- Modify: `traefik/dynamic.yml`
- Modify: `traefik/dynamic.prod.yml`
- Modify: `docker/nginx/default.conf`
- Modify: `docker/nginx/prod.conf`
- Modify: `docker-compose.yml`
- Modify: `apps/admin/tests/e2e/admin.spec.ts`

**Interfaces:**
- Consumes: public host-bound controller from Task 5.
- Produces: dev `lin.conwix.localhost|internal`, production `lin.conwix.com`, rate limit 20/s burst 50.

- [ ] **Step 1: Add the public click assertion and observe the missing host**

Extend only the Links portion added to the existing Playwright scenario. Extract the seven-character code from the row, record a click without following the external redirect, then assert today's row changes from `0` to `1`:

```ts
const response = await page.request.get(`http://lin.conwix.internal/${code}`, {
  headers: { 'User-Agent': 'Mozilla/5.0 Chrome/130.0 Safari/537.36' },
  maxRedirects: 0,
})
expect(response.status()).toBe(302)
```

Run: `make test-e2e`

Expected: FAIL because `lin.conwix.internal` has no Docker alias/nginx vhost yet.

- [ ] **Step 2: Add dev and production routers**

Dev router:

```yaml
links:
  rule: "Host(`lin.conwix.localhost`)"
  entryPoints: ["web"]
  middlewares: ["links-rate-limit"]
  service: nginx

links-rate-limit:
  rateLimit:
    average: 20
    period: 1s
    burst: 50
```

Production adds `links-insecure` with `to-https` and secure `links` with the same middleware and `certResolver: letsencrypt`. Add `lin.conwix.internal` to the nginx network aliases in dev compose.

- [ ] **Step 3: Add a locked-down nginx vhost in both environments**

Use an exact regex location and named FastCGI location; everything else is 404:

```nginx
server {
    listen 80;
    server_name lin.conwix.localhost lin.conwix.internal;

    location ~ ^/[0-9A-Za-z]{7}$ {
        try_files /__links_front_controller__ @links_api;
    }

    location / {
        return 404;
    }

    location @links_api {
        root /var/www/html/public;
        set $upstream php-fpm:9000;
        fastcgi_pass $upstream;
        fastcgi_connect_timeout 3s;
        fastcgi_read_timeout 5s;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param DOCUMENT_ROOT $document_root;
    }
}
```

Production uses `server_name lin.conwix.com`, upstream `api:9000`, and the existing HTTPS FastCGI params. Keep the user's unrelated `fastcgi_read_timeout` edits in the existing app/admin vhosts intact.

- [ ] **Step 4: Validate compose, nginx, and host behavior**

Run: `docker compose config --quiet`

Run: `docker compose exec nginx nginx -t`

Run: `curl -sS -o /dev/null -w '%{http_code}\n' -H 'Host: lin.conwix.localhost' http://127.0.0.1/api/admin/ping`

Expected: both config commands exit 0 and the cross-host admin request prints HTTP status `404`.

Run: `docker compose exec php-cli php bin/phpunit tests/Functional/Links/RedirectShortLinkControllerTest.php`

Expected: PASS.

- [ ] **Step 5: Run the completed single Playwright scenario**

Run: `make test-e2e`

Expected: PASS.

- [ ] **Step 6: Commit host routing and the E2E proof**

```bash
git add traefik/dynamic.yml traefik/dynamic.prod.yml docker/nginx/prod.conf docker-compose.yml apps/admin/tests/e2e/admin.spec.ts
git add -p docker/nginx/default.conf
git commit -m "Routes lin.conwix.com through the monolith"
```

### Task 11: Update living documentation, verify from a clean database, and review

**Files:**
- Modify: `CLAUDE.md`
- Modify: `docs/structure.md`
- Modify: `docs/operations-checklist.md`
- Modify: `docs/adr/0022-links-shortener-module.md`
- Modify: `docs/adr/README.md`
- Verify: every Links/backend/frontend/infrastructure file from Tasks 1–10

**Interfaces:**
- Consumes: the complete feature.
- Produces: accepted ADR, updated repository map, operational rollout checklist, green full checks.

- [ ] **Step 1: Update living documentation while ADR remains Proposed**

Add Links to the module lists and dependency diagram (`Links → Identity → Shared`), add the lin host and `LINKS_PUBLIC_BASE_URL` to structure/network docs, and add these operations checkboxes:

```markdown
- [ ] DNS `A/AAAA` для `lin.conwix.com` указывает на production Traefik.
- [ ] Миграция Links применена ручным решением до выкладки зависящего кода.
- [ ] `https://lin.conwix.com` с реальным кодом созданной smoke-ссылки отвечает `302`, TLS-сертификат валиден, `/api/admin/ping` на lin-хосте отвечает `404`.
- [ ] Откат: вернуть прежний образ/конфигурацию; `down()` миграции удаляет click перед link и требует отдельного решения из-за потери статистики.
```

- [ ] **Step 2: Run focused suites and static checks**

Run:

```bash
make structure-check stan deptrac lint
make test-unit
make test-int
make test-func
make front-typecheck front-lint front-test front-knip front-build APP=admin
make api-types-check
```

Expected: every command exits 0.

- [ ] **Step 3: Prove the migration from empty databases**

Run: `make db-rebuild-check`

Expected: both dev/test databases rebuild, every migration applies, backend tests pass, and the target ends with `db-rebuild-check: OK`.

- [ ] **Step 4: Run complete local CI including E2E**

Run: `make ci-local`

Expected: final line `ci-local: все проверки пройдены.`

- [ ] **Step 5: Run both required external review roles**

Migration/schema and host authentication isolation require both passes under the repository threshold.

Run: `make review TASK="Links: shortener module, public host isolation, admin API and dashboard"`

Read `var/review/codex.md` and `var/review/codex-defects.md`. Classify every finding as defect, rule violation, rule gap, or taste; fix all accepted findings; rerun the affected focused checks and Steps 2–4. Record how many accepted findings the second pass uniquely added.

- [ ] **Step 6: Accept ADR only after all checks and accepted findings are closed**

Change both ADR file and registry row from `Proposed` to `Accepted`. Do not modify the ADR text after acceptance.

- [ ] **Step 7: Commit documentation and final accepted state**

```bash
git add CLAUDE.md docs/operations-checklist.md docs/adr/0022-links-shortener-module.md
git add -p docs/structure.md docs/adr/README.md
git commit -m "Documents Links operations and accepts ADR-022"
```

- [ ] **Step 8: Produce the repository-format task report**

Report: one-sentence task; substantive changes; exact touched paths; both review results and accepted counts; rejected finding reasons; all verification commands; open items limited to manual DNS, production migration, and deployment. Explicitly state that no production state was changed and whether commits were blocked by read-only `.git`.
