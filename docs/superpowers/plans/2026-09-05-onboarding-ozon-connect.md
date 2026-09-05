# Онбординг: подключение кабинета Ozon — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** довести путь «регистрация → подтверждение → онбординг → вход
в сервис» до конца: экран `/onboarding` подключает кабинет Ozon с живой
проверкой ключей и ставит загрузку текущего месяца.

**Architecture:** сценарий подключения живёт в `Ingestion` и повторяет
устройство уже работающего `ReplaceOzonCredentialsAction` — проба ключа
у площадки, разбор отказа, и только потом запись через `IdentityFacade`.
Глобальная уникальность кабинета обеспечивается частичным уникальным
индексом с перехватом конфликта на вставке, а не запросом перед ней.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM 3 / DBAL 4,
PostgreSQL 16, Symfony Messenger (doctrine-транспорт), React + TypeScript,
TanStack Query, Vitest + msw.

**Spec:** `docs/superpowers/specs/2026-09-05-onboarding-ozon-connect-design.md`

## Global Constraints

Каждая задача обязана соблюдать эти правила; в тексте задач они
не повторяются.

- **Изоляция арендаторов (§1).** Любой метод чтения данных компании
  принимает `string $companyId` первым параметром. Маршрут —
  `/api/companies/{companyId}/...`. Межарендаторных чтений задача
  не добавляет ни одного.
- **Идемпотентность (§4).** Конструкция «найти, и если не найдено —
  записать» запрещена во всех видах. Защита — уникальный индекс
  с перехватом конфликта на вставке.
- **Списки (§5).** Чтение списков — DBAL в `readonly` DTO, всегда
  с лимитом. Задача новых списков не добавляет.
- **Кэш фронтенда (§7).** Ключ запроса — только через
  `companyQueryKey(companyId, ...)`. Литеральные массивы запрещены.
- **Фронтенд (§10).** TypeScript strict, `any` запрещён линтером. Типы
  ответов — только из `../../../api/schema`. Сетевые вызовы — только
  через `createCompanyApiClient`; прямой `fetch` вне `src/api/` запрещён.
- **Тесты (§9).** Данные — только через Builder из
  `api/tests/Support/Builder`, глобальные фикстуры запрещены. Builder
  неизменяем: каждый метод возвращает новый экземпляр. Обращений
  к настоящему Ozon в тестах нет.
- **Секреты.** `api_key` не попадает ни в ответ, ни в журнал, ни в текст
  исключения, ни в аудит-запись.
- **Модификаторы.** Entity — `class`, сценарии и репозитории —
  `final readonly` там, где так уже сделано у соседей.
- **Проверки.** Задача считается сделанной только при зелёном
  `make ci-local`: PHPStan level 9 (baseline пустой), PHP-CS-Fixer,
  Deptrac, PHPUnit, `make api-types-check`, фронтендовые проверки.

---

### Task 1: Название магазина и глобальная уникальность кабинета

Одна миграция на всю задачу — она вся здесь. Первый шаг проверяет
рискованное допущение (round-trip частичного индекса в Doctrine)
до того, как на нём построено что-либо ещё.

**Files:**
- Modify: `api/src/Identity/Domain/MarketplaceAccount.php`
- Modify: `api/src/Identity/Application/RegisterCompanyWithOzonAccountAction.php`
- Modify: `api/tests/Support/Builder/MarketplaceAccountBuilder.php`
- Modify: `api/src/Identity/Ui/Command/SeedOzonSandboxCompanyCommand.php`
- Modify: `api/tests/Integration/Identity/RegisterCompanyWithOzonAccountActionTest.php`
- Create: `api/migrations/Version20260905090000.php`
- Create: `api/tests/Integration/Identity/MarketplaceAccountUniquenessTest.php`

**Interfaces:**
- Consumes: ничего.
- Produces:
  - `MarketplaceAccount::connect(Uuid $companyId, Marketplace $marketplace, string $name, string $externalShopId, string $credentialsCiphertext, int $credentialsKeyVersion): self`
  - `MarketplaceAccount::name(): string`
  - `MarketplaceAccountBuilder::withName(string $name): self`
  - индекс `uq_marketplace_account_marketplace_external_shop_active`

- [ ] **Step 1: Проверить, переживает ли частичный индекс round-trip Doctrine**

Это шаг-разведка: он решает, объявляется индекс на сущности или только
в миграции. Временно допиши атрибут в `api/src/Identity/Domain/MarketplaceAccount.php`
сразу под существующим `#[ORM\UniqueConstraint(...)]`:

```php
#[ORM\UniqueConstraint(
    name: 'uq_marketplace_account_marketplace_external_shop_active',
    columns: ['marketplace', 'external_shop_id'],
    options: ['where' => "(state <> 'revoked')"],
)]
```

Затем:

```bash
make up && make db-wait && make api-migrate
make api-console CMD="doctrine:schema:validate"
make api-console CMD="doctrine:migrations:diff --dry-run"
```

Ожидание: `schema:validate` зелёный по маппингу, а `diff --dry-run`
показывает ровно одно изменение — создание нового индекса, и **не**
показывает его удаление и пересоздание.

- [ ] **Step 2: Записать исход разведки в спецификацию**

Если round-trip сошёлся — атрибут остаётся, ничего не меняем.

Если `diff` предлагает пересоздавать индекс на каждом прогоне, атрибут
из сущности убирается, индекс живёт только в миграции, а на место
атрибута ставится комментарий:

```php
// Частичный уникальный индекс uq_marketplace_account_marketplace_external_shop_active
// объявлен только в миграции: интроспекция DBAL не воспроизводит условие
// WHERE, и объявление на сущности давало бы вечный дифф в migrations:diff.
// Спецификация 2026-09-05-onboarding-ozon-connect-design.md, раздел «Данные».
```

В обоих случаях допиши фактический исход в раздел «Данные»
спецификации, заменив абзац «Риск, который проверяется в реализации»:
догадка заменяется измеренным фактом.

- [ ] **Step 3: Написать падающий тест уникальности**

Создай `api/tests/Integration/Identity/MarketplaceAccountUniquenessTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Один кабинет Ozon не подключается в две наши компании (ADR-021).
 * Проверка глобальная, и держит её индекс, а не запрос перед вставкой:
 * два параллельных запроса прошли бы проверку оба (CLAUDE.md §4).
 */
final class MarketplaceAccountUniquenessTest extends KernelTestCase
{
    public function testSameCabinetCannotBeConnectedToASecondCompany(): void
    {
        $companies = $this->companies();
        $accounts = $this->marketplaceAccounts();

        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-42')
            ->persistWith($companies, $accounts);

        $this->expectException(UniqueConstraintViolationException::class);

        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-42')
            ->persistWith($companies, $accounts);
    }

    public function testRevokedCabinetIsFreedForAnotherCompany(): void
    {
        $companies = $this->companies();
        $accounts = $this->marketplaceAccounts();

        // Отзыв необратим (ADR-011), поэтому безусловный индекс занял бы
        // кабинет навсегда: клиент, отключившийся и вернувшийся, упёрся
        // бы в стену, разбирать которую пришлось бы руками.
        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-77')
            ->withState(MarketplaceAccountState::Revoked)
            ->persistWith($companies, $accounts);

        $second = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-77')
            ->persistWith($companies, $accounts);

        self::assertSame('shop-77', $second->externalShopId());
    }

    private function companies(): CompanyRepository
    {
        $companies = static::getContainer()->get(CompanyRepository::class);
        self::assertInstanceOf(CompanyRepository::class, $companies);

        return $companies;
    }

    private function marketplaceAccounts(): MarketplaceAccountRepository
    {
        $accounts = static::getContainer()->get(MarketplaceAccountRepository::class);
        self::assertInstanceOf(MarketplaceAccountRepository::class, $accounts);

        return $accounts;
    }
}
```

- [ ] **Step 4: Убедиться, что тест падает**

```bash
make db-test-create && make api-migrate-test
make test-int
```

Ожидание: `testSameCabinetCannotBeConnectedToASecondCompany` падает —
исключения нет, вторая строка вставилась. Индекса ещё нет.

- [ ] **Step 5: Добавить поле `name` на сущность**

В `api/src/Identity/Domain/MarketplaceAccount.php` добавь свойство рядом
с `externalShopId`:

```php
/**
 * Название магазина: пара Client-Id из цифр человеку ничего не говорит.
 * Правки нет намеренно (ADR-021) — правимое имя переводит сущность
 * во вторую строку таблиц ADR-011 и стоит версии и записи в журнал,
 * и платить эту цену надо в задаче про переименование.
 */
#[ORM\Column(length: 255)]
private readonly string $name;
```

Добавь параметр `string $name` в приватный конструктор (сразу после
`Marketplace $marketplace`), присвоение `$this->name = $name;`,
одноимённый параметр в `connect()` с передачей в `new self(...)`,
и геттер:

```php
public function name(): string
{
    return $this->name;
}
```

- [ ] **Step 6: Обновить всех вызывающих `connect()`**

`api/src/Identity/Application/RegisterCompanyWithOzonAccountAction.php` —
добавь параметр `string $name` в `__invoke()` перед `$externalShopId`
и передай его в `MarketplaceAccount::connect()`.

`api/src/Identity/Ui/Command/SeedOzonSandboxCompanyCommand.php` — передай
название магазина в вызов сценария (используй то же значение, что уже
передаётся как имя компании).

`api/tests/Support/Builder/MarketplaceAccountBuilder.php` — добавь поле
и метод, сохранив неизменяемость билдера:

```php
private string $name = 'Песочный магазин';

public function withName(string $name): self
{
    $clone = clone $this;
    $clone->name = $name;

    return $clone;
}
```

Передай `name: $this->name` в оба вызова `MarketplaceAccount::connect()`
внутри `build()` и `persistWith()`.

`api/tests/Integration/Identity/RegisterCompanyWithOzonAccountActionTest.php` —
добавь аргумент названия в вызов сценария.

- [ ] **Step 7: Написать миграцию**

Создай `api/migrations/Version20260905090000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Онбординг (ADR-021): название магазина у подключения и глобальная
 * уникальность кабинета.
 *
 * marketplace_account — справочник в единицы строк, а не факт-таблица,
 * поэтому три шага по колонке идут одной миграцией: правило совместимых
 * изменений писано про таблицы с миллионами строк, где ALTER блокирует
 * запись на минуты.
 */
final class Version20260905090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Название магазина и глобальная уникальность кабинета Ozon';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_account ADD name VARCHAR(255) DEFAULT NULL');
        // Существующие подключения заведены нами вручную, названия у них
        // не было: идентификатор кабинета — единственное осмысленное
        // значение, которое мы про них знаем.
        $this->addSql('UPDATE marketplace_account SET name = external_shop_id WHERE name IS NULL');
        $this->addSql('ALTER TABLE marketplace_account ALTER COLUMN name SET NOT NULL');

        // Условие WHERE обязательно: отзыв необратим (ADR-011),
        // и безусловный индекс занял бы кабинет навсегда.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uq_marketplace_account_marketplace_external_shop_active
                ON marketplace_account (marketplace, external_shop_id)
                WHERE state <> 'revoked'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uq_marketplace_account_marketplace_external_shop_active');
        $this->addSql('ALTER TABLE marketplace_account DROP name');
    }
}
```

- [ ] **Step 8: Применить миграцию и убедиться, что тест проходит**

```bash
make api-migrate && make api-migrate-test
make test-int
```

Ожидание: оба теста уникальности зелёные.

- [ ] **Step 9: Проверить миграцию с пустой базы**

```bash
make db-rebuild-check
```

Ожидание: зелёный. Это условие закрытия любой задачи с миграцией.

- [ ] **Step 10: Проверить откат**

```bash
make api-console CMD="doctrine:migrations:migrate prev --no-interaction"
make api-console CMD="doctrine:migrations:migrate latest --no-interaction"
```

Ожидание: обе команды успешны — `down()` рабочий.

- [ ] **Step 11: Commit**

```bash
git add api/src/Identity api/tests api/migrations docs/superpowers/specs
git commit -m "Добавляет название магазина и глобальную уникальность кабинета"
```

---

### Task 2: Запись подключения в Identity

Сохранение с перехватом конфликта и аудит-записью в одной транзакции.
Половина подключения без записи в журнал — не «почти получилось»,
а строка, о происхождении которой спросить будет не у кого.

**Files:**
- Modify: `api/src/Identity/Domain/AuditAction.php`
- Modify: `api/src/Identity/Domain/MarketplaceAccountRepository.php`
- Modify: `api/src/Identity/Infrastructure/Repository/DoctrineMarketplaceAccountRepository.php`
- Create: `api/src/Identity/Application/Facade/MarketplaceAccountConnectionOutcome.php`
- Create: `api/src/Identity/Application/Facade/MarketplaceAccountConnection.php`
- Modify: `api/src/Identity/Application/Facade/IdentityFacade.php`
- Create: `api/tests/Integration/Identity/ConnectOzonAccountFacadeTest.php`

**Interfaces:**
- Consumes: `MarketplaceAccount::connect(...)` и `MarketplaceAccountBuilder::withName()` из Task 1.
- Produces:
  - `MarketplaceAccountRepository::tryConnect(MarketplaceAccount $account, AuditRecord $trail): bool`
  - `enum MarketplaceAccountConnectionOutcome { case Connected; case AlreadyConnected; }`
  - `final readonly class MarketplaceAccountConnection { public MarketplaceAccountConnectionOutcome $outcome; public ?string $accountId; }` — `accountId` заполнен только у `Connected`
  - `IdentityFacade::connectOzonAccount(string $companyId, string $name, string $clientId, string $apiKey, string $actorUserId): MarketplaceAccountConnection`
  - `AuditAction::MarketplaceAccountConnected = 'marketplace_account.connected'`

- [ ] **Step 1: Написать падающий тест фасада**

Создай `api/tests/Integration/Identity/ConnectOzonAccountFacadeTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\Facade\IdentityFacade;
use App\Identity\Application\Facade\MarketplaceAccountConnectionOutcome;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Запись подключения при онбординге (ADR-021). Ключ обязан быть проверен
 * площадкой до вызова — Identity в площадку не ходит.
 */
final class ConnectOzonAccountFacadeTest extends KernelTestCase
{
    public function testConnectedAccountIsStoredWithNameAndAuditTrail(): void
    {
        $facade = $this->facade();
        [$companyId, $userId] = $this->companyWithOwner();

        $connection = $facade->connectOzonAccount($companyId, 'Мой магазин', 'shop-1', 'live-key', $userId);

        self::assertSame(MarketplaceAccountConnectionOutcome::Connected, $connection->outcome);
        // Идентификатор возвращается сразу: иначе ответу 201 пришлось бы
        // перечитывать подключения компании только что записанным.
        self::assertNotNull($connection->accountId);

        $row = $this->connection()->fetchAssociative(
            'SELECT name, external_shop_id, state FROM marketplace_account WHERE company_id = ?',
            [$companyId],
        );
        self::assertIsArray($row);
        self::assertSame('Мой магазин', $row['name']);
        self::assertSame('shop-1', $row['external_shop_id']);
        self::assertSame('active', $row['state']);

        // «Добавление учётных данных подключений» — одно из событий,
        // для которых журнал обязателен (CLAUDE.md, «Безопасность и аудит»).
        $record = $this->connection()->fetchAssociative(
            'SELECT action, previous_value, new_value FROM audit_record WHERE company_id = ? AND action = ?',
            [$companyId, 'marketplace_account.connected'],
        );
        self::assertIsArray($record);
        self::assertNull($record['previous_value']);
        self::assertSame('Мой магазин (shop-1)', $record['new_value']);
    }

    public function testSecretIsNotWrittenToTheAuditJournal(): void
    {
        $facade = $this->facade();
        [$companyId, $userId] = $this->companyWithOwner();

        $facade->connectOzonAccount($companyId, 'Мой магазин', 'shop-1', 'SUPER-SECRET-KEY', $userId);

        $newValue = $this->connection()->fetchOne(
            'SELECT new_value FROM audit_record WHERE company_id = ? AND action = ?',
            [$companyId, 'marketplace_account.connected'],
        );
        self::assertIsString($newValue);
        self::assertStringNotContainsString('SUPER-SECRET-KEY', $newValue);
    }

    public function testCabinetTakenByAnotherCompanyIsReportedNotThrown(): void
    {
        $facade = $this->facade();
        $companies = $this->companies();

        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-taken')
            ->persistWith($companies, $this->marketplaceAccounts());

        [$companyId, $userId] = $this->companyWithOwner();

        $connection = $facade->connectOzonAccount($companyId, 'Второй магазин', 'shop-taken', 'live-key', $userId);

        // Исход, а не исключение: занятый кабинет — обычный ответ клиенту,
        // а не сбой, и 500 на нём означал бы письмо в трекер на каждую
        // ошибку человека.
        self::assertSame(MarketplaceAccountConnectionOutcome::AlreadyConnected, $connection->outcome);
        self::assertNull($connection->accountId);
        $count = $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM marketplace_account WHERE company_id = ?',
            [$companyId],
        );
        self::assertSame(0, (int) $count);
    }

    /**
     * @return array{string, string}
     */
    private function companyWithOwner(): array
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $users = new DoctrineUserRepository($entityManager);
        $company = CompanyBuilder::aCompany()->persistWith($this->companies());
        $user = UserBuilder::aUser()->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($company)
            ->withUser($user)
            ->persistWith($this->companies(), $users, new DoctrineCompanyMemberRepository($entityManager));

        return [$company->id()->toRfc4122(), $user->id()->toRfc4122()];
    }

    private function facade(): IdentityFacade
    {
        $facade = static::getContainer()->get(IdentityFacade::class);
        self::assertInstanceOf(IdentityFacade::class, $facade);

        return $facade;
    }

    private function companies(): CompanyRepository
    {
        $companies = static::getContainer()->get(CompanyRepository::class);
        self::assertInstanceOf(CompanyRepository::class, $companies);

        return $companies;
    }

    private function marketplaceAccounts(): MarketplaceAccountRepository
    {
        $accounts = static::getContainer()->get(MarketplaceAccountRepository::class);
        self::assertInstanceOf(MarketplaceAccountRepository::class, $accounts);

        return $accounts;
    }

    private function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
```

- [ ] **Step 2: Убедиться, что тест падает**

```bash
make test-int
```

Ожидание: фатальная ошибка — класса
`MarketplaceAccountConnectionOutcome` и метода `connectOzonAccount`
не существует.

- [ ] **Step 3: Добавить константу действия**

В `api/src/Identity/Domain/AuditAction.php`, рядом
с `MarketplaceCredentialsReplaced`:

```php
/** Подключение кабинета при онбординге (ADR-021). */
public const string MarketplaceAccountConnected = 'marketplace_account.connected';
```

- [ ] **Step 4: Добавить исход подключения**

Создай `api/src/Identity/Application/Facade/MarketplaceAccountConnectionOutcome.php`:

```php
<?php

declare(strict_types=1);

namespace App\Identity\Application\Facade;

/**
 * Занятый кабинет — обычный ответ клиенту, а не сбой: исход, а не
 * исключение. Иначе ошибка человека приезжала бы в трекер как наша.
 */
enum MarketplaceAccountConnectionOutcome
{
    case Connected;
    /** Кабинет уже подключён — к этой компании или к другой (ADR-021). */
    case AlreadyConnected;
}
```

Создай `api/src/Identity/Application/Facade/MarketplaceAccountConnection.php`:

```php
<?php

declare(strict_types=1);

namespace App\Identity\Application\Facade;

/**
 * Исход подключения вместе с идентификатором созданной строки.
 *
 * Идентификатор возвращается сразу, а не читается следом: ответ 201
 * обязан назвать созданный ресурс, и перечитывать подключения компании
 * ради строки, которую мы только что записали, — лишний запрос
 * и лишняя возможность разойтись с самим собой.
 */
final readonly class MarketplaceAccountConnection
{
    private function __construct(
        public MarketplaceAccountConnectionOutcome $outcome,
        /** Заполнен только у Connected. */
        public ?string $accountId,
    ) {
    }

    public static function connected(string $accountId): self
    {
        return new self(MarketplaceAccountConnectionOutcome::Connected, $accountId);
    }

    public static function alreadyConnected(): self
    {
        return new self(MarketplaceAccountConnectionOutcome::AlreadyConnected, null);
    }
}
```

- [ ] **Step 5: Добавить метод репозитория**

В `api/src/Identity/Domain/MarketplaceAccountRepository.php`:

```php
/**
 * Подключение кабинета вместе с записью в журнал, одной транзакцией:
 * строка подключения без аудит-записи — строка, о происхождении которой
 * спросить будет не у кого (ADR-011).
 *
 * Возвращает false, когда кабинет уже занят. Проверки перед вставкой
 * нет намеренно: между ней и вставкой два параллельных запроса прошли
 * бы её оба (CLAUDE.md §4), поэтому уникальность держит индекс,
 * а конфликт перехватывается здесь.
 */
public function tryConnect(MarketplaceAccount $account, AuditRecord $trail): bool;
```

Добавь `use App\Identity\Domain\AuditRecord;`, если его там ещё нет.

В `api/src/Identity/Infrastructure/Repository/DoctrineMarketplaceAccountRepository.php`
добавь реализацию — тем же приёмом, что `DoctrineCompanyRepository::registerWithOwner`:

```php
public function tryConnect(MarketplaceAccount $account, AuditRecord $trail): bool
{
    try {
        $this->entityManager->wrapInTransaction(function () use ($account, $trail): void {
            $this->entityManager->persist($account);
            $this->entityManager->persist($trail);
        });
    } catch (UniqueConstraintViolationException $exception) {
        // PostgreSQL называет нарушенное ограничение в сообщении.
        // Поглощаем только уникальность кабинета — глобальную и внутри
        // компании: любое другое нарушение это наш дефект, и молча
        // превращать его в «кабинет занят» значит спрятать его навсегда.
        $message = $exception->getMessage();
        $isCabinetTaken =
            str_contains($message, 'uq_marketplace_account_marketplace_external_shop_active')
            || str_contains($message, 'uq_marketplace_account_company_marketplace_external_shop');

        if (!$isCabinetTaken) {
            throw $exception;
        }

        return false;
    }

    return true;
}
```

Добавь импорты `App\Identity\Domain\AuditRecord` и
`Doctrine\DBAL\Exception\UniqueConstraintViolationException`.

- [ ] **Step 6: Добавить метод фасада**

В `api/src/Identity/Application/Facade/IdentityFacade.php`:

```php
/**
 * Подключение кабинета при онбординге (ADR-021). Ключ обязан быть
 * проверен площадкой до вызова: Identity в площадку не ходит,
 * зависимости строго вниз, и проба живёт в Ingestion.
 *
 * Client-Id становится external_shop_id — под ним подключение заведено,
 * и по нему же работает глобальная уникальность кабинета.
 */
public function connectOzonAccount(
    string $companyId,
    string $name,
    string $clientId,
    string $apiKey,
    string $actorUserId,
): MarketplaceAccountConnection {
    $encrypted = $this->credentialsEncryptor->encrypt(
        MarketplaceCredentials::fromArray(['client_id' => $clientId, 'api_key' => $apiKey]),
    );

    $account = MarketplaceAccount::connect(
        companyId: Uuid::fromString($companyId),
        marketplace: Marketplace::Ozon,
        name: $name,
        externalShopId: $clientId,
        credentialsCiphertext: $encrypted->ciphertext,
        credentialsKeyVersion: $encrypted->keyVersion,
    );

    // «Стало» — название и кабинет, не ключ: журнал не место для секрета
    // (ADR-011). «Было» пусто — подключения до этого не существовало.
    $trail = AuditRecord::record(
        companyId: Uuid::fromString($companyId),
        actorUserId: Uuid::fromString($actorUserId),
        action: AuditAction::MarketplaceAccountConnected,
        subjectId: $account->id(),
        previousValue: null,
        newValue: \sprintf('%s (%s)', $name, $clientId),
        occurredAt: new \DateTimeImmutable(),
    );

    return $this->marketplaceAccounts->tryConnect($account, $trail)
        ? MarketplaceAccountConnection::connected($account->id()->toRfc4122())
        : MarketplaceAccountConnection::alreadyConnected();
}
```

Добавь недостающие импорты: `AuditAction`, `AuditRecord`,
`MarketplaceAccount`, `Marketplace`, `MarketplaceCredentials`.

- [ ] **Step 7: Убедиться, что тесты проходят**

```bash
make test-int
```

Ожидание: все три теста фасада зелёные.

- [ ] **Step 8: Commit**

```bash
git add api/src/Identity api/tests/Integration/Identity
git commit -m "Записывает подключение кабинета с аудит-следом"
```

---

### Task 3: Список дней первичной загрузки

Чистая функция, отдельно от всего остального: границу месяца проще
проверить здесь, чем сквозь HTTP, и ошибка в ней стоит клиенту
недостающего дня истории.

**Files:**
- Create: `api/src/Ingestion/Application/InitialBackfillWindow.php`
- Create: `api/tests/Unit/Ingestion/InitialBackfillWindowTest.php`

**Interfaces:**
- Consumes: ничего.
- Produces: `InitialBackfillWindow::businessDates(\DateTimeImmutable $connectedAt): list<string>` — даты `Y-m-d` в часовом поясе Ozon, от первого числа текущего месяца до дня подключения включительно.

- [ ] **Step 1: Написать падающий тест**

Создай `api/tests/Unit/Ingestion/InitialBackfillWindowTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\InitialBackfillWindow;
use PHPUnit\Framework\TestCase;

/**
 * Ступень 1 первичной загрузки (ADR-021): текущий месяц, сразу, вперёд
 * остальных. Не «последние 30 дней» — продукт контролирует план-факт,
 * а план-факт живёт календарным месяцем.
 */
final class InitialBackfillWindowTest extends TestCase
{
    public function testFirstDayOfMonthGivesExactlyThatDay(): void
    {
        $dates = InitialBackfillWindow::businessDates(new \DateTimeImmutable('2026-09-01 10:00:00', new \DateTimeZone('Europe/Moscow')));

        self::assertSame(['2026-09-01'], $dates);
    }

    public function testMidMonthGivesEveryDayFromTheFirst(): void
    {
        $dates = InitialBackfillWindow::businessDates(new \DateTimeImmutable('2026-09-05 10:00:00', new \DateTimeZone('Europe/Moscow')));

        self::assertSame(
            ['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-05'],
            $dates,
        );
    }

    public function testLastDayOfLongMonthIsIncluded(): void
    {
        $dates = InitialBackfillWindow::businessDates(new \DateTimeImmutable('2026-01-31 23:00:00', new \DateTimeZone('Europe/Moscow')));

        self::assertCount(31, $dates);
        self::assertSame('2026-01-01', $dates[0]);
        self::assertSame('2026-01-31', $dates[30]);
    }

    public function testBusinessDateFollowsMarketplaceTimezoneNotUtc(): void
    {
        // 31 августа 22:00 UTC — это уже 1 сентября в Москве (ADR-009:
        // бизнес-дата в часовом поясе площадки). Считать по UTC значило
        // бы грузить весь август вместо одного дня сентября.
        $dates = InitialBackfillWindow::businessDates(new \DateTimeImmutable('2026-08-31 22:00:00', new \DateTimeZone('UTC')));

        self::assertSame(['2026-09-01'], $dates);
    }
}
```

- [ ] **Step 2: Убедиться, что тест падает**

```bash
make test-unit
```

Ожидание: `Class "App\Ingestion\Application\InitialBackfillWindow" not found`.

- [ ] **Step 3: Написать реализацию**

Создай `api/src/Ingestion/Application/InitialBackfillWindow.php`:

```php
<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

/**
 * Ступень 1 первичной загрузки при онбординге (ADR-021): текущий
 * календарный месяц, сразу, вперёд остальных ступеней.
 *
 * Месяц, а не скользящие 30 дней: продукт контролирует план-факт,
 * а план-факт живёт календарным месяцем. Клиент, подключившийся второго
 * числа, при скользящем окне получил бы текущий месяц без единого
 * закрытого месяца рядом — то есть цифру, которую не с чем сравнить.
 *
 * Сообщения загрузки устроены по одному бизнес-дню на сообщение,
 * поэтому «месяц» здесь — список дней, а не диапазон.
 */
final readonly class InitialBackfillWindow
{
    /** ADR-009: бизнес-дата в часовом поясе площадки, не в UTC. */
    private const string TIMEZONE = 'Europe/Moscow';

    private function __construct()
    {
    }

    /**
     * @return list<string> даты Y-m-d по возрастанию, включая день подключения
     */
    public static function businessDates(\DateTimeImmutable $connectedAt): array
    {
        $today = $connectedAt->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $cursor = $today->modify('first day of this month');

        $dates = [];
        while ($cursor->format('Y-m-d') <= $today->format('Y-m-d')) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return $dates;
    }
}
```

- [ ] **Step 4: Убедиться, что тесты проходят**

```bash
make test-unit
```

Ожидание: все четыре теста зелёные.

- [ ] **Step 5: Commit**

```bash
git add api/src/Ingestion/Application/InitialBackfillWindow.php api/tests/Unit/Ingestion
git commit -m "Считает окно первичной загрузки по календарному месяцу"
```

---

### Task 4: Сценарий подключения с проверкой ключей

**Files:**
- Create: `api/src/Ingestion/Application/ConnectOzonAccountResult.php`
- Create: `api/src/Ingestion/Application/ConnectOzonAccountOutcome.php`
- Create: `api/src/Ingestion/Application/ConnectOzonAccountAction.php`
- Create: `api/tests/Integration/Ingestion/ConnectOzonAccountActionTest.php`

**Interfaces:**
- Consumes: `IdentityFacade::connectOzonAccount(...)`, `MarketplaceAccountConnection` и `MarketplaceAccountConnectionOutcome` (Task 2); `InitialBackfillWindow::businessDates(...)` (Task 3).
- Produces:
  - `enum ConnectOzonAccountResult { case Connected; case Rejected; case AlreadyConnected; case Unavailable; }`
  - `final readonly class ConnectOzonAccountOutcome { public ConnectOzonAccountResult $result; public ?string $accountId; }`
  - `ConnectOzonAccountAction::__invoke(string $companyId, string $name, string $clientId, string $apiKey, string $actorUserId): ConnectOzonAccountOutcome`

- [ ] **Step 1: Написать падающий тест**

Создай `api/tests/Integration/Ingestion/ConnectOzonAccountActionTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Application\ConnectOzonAccountAction;
use App\Ingestion\Application\ConnectOzonAccountResult;
use App\Ingestion\Application\Message\FetchOzonCatalogMessage;
use App\Ingestion\Application\Message\FetchOzonExpensesMessage;
use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use App\Ingestion\Domain\OzonCatalogFetcher;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonProductListClient;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Подключение кабинета при онбординге (ADR-021): ключи проверяются живым
 * запросом ДО сохранения, и три исхода различаются, потому что клиенту
 * нужно разное действие в каждом.
 *
 * Обращений к настоящему Ozon нет (ADR-005).
 */
final class ConnectOzonAccountActionTest extends KernelTestCase
{
    public function testAcceptedKeyIsSavedAndSchedulesTheCurrentMonth(): void
    {
        [$companyId, $userId] = $this->companyWithOwner();
        $this->ozonAnswers(200);

        $outcome = ($this->action())($companyId, 'Мой магазин', 'shop-1', 'live-key', $userId);

        self::assertSame(ConnectOzonAccountResult::Connected, $outcome->result);
        self::assertNotNull($outcome->accountId);

        // Не «данные появятся ночью»: сообщение в очередь сразу после
        // сохранения (ADR-021).
        $dispatched = $this->dispatchedMessages();
        self::assertContains(FetchOzonCatalogMessage::class, $dispatched);
        self::assertContains(FetchOzonPostingsMessage::class, $dispatched);
        self::assertContains(FetchOzonExpensesMessage::class, $dispatched);
    }

    public function testRejectedKeyIsNotSaved(): void
    {
        [$companyId, $userId] = $this->companyWithOwner();
        // Подключение, созданное с неверными ключами, — это broken через
        // несколько часов и клиент, который считает, что всё настроил.
        $this->ozonAnswers(401);

        $outcome = ($this->action())($companyId, 'Мой магазин', 'shop-1', 'wrong-key', $userId);

        self::assertSame(ConnectOzonAccountResult::Rejected, $outcome->result);
        self::assertSame(0, $this->accountCount($companyId));
        self::assertSame([], $this->dispatchedMessages());
    }

    public function testUnavailableMarketplaceIsNotReportedAsAWrongKey(): void
    {
        [$companyId, $userId] = $this->companyWithOwner();
        // Лимит запросов и сбой площадки лечатся повтором, а не выпуском
        // нового ключа. Сказать «ключ не подошёл» здесь значит отправить
        // клиента делать бесполезную работу.
        $this->ozonAnswers(503);

        $outcome = ($this->action())($companyId, 'Мой магазин', 'shop-1', 'live-key', $userId);

        self::assertSame(ConnectOzonAccountResult::Unavailable, $outcome->result);
        self::assertSame(0, $this->accountCount($companyId));
    }

    public function testCabinetOfAnotherCompanyIsReportedAsAlreadyConnected(): void
    {
        $companies = $this->companies();
        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-taken')
            ->persistWith($companies, $this->marketplaceAccounts());

        [$companyId, $userId] = $this->companyWithOwner();
        $this->ozonAnswers(200);

        $outcome = ($this->action())($companyId, 'Второй магазин', 'shop-taken', 'live-key', $userId);

        self::assertSame(ConnectOzonAccountResult::AlreadyConnected, $outcome->result);
        self::assertSame(0, $this->accountCount($companyId));
    }

    /** @return list<string> */
    private function dispatchedMessages(): array
    {
        $transport = static::getContainer()->get('messenger.transport.async_ingestion');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_map(
            static fn (object $envelope): string => $envelope->getMessage()::class,
            $transport->getSent(),
        );
    }

    private function accountCount(string $companyId): int
    {
        $connection = static::getContainer()->get(\Doctrine\DBAL\Connection::class);
        self::assertInstanceOf(\Doctrine\DBAL\Connection::class, $connection);

        return (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_account WHERE company_id = ?',
            [$companyId],
        );
    }

    private function ozonAnswers(int $status): void
    {
        $body = 200 === $status
            ? '{"result":{"items":[],"total":0,"last_id":""}}'
            : '{"code":16,"message":"unauthenticated"}';

        static::getContainer()->set(OzonProductListClient::class, new class($body, $status) implements OzonCatalogFetcher {
            public function __construct(
                private readonly string $body,
                private readonly int $status,
            ) {
            }

            public function fetchPage(string $clientId, string $apiKey, string $lastId, int $limit = 1000): string
            {
                $client = new MockHttpClient(new MockResponse($this->body, ['http_code' => $this->status]));

                return $client->request('POST', 'https://api-seller.ozon.ru/v3/product/list')->getContent();
            }
        });
    }

    private function action(): ConnectOzonAccountAction
    {
        $action = static::getContainer()->get(ConnectOzonAccountAction::class);
        self::assertInstanceOf(ConnectOzonAccountAction::class, $action);

        return $action;
    }

    private function companies(): CompanyRepository
    {
        $companies = static::getContainer()->get(CompanyRepository::class);
        self::assertInstanceOf(CompanyRepository::class, $companies);

        return $companies;
    }

    private function marketplaceAccounts(): MarketplaceAccountRepository
    {
        $accounts = static::getContainer()->get(MarketplaceAccountRepository::class);
        self::assertInstanceOf(MarketplaceAccountRepository::class, $accounts);

        return $accounts;
    }

    /** @return array{string, string} */
    private function companyWithOwner(): array
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $users = new DoctrineUserRepository($entityManager);
        $company = CompanyBuilder::aCompany()->persistWith($this->companies());
        $user = UserBuilder::aUser()->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($company)
            ->withUser($user)
            ->persistWith($this->companies(), $users, new DoctrineCompanyMemberRepository($entityManager));

        return [$company->id()->toRfc4122(), $user->id()->toRfc4122()];
    }
}
```

- [ ] **Step 2: Убедиться, что тест падает**

```bash
make test-int
```

Ожидание: класса `ConnectOzonAccountAction` не существует.

Конфигурацию очереди **не трогай**: блок `when@test` в
`api/config/packages/messenger.yaml` уже задаёт
`async_ingestion: 'in-memory://'`, и второго файла не нужно. Изменение
конфигурации транспортов вдобавок является условием остановки
по CLAUDE.md.

- [ ] **Step 3: Добавить исходы сценария**

Создай `api/src/Ingestion/Application/ConnectOzonAccountResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

/**
 * Исходы подключения кабинета, которые контроллер обязан различать
 * по-разному: у каждого свой ответ и своё следующее действие клиента
 * (ADR-021).
 */
enum ConnectOzonAccountResult
{
    case Connected;
    /** Площадка не приняла ключ — проверить пару и тип ключа. */
    case Rejected;
    /** Кабинет уже подключён к этой или другой компании. */
    case AlreadyConnected;
    /** Площадка не ответила — подождать, а не выпускать новый ключ. */
    case Unavailable;
}
```

Создай `api/src/Ingestion/Application/ConnectOzonAccountOutcome.php`:

```php
<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

/**
 * Исход подключения вместе с идентификатором созданной строки: ответ 201
 * обязан назвать созданный ресурс, и читать его вторым запросом сразу
 * после записи незачем.
 */
final readonly class ConnectOzonAccountOutcome
{
    private function __construct(
        public ConnectOzonAccountResult $result,
        /** Заполнен только у Connected. */
        public ?string $accountId,
    ) {
    }

    public static function connected(string $accountId): self
    {
        return new self(ConnectOzonAccountResult::Connected, $accountId);
    }

    public static function failed(ConnectOzonAccountResult $result): self
    {
        return new self($result, null);
    }
}
```

- [ ] **Step 4: Написать сценарий**

Создай `api/src/Ingestion/Application/ConnectOzonAccountAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\Identity\Application\Facade\MarketplaceAccountConnectionOutcome;
use App\Ingestion\Application\Message\FetchOzonCatalogMessage;
use App\Ingestion\Application\Message\FetchOzonExpensesMessage;
use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use App\Ingestion\Application\Message\FetchOzonReturnsMessage;
use App\Ingestion\Domain\OzonAuthorizationFailure;
use App\Ingestion\Domain\OzonCatalogFetcher;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Подключение кабинета Ozon при онбординге (ADR-021).
 *
 * Порядок важен и повторяет ReplaceOzonCredentialsAction: сохранить
 * непроверенный ключ значит завести подключение, которое станет broken
 * через несколько часов, — а клиент будет считать, что всё настроил.
 *
 * Живёт в Ingestion, хотя пишет данные Identity: проверка требует похода
 * в площадку, клиент площадки принадлежит Ingestion, а зависимости
 * строго вниз.
 */
final readonly class ConnectOzonAccountAction
{
    private const int PROBE_LIMIT = 1;

    public function __construct(
        private OzonCatalogFetcher $client,
        private IdentityFacade $identityFacade,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(
        string $companyId,
        string $name,
        string $clientId,
        string $apiKey,
        string $actorUserId,
    ): ConnectOzonAccountOutcome {
        try {
            $this->client->fetchPage($clientId, $apiKey, '', self::PROBE_LIMIT);
        } catch (\Throwable $failure) {
            if (OzonAuthorizationFailure::isAuthorizationFailure($failure)) {
                return ConnectOzonAccountOutcome::failed(ConnectOzonAccountResult::Rejected);
            }

            // Лимит запросов, сбой площадки, обрыв сети. В отличие
            // от замены ключей, недоступность здесь не пробрасывается
            // исключением: ADR-021 требует именно трёх различимых
            // исходов, а 500 не может честно сказать «попробуйте позже».
            return ConnectOzonAccountOutcome::failed(ConnectOzonAccountResult::Unavailable);
        }

        $connection = $this->identityFacade->connectOzonAccount($companyId, $name, $clientId, $apiKey, $actorUserId);
        if (MarketplaceAccountConnectionOutcome::AlreadyConnected === $connection->outcome) {
            return ConnectOzonAccountOutcome::failed(ConnectOzonAccountResult::AlreadyConnected);
        }

        \assert(null !== $connection->accountId);
        $this->scheduleInitialBackfill($companyId, $connection->accountId);

        return ConnectOzonAccountOutcome::connected($connection->accountId);
    }

    /**
     * Ступень 1 (ADR-021): текущий месяц, сразу, вперёд остальных.
     * Каталог — снимок текущего состояния, глубины у него нет, поэтому
     * одним сообщением.
     */
    private function scheduleInitialBackfill(string $companyId, string $accountId): void
    {
        $this->bus->dispatch(new FetchOzonCatalogMessage($companyId, $accountId));

        $businessDates = InitialBackfillWindow::businessDates(new \DateTimeImmutable());
        foreach ($businessDates as $businessDate) {
            $this->bus->dispatch(new FetchOzonPostingsMessage($companyId, $accountId, $businessDate));
            $this->bus->dispatch(new FetchOzonExpensesMessage($companyId, $accountId, $businessDate));
        }

        // Возвраты принимают диапазон, а не один день (FetchOzonReturnsMessage:
        // from/to), поэтому уходят одним сообщением на весь месяц. Тридцать
        // сообщений с диапазоном в один день отработали бы, но потратили бы
        // квоту площадки тридцатикратно.
        $first = $businessDates[0] ?? null;
        $last = $businessDates[\count($businessDates) - 1] ?? null;
        if (null !== $first && null !== $last) {
            $this->bus->dispatch(new FetchOzonReturnsMessage($companyId, $accountId, $first, $last));
        }
    }
}
```

**Сигнатуры сообщений сверены при подготовке, менять их не нужно:**
`FetchOzonCatalogMessage(companyId, marketplaceAccountId)`;
`FetchOzonPostingsMessage(companyId, marketplaceAccountId, businessDate)`;
`FetchOzonExpensesMessage(companyId, marketplaceAccountId, accrualDate)`;
`FetchOzonReturnsMessage(companyId, marketplaceAccountId, from, to)`.

- [ ] **Step 5: Убедиться, что тесты проходят**

```bash
make test-int
```

Ожидание: все четыре теста сценария зелёные.

- [ ] **Step 6: Проверить границы модулей**

```bash
make deptrac
```

Ожидание: зелёный. Ingestion → Identity разрешено; обратного вызова
задача не добавляет.

- [ ] **Step 7: Commit**

```bash
git add api/src/Ingestion/Application api/tests/Integration/Ingestion api/config
git commit -m "Подключает кабинет Ozon с проверкой ключей до сохранения"
```

---

### Task 5: HTTP-эндпоинт подключения

**Files:**
- Create: `api/src/Ingestion/Ui/Request/ConnectOzonAccountRequest.php`
- Create: `api/src/Ingestion/Ui/Response/ConnectedAccountResponse.php`
- Create: `api/src/Ingestion/Ui/Controller/ConnectOzonAccountController.php`
- Create: `api/tests/Functional/Ingestion/ConnectOzonAccountControllerTest.php`
- Modify: `packages/api-schema/openapi.json` (генерируется)
- Modify: `packages/api-schema/src/schema.d.ts` (генерируется)

**Interfaces:**
- Consumes: `ConnectOzonAccountAction`, `ConnectOzonAccountOutcome` и `ConnectOzonAccountResult` (Task 4).
- Produces: `POST /api/companies/{companyId}/connections`; схема `ConnectedAccountResponse { id: string, name: string, state: string }`; коды ошибок `credentials_rejected`, `cabinet_already_connected`, `marketplace_unavailable`.

- [ ] **Step 1: Написать падающий функциональный тест**

Создай `api/tests/Functional/Ingestion/ConnectOzonAccountControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\OzonCatalogFetcher;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonProductListClient;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Приём подключения на онбординге (ADR-021).
 *
 * Через HTTP проверяется то, что иначе проверить нечем: изоляция
 * арендаторов живёт в подписчике kernel.controller, а повтор запроса
 * и есть предмет проверки идемпотентности приёма (CLAUDE.md §9).
 */
final class ConnectOzonAccountControllerTest extends WebTestCase
{
    public function testForeignCompanyIsRejectedAndNothingIsWritten(): void
    {
        $client = static::createClient();
        $this->loginAsCompanyMember($client);
        // Обязательное покрытие §9: изоляция данных между компаниями.
        $foreign = CompanyBuilder::aCompany()->persistWith($this->companies());
        $this->ozonAnswers(200);

        $this->post($client, $foreign, ['name' => 'Чужой магазин', 'clientId' => 'shop-9', 'apiKey' => 'live-key']);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->accountCount($foreign->id()->toRfc4122()));
    }

    public function testAcceptedKeyCreatesTheConnection(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $this->ozonAnswers(200);

        $this->post($client, $company, ['name' => 'Мой магазин', 'clientId' => 'shop-1', 'apiKey' => 'live-key']);

        self::assertSame(201, $client->getResponse()->getStatusCode());
        self::assertSame(1, $this->accountCount($company->id()->toRfc4122()));
    }

    public function testRejectedKeyAnswersWithItsOwnCode(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $this->ozonAnswers(401);

        $this->post($client, $company, ['name' => 'Мой магазин', 'clientId' => 'shop-1', 'apiKey' => 'wrong-key']);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame('credentials_rejected', $this->code($client));
    }

    public function testUnavailableMarketplaceAnswersWithItsOwnCode(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $this->ozonAnswers(503);

        $this->post($client, $company, ['name' => 'Мой магазин', 'clientId' => 'shop-1', 'apiKey' => 'live-key']);

        // Отдельный код и отдельный статус: клиенту надо подождать,
        // а не выпускать новый ключ (ADR-021).
        self::assertSame(503, $client->getResponse()->getStatusCode());
        self::assertSame('marketplace_unavailable', $this->code($client));
    }

    public function testRepeatedRequestDoesNotCreateASecondConnection(): void
    {
        $client = static::createClient();
        // Без disableReboot ядро перезапускается между запросами,
        // подменённый клиент площадки исчезает вместе с контейнером,
        // и второй запрос уходит в настоящий Ozon (ADR-005).
        $client->disableReboot();
        $company = $this->loginAsCompanyMember($client);
        $this->ozonAnswers(200);

        $this->post($client, $company, ['name' => 'Мой магазин', 'clientId' => 'shop-1', 'apiKey' => 'live-key']);
        self::assertSame(201, $client->getResponse()->getStatusCode());

        $this->post($client, $company, ['name' => 'Мой магазин', 'clientId' => 'shop-1', 'apiKey' => 'live-key']);

        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertSame('cabinet_already_connected', $this->code($client));
        self::assertSame(1, $this->accountCount($company->id()->toRfc4122()));
    }

    public function testCabinetTakenByAnotherCompanyIsRefused(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($this->companies()))
            ->withExternalShopId('shop-taken')
            ->persistWith($this->companies(), $this->marketplaceAccounts());
        $this->ozonAnswers(200);

        // Без этой проверки первый же клиент, продублировавший кабинет
        // на второй аккаунт, получил бы две компании с одними фактами
        // и расхождение, которое нечем объяснить (ADR-021).
        $this->post($client, $company, ['name' => 'Второй магазин', 'clientId' => 'shop-taken', 'apiKey' => 'live-key']);

        self::assertSame(409, $client->getResponse()->getStatusCode());
    }

    public function testEmptyFieldsAreRejectedBeforeAnyRequestToTheMarketplace(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        // Клиент площадки не подменяется: если запрос всё-таки уйдёт,
        // тест упадёт на попытке реального HTTP.
        $this->post($client, $company, ['name' => '', 'clientId' => '', 'apiKey' => '']);

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testSecretNeverAppearsInTheResponse(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $this->ozonAnswers(200);

        $this->post($client, $company, ['name' => 'Мой магазин', 'clientId' => 'shop-1', 'apiKey' => 'SUPER-SECRET-KEY']);

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringNotContainsString('SUPER-SECRET-KEY', $content);
    }

    /** @param array<string, mixed> $body */
    private function post(KernelBrowser $client, Company $company, array $body): void
    {
        $client->request(
            'POST',
            "/api/companies/{$company->id()->toRfc4122()}/connections",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    private function code(KernelBrowser $client): string
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        $decoded = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('code', $decoded);
        self::assertIsString($decoded['code']);

        return $decoded['code'];
    }

    private function accountCount(string $companyId): int
    {
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_account WHERE company_id = ?',
            [$companyId],
        );
    }

    private function ozonAnswers(int $status): void
    {
        $body = 200 === $status
            ? '{"result":{"items":[],"total":0,"last_id":""}}'
            : '{"code":16,"message":"unauthenticated"}';

        static::getContainer()->set(OzonProductListClient::class, new class($body, $status) implements OzonCatalogFetcher {
            public function __construct(
                private readonly string $body,
                private readonly int $status,
            ) {
            }

            public function fetchPage(string $clientId, string $apiKey, string $lastId, int $limit = 1000): string
            {
                $client = new MockHttpClient(new MockResponse($this->body, ['http_code' => $this->status]));

                return $client->request('POST', 'https://api-seller.ozon.ru/v3/product/list')->getContent();
            }
        });
    }

    private function companies(): CompanyRepository
    {
        $companies = static::getContainer()->get(CompanyRepository::class);
        self::assertInstanceOf(CompanyRepository::class, $companies);

        return $companies;
    }

    private function marketplaceAccounts(): MarketplaceAccountRepository
    {
        $accounts = static::getContainer()->get(MarketplaceAccountRepository::class);
        self::assertInstanceOf(MarketplaceAccountRepository::class, $accounts);

        return $accounts;
    }

    private function loginAsCompanyMember(KernelBrowser $client): Company
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $users = new DoctrineUserRepository($entityManager);
        $company = CompanyBuilder::aCompany()->persistWith($this->companies());
        $user = UserBuilder::aUser()->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($company)
            ->withUser($user)
            ->persistWith($this->companies(), $users, new DoctrineCompanyMemberRepository($entityManager));

        $client->loginUser($user, 'api');

        return $company;
    }
}
```

- [ ] **Step 2: Убедиться, что тест падает**

```bash
make test-func
```

Ожидание: 404 вместо ожидаемых кодов — маршрута нет.

- [ ] **Step 3: Написать DTO запроса**

Создай `api/src/Ingestion/Ui/Request/ConnectOzonAccountRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Request;

/**
 * DTO запроса, не Symfony Form (docs/patterns.md, «HTTP-слой»), проверки
 * ручные — тем же приёмом, что в ReplaceCredentialsRequest.
 *
 * Значения в исключения и сообщения не попадают: apiKey — секрет,
 * и текст ошибки с его фрагментом уехал бы в трекер и в логи.
 */
final readonly class ConnectOzonAccountRequest
{
    private function __construct(
        public string $name,
        public string $clientId,
        public string $apiKey,
    ) {
    }

    /**
     * @throws \InvalidArgumentException с кодом ошибки для ответа 422
     */
    public static function fromJson(string $body): self
    {
        try {
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('malformed_json');
        }

        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('malformed_json');
        }

        $name = $decoded['name'] ?? null;
        if (!\is_string($name) || '' === trim($name)) {
            throw new \InvalidArgumentException('name_required');
        }

        $clientId = $decoded['clientId'] ?? null;
        if (!\is_string($clientId) || '' === trim($clientId)) {
            throw new \InvalidArgumentException('client_id_required');
        }

        $apiKey = $decoded['apiKey'] ?? null;
        if (!\is_string($apiKey) || '' === trim($apiKey)) {
            throw new \InvalidArgumentException('api_key_required');
        }

        return new self(trim($name), trim($clientId), trim($apiKey));
    }
}
```

- [ ] **Step 4: Написать DTO ответа**

Создай `api/src/Ingestion/Ui/Response/ConnectedAccountResponse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

use OpenApi\Attributes as OA;

/**
 * Ответ на успешное подключение. Учётных данных здесь нет и быть
 * не может: эхо секрета оставило бы его в истории запросов браузера
 * и в логах любого прокси на пути.
 */
final readonly class ConnectedAccountResponse
{
    public function __construct(
        #[OA\Property(description: 'Идентификатор подключения')]
        public string $id,
        #[OA\Property(description: 'Название магазина')]
        public string $name,
        #[OA\Property(description: 'Состояние подключения')]
        public string $state,
    ) {
    }
}
```

- [ ] **Step 5: Написать контроллер**

Создай `api/src/Ingestion/Ui/Controller/ConnectOzonAccountController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Controller;

use App\Ingestion\Application\ConnectOzonAccountAction;
use App\Ingestion\Application\ConnectOzonAccountResult;
use App\Ingestion\Ui\Request\ConnectOzonAccountRequest;
use App\Ingestion\Ui\Response\ConnectedAccountResponse;
use App\Shared\Ui\RequestAttributes;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Подключение кабинета при онбординге (ADR-021).
 *
 * До этого эндпоинта самостоятельно зарегистрировавшийся клиент упирался
 * в тупик: аккаунт есть, компания есть, а подключить кабинет нечем.
 *
 * Ключ проверяется у площадки до сохранения (ConnectOzonAccountAction),
 * поэтому 422 здесь означает именно «площадка не приняла ключ»,
 * а 503 — «площадка не ответила», и это разные следующие действия
 * клиента.
 *
 * companyId первым сегментом (§1); 403 для чужой компании отдаёт
 * CompanyAccessSubscriber, до контроллера запрос не доходит.
 */
#[Route(
    '/api/companies/{companyId}/connections',
    name: 'ingestion_company_connection_connect',
    requirements: ['companyId' => Requirement::UUID],
    methods: ['POST'],
)]
final class ConnectOzonAccountController
{
    public function __construct(
        private readonly ConnectOzonAccountAction $connect,
    ) {
    }

    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['name', 'clientId', 'apiKey'],
        properties: [
            new OA\Property(property: 'name', type: 'string', description: 'Название магазина; после создания не меняется (ADR-021)'),
            new OA\Property(property: 'clientId', type: 'string'),
            new OA\Property(property: 'apiKey', type: 'string'),
        ],
    ))]
    #[OA\Response(
        response: 201,
        description: 'Ключ принят площадкой, подключение создано, первичная загрузка поставлена в очередь',
        content: new Model(type: ConnectedAccountResponse::class),
    )]
    #[OA\Response(
        response: 422,
        description: 'Площадка не приняла ключ либо тело запроса неполное',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 409,
        description: 'Кабинет уже подключён — к этой или другой компании (ADR-021)',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 503,
        description: 'Площадка не ответила — повторить позже, ключ выпускать не нужно',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    #[OA\Response(
        response: 403,
        description: 'Пользователь не состоит в этой компании',
        content: new Model(type: ValidationErrorResponse::class),
    )]
    public function __invoke(string $companyId, Request $request): JsonResponse
    {
        try {
            $payload = ConnectOzonAccountRequest::fromJson($request->getContent());
        } catch (\InvalidArgumentException $invalid) {
            return $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $invalid->getMessage(),
                'Заполните название магазина, Client-Id и Api-Key.',
            );
        }

        // Автора ставит CompanyAccessSubscriber там же, где проверяет
        // членство: сюда запрос без него не доходит.
        $actorUserId = $request->attributes->get(RequestAttributes::ActorUserId);
        \assert(\is_string($actorUserId));

        $outcome = ($this->connect)($companyId, $payload->name, $payload->clientId, $payload->apiKey, $actorUserId);

        return match ($outcome->result) {
            ConnectOzonAccountResult::Connected => $this->created($outcome->accountId, $payload->name),
            // Тот же код, что у замены ключей: у клиента это та же беда
            // и то же следующее действие.
            ConnectOzonAccountResult::Rejected => $this->error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'credentials_rejected',
                'Площадка не приняла ключ. Проверьте Client-Id и Api-Key в кабинете продавца.',
            ),
            ConnectOzonAccountResult::AlreadyConnected => $this->error(
                Response::HTTP_CONFLICT,
                'cabinet_already_connected',
                'Этот кабинет уже подключён. Один кабинет Ozon подключается только к одному аккаунту.',
            ),
            ConnectOzonAccountResult::Unavailable => $this->error(
                Response::HTTP_SERVICE_UNAVAILABLE,
                'marketplace_unavailable',
                'Ozon сейчас не отвечает. Ключ выпускать не нужно — повторите через несколько минут.',
            ),
        };
    }

    private function created(?string $accountId, string $name): JsonResponse
    {
        // Идентификатор приходит вместе с исходом: у Connected он есть
        // по построению (ConnectOzonAccountOutcome::connected).
        \assert(null !== $accountId);

        return new JsonResponse(
            new ConnectedAccountResponse(id: $accountId, name: $name, state: 'active'),
            Response::HTTP_CREATED,
        );
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
```

- [ ] **Step 6: Убедиться, что тесты проходят**

```bash
make test-func
```

Ожидание: все восемь тестов контроллера зелёные.

- [ ] **Step 7: Перегенерировать схему и типы**

```bash
make api-doc-export && make api-types && make api-types-check
```

Ожидание: `api-types-check` зелёный, в `schema.d.ts` появился
`ConnectedAccountResponse`.

- [ ] **Step 8: Commit**

```bash
git add api/src/Ingestion/Ui api/tests/Functional/Ingestion packages/api-schema
git commit -m "Добавляет эндпоинт подключения кабинета"
```

---

### Task 6: Экран онбординга

**Files:**
- Create: `apps/seller/src/features/onboarding/lib/connectAccountError.ts`
- Create: `apps/seller/src/features/onboarding/lib/connectAccountError.test.ts`
- Create: `apps/seller/src/features/onboarding/model/useConnectAccount.ts`
- Create: `apps/seller/src/features/onboarding/model/useConnectAccount.test.ts`
- Modify: `apps/seller/src/features/auth/ui/OnboardingStartPage.tsx`
- Modify: `apps/seller/src/features/auth/ui/OnboardingStartPage.test.tsx`

**Interfaces:**
- Consumes: `components['schemas']['ConnectedAccountResponse']` из Task 5.
- Produces:
  - `connectAccountFailure(code: string | null): ConnectAccountFailure` с полями `{ title, description }`
  - `useConnectAccount(companyId: string)` — мутация TanStack Query
  - `OnboardingStartPage` с рабочей формой

- [ ] **Step 1: Написать падающий тест разбора ошибок**

Создай `apps/seller/src/features/onboarding/lib/connectAccountError.test.ts`:

```ts
import { describe, expect, it } from 'vitest'

import { connectAccountFailure } from './connectAccountError'

// Обязательное покрытие §10 — разбор ошибок API. У каждого исхода своё
// следующее действие человека, и перепутать их значит отправить его
// выпускать новый ключ там, где надо было подождать.
describe('connectAccountFailure', () => {
  it('говорит проверить ключи, когда площадка их не приняла', () => {
    expect(connectAccountFailure('credentials_rejected').title).toBe(
      'Площадка не приняла ключ',
    )
  })

  it('говорит подождать, а не выпускать ключ, когда площадка не ответила', () => {
    const failure = connectAccountFailure('marketplace_unavailable')

    expect(failure.title).toBe('Ozon сейчас не отвечает')
    expect(failure.description).toContain('Ключ выпускать не нужно')
  })

  it('объясняет занятый кабинет', () => {
    expect(connectAccountFailure('cabinet_already_connected').title).toBe(
      'Кабинет уже подключён',
    )
  })

  it('не обещает лишнего на незнакомом коде', () => {
    // Ответ без тела: упала сеть, прокси отдал HTML. Обещать, что ничего
    // не сохранилось, здесь нельзя — неизвестно, дошёл ли запрос.
    expect(connectAccountFailure(null).title).toBe('Не удалось подключить кабинет')
  })
})
```

- [ ] **Step 2: Убедиться, что тест падает**

```bash
make front-test APP=seller
```

Ожидание: модуль `./connectAccountError` не найден.

- [ ] **Step 3: Написать разбор ошибок**

Создай `apps/seller/src/features/onboarding/lib/connectAccountError.ts`:

```ts
// Что показать клиенту на отказ подключения (ADR-021).
//
// Отдельная чистая функция, а не ветвление в компоненте: у каждого
// исхода своё следующее действие человека.
export interface ConnectAccountFailure {
  title: string
  description: string
}

const BY_CODE: Record<string, ConnectAccountFailure> = {
  credentials_rejected: {
    title: 'Площадка не приняла ключ',
    description:
      'Проверьте, что Client-Id и Api-Key скопированы целиком и выпущены в одном кабинете. Подключение не создано.',
  },
  cabinet_already_connected: {
    title: 'Кабинет уже подключён',
    description:
      'Один кабинет Ozon подключается только к одному аккаунту. Если кабинет ваш, напишите нам — разберёмся.',
  },
  marketplace_unavailable: {
    title: 'Ozon сейчас не отвечает',
    description:
      'Ключ выпускать не нужно — с ключами всё в порядке. Повторите через несколько минут.',
  },
  name_required: {
    title: 'Укажите название магазина',
    description: 'Оно нужно, чтобы отличать кабинеты: Client-Id из цифр ни о чём не говорит.',
  },
  client_id_required: {
    title: 'Укажите Client-Id',
    description: 'Client-Id есть в кабинете продавца, в разделе с API-ключами.',
  },
  api_key_required: {
    title: 'Укажите Api-Key',
    description: 'Api-Key выпускается в кабинете продавца рядом с Client-Id.',
  },
}

export function connectAccountFailure(code: string | null): ConnectAccountFailure {
  if (code !== null && code in BY_CODE) {
    return BY_CODE[code] as ConnectAccountFailure
  }

  return {
    title: 'Не удалось подключить кабинет',
    description: 'Повторите попытку. Если повторяется — напишите нам.',
  }
}
```

- [ ] **Step 4: Убедиться, что тесты проходят**

```bash
make front-test APP=seller
```

Ожидание: четыре теста разбора зелёные.

- [ ] **Step 5: Написать падающий тест ключа кэша**

Создай `apps/seller/src/features/onboarding/model/useConnectAccount.test.ts`:

```ts
import { describe, expect, it } from 'vitest'

import { connectionsQueryKey } from '../../connections/model/useConnections'

// Обязательное покрытие §10 — изоляция кэша при смене компании.
// Без companyId в ключе клиент после переключения показал бы состояние
// подключений предыдущей компании, а бэкенд при этом отработал бы верно.
describe('ключ, который инвалидирует подключение', () => {
  it('различает компании', () => {
    expect(connectionsQueryKey('company-a')).not.toEqual(
      connectionsQueryKey('company-b'),
    )
  })

  it('содержит companyId', () => {
    expect(connectionsQueryKey('company-a')).toContain('company-a')
  })
})
```

- [ ] **Step 6: Написать хук мутации**

Создай `apps/seller/src/features/onboarding/model/useConnectAccount.ts`:

```ts
import { useMutation, useQueryClient } from '@tanstack/react-query'

import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { connectionsQueryKey } from '../../connections/model/useConnections'

type ConnectedAccountResponse = components['schemas']['ConnectedAccountResponse']

export interface ConnectAccountInput {
  name: string
  clientId: string
  apiKey: string
}

export function useConnectAccount(companyId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: ConnectAccountInput) =>
      createCompanyApiClient(companyId).post<ConnectedAccountResponse>(
        '/connections',
        input,
      ),
    onSuccess: () => {
      // Список подключений изменился, и от него зависит гейт онбординга:
      // оставить кэш прежним значит увести клиента обратно на форму
      // сразу после успешного подключения.
      void queryClient.invalidateQueries({
        queryKey: connectionsQueryKey(companyId),
      })
    },
  })
}
```

- [ ] **Step 7: Переписать экран онбординга**

Замени содержимое `apps/seller/src/features/auth/ui/OnboardingStartPage.tsx`:

```tsx
import { useState } from 'react'
import { CircleCheck, CircleX } from 'lucide-react'
import { Navigate, useSearchParams } from 'react-router'

import { ApiError } from '../../../api/ApiError'
import {
  Button,
  Card,
  Input,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import { connectAccountFailure } from '../../onboarding/lib/connectAccountError'
import { useConnectAccount } from '../../onboarding/model/useConnectAccount'
import { useCurrentUser } from '../model/useCurrentUser'

/**
 * Подключение кабинета — обязательный шаг, а не приглашение (ADR-021):
 * компания без активного подключения не имеет содержательного экрана,
 * и пустой дашборд с нулями не показывается никогда.
 *
 * companyId в пути /onboarding нет, а company-scoped запрос без него
 * невозможен. Источников два, и порядок между ними важен:
 *
 * 1. параметр ?company= — его ставит гейт CompanyLayout, который уже
 *    знает, какая именно компания осталась без подключения;
 * 2. единственная компания пользователя — после саморегистрации она
 *    ровно одна.
 *
 * Без первого источника участник двух компаний попадал бы в петлю:
 * гейт уводит сюда, здесь компаний больше одной, значит на /companies,
 * оттуда снова в ту же компанию и снова на гейт.
 */
export function OnboardingStartPage() {
  const currentUser = useCurrentUser()
  const [searchParams] = useSearchParams()

  if (currentUser.status !== 'success') {
    return null
  }

  const companies = currentUser.data.companies
  const requested = searchParams.get('company')

  // Параметр проверяется по членству, а не берётся на веру: адрес
  // правит кто угодно, а companyId из него уходит в запрос.
  const company =
    requested !== null
      ? companies.find((candidate) => candidate.id === requested)
      : companies.length === 1
        ? companies[0]
        : undefined

  if (company === undefined) {
    return <Navigate to="/companies" replace />
  }

  return <ConnectForm companyId={company.id} />
}

function ConnectForm({ companyId }: { companyId: string }) {
  const [name, setName] = useState('')
  const [clientId, setClientId] = useState('')
  const [apiKey, setApiKey] = useState('')
  const mutation = useConnectAccount(companyId)

  if (mutation.isSuccess) {
    return (
      <div className="flex min-h-screen items-center justify-center p-6">
        <div className="w-full max-w-sm">
          <Card>
            <StatusPanel
              icon={<CircleCheck aria-hidden="true" size={20} />}
              title="Кабинет подключён"
              description="Загружаем данные за текущий месяц. Это занимает несколько минут — экраны наполнятся сами."
              tone="accent"
              action={
                <Button
                  type="button"
                  variant="primary"
                  size="compact"
                  onClick={() => {
                    window.location.assign(`/companies/${companyId}/sales`)
                  }}
                >
                  Перейти к продажам
                </Button>
              }
            />
          </Card>
        </div>
      </div>
    )
  }

  const failure =
    mutation.error instanceof ApiError
      ? connectAccountFailure(mutation.error.code)
      : mutation.error instanceof Error
        ? connectAccountFailure(null)
        : null

  return (
    <div className="flex min-h-screen items-center justify-center p-6">
      <div className="w-full max-w-sm">
        <Card>
          <form
            className="flex flex-col gap-3"
            onSubmit={(event) => {
              event.preventDefault()
              mutation.mutate({ name, clientId, apiKey })
            }}
          >
            <h1 className="text-lg font-semibold">Подключите кабинет Ozon</h1>
            <p className="text-sm text-text-muted">
              Ключи проверяются сразу — сохраняем только рабочие.
            </p>

            <Input
              label="Название магазина"
              value={name}
              onChange={(event) => {
                setName(event.target.value)
              }}
            />
            <Input
              label="Client-Id"
              value={clientId}
              onChange={(event) => {
                setClientId(event.target.value)
              }}
            />
            <Input
              label="Api-Key"
              type="password"
              value={apiKey}
              onChange={(event) => {
                setApiKey(event.target.value)
              }}
            />

            {failure !== null && (
              <StatusPanel
                icon={<CircleX aria-hidden="true" size={20} />}
                title={failure.title}
                description={failure.description}
                role="alert"
                tone="negative"
              />
            )}

            <Button type="submit" variant="primary" disabled={mutation.isPending}>
              {mutation.isPending ? 'Проверяем ключи…' : 'Подключить'}
            </Button>
          </form>
        </Card>
      </div>
    </div>
  )
}
```

**Сверь с китом:** имена свойств `Input`, `Button` и `StatusPanel` возьми
из `packages/ui/src` — в примере выше они списаны
с `ReplaceCredentialsForm.tsx`, но если сигнатуры отличаются, прав кит,
а не этот план.

- [ ] **Step 8: Обновить тест экрана**

Открой `apps/seller/src/features/auth/ui/OnboardingStartPage.test.tsx` —
существующий тест проверяет текст заглушки и теперь неверен. Замени его
проверкой, что форма отправляет введённое и показывает разобранную
ошибку; ответы задавай через msw с типизацией по схеме
(`openapi-msw`), а не подменой `fetch`.

- [ ] **Step 9: Прогнать проверки фронтенда**

```bash
make front-typecheck APP=seller && make front-lint APP=seller && make front-test APP=seller
```

Ожидание: всё зелёное.

- [ ] **Step 10: Commit**

```bash
git add apps/seller/src/features/onboarding apps/seller/src/features/auth
git commit -m "Заменяет заглушку онбординга формой подключения"
```

---

### Task 7: Гейт онбординга и состояние загрузки

**Files:**
- Modify: `apps/seller/src/app/CompanyLayout.tsx`
- Create: `apps/seller/src/app/CompanyLayout.test.tsx`
- Modify: `apps/seller/src/features/ingestion/ui/SalesFactsPage.tsx`

**Interfaces:**
- Consumes: `useConnections(companyId)` (существует), форма из Task 6.
- Produces: редирект на `/onboarding?company=<companyId>` для компании без подключений.

- [ ] **Step 1: Написать падающий тест гейта**

Создай `apps/seller/src/app/CompanyLayout.test.tsx`. Тест рендерит
`CompanyLayout` внутри `MemoryRouter` с маршрутом
`/companies/:companyId/sales`, мокает `/api/auth/me` и
`/api/companies/:companyId/connections` через msw (пустой список
подключений) и проверяет, что отрисовался экран онбординга, а не
`Outlet`. Пример утверждения:

```tsx
expect(
  await screen.findByRole('heading', { name: 'Подключите кабинет Ozon' }),
).toBeInTheDocument()
```

- [ ] **Step 2: Убедиться, что тест падает**

```bash
make front-test APP=seller
```

Ожидание: заголовка нет — гейта не существует, отрисовался вложенный экран.

- [ ] **Step 3: Добавить гейт**

В `apps/seller/src/app/CompanyLayout.tsx` оберни содержимое так, чтобы
проверка шла внутри `RequireAuth` (иначе неаутентифицированный запрос
за подключениями даст 401 раньше редиректа на `/login`). Вынеси текущую
разметку в отдельный компонент `CompanyShell` и добавь перед ним:

```tsx
function ConnectionGate({ companyId }: { companyId: string }) {
  const connections = useConnections(companyId)

  // Пока список не прочитан, решения нет: показать оболочку сейчас
  // значит мигнуть пустым дашбордом и увести с него.
  if (connections.status === 'pending') {
    return null
  }

  // Компания без активного подключения не имеет содержательного экрана
  // (ADR-021). Гейт стоит здесь, а не на каждом экране: забыть его
  // в одном новом экране — вопрос времени, а последствие — ровно те
  // нули, ради отсутствия которых он написан.
  // companyId уходит параметром: онбординг обязан знать, какую именно
  // компанию подключают, иначе участник двух компаний ходит по кругу
  // между гейтом и экраном выбора.
  if (connections.status === 'success' && connections.data.items.length === 0) {
    return <Navigate to={`/onboarding?company=${encodeURIComponent(companyId)}`} replace />
  }

  return <CompanyShell companyId={companyId} />
}
```

**Сверь имя поля списка** (`items`) с
`components['schemas']['ConnectionsResponse']` в `schema.d.ts` — если оно
называется иначе, право за схемой.

- [ ] **Step 4: Убедиться, что тест проходит**

```bash
make front-test APP=seller
```

- [ ] **Step 5: Добавить состояние «данные загружаются»**

В `apps/seller/src/features/ingestion/ui/SalesFactsPage.tsx` добавь ветку
для пустого списка фактов:

```tsx
{query.status === 'success' && query.data.items.length === 0 && (
  <Card>
    <StatusPanel
      icon={<LoaderCircle aria-hidden="true" className="animate-spin" size={20} />}
      title="Данные загружаются"
      description="Мы забираем историю за текущий месяц. Экран наполнится сам — обновлять страницу не нужно."
      tone="accent"
    />
  </Card>
)}
```

Причина ветки, а не нулей: нуль неотличим от посчитанного нуля, и экран
с нулями выглядит исправным ровно тогда, когда данных ещё нет.

**Сверь** имена `query`, `items` и импорты с фактическим содержимым
файла.

- [ ] **Step 6: Прогнать проверки фронтенда**

```bash
make front-typecheck APP=seller && make front-lint APP=seller && make front-test APP=seller && make front-knip APP=seller
```

- [ ] **Step 7: Commit**

```bash
git add apps/seller/src/app apps/seller/src/features/ingestion
git commit -m "Уводит компанию без подключения на онбординг"
```

---

### Task 8: Документация, сквозная проверка и ревью

**Files:**
- Modify: `docs/structure.md`
- Modify: `docs/patterns.md`
- Create: `var/review/...` (артефакты пакета ревью)

**Interfaces:**
- Consumes: всё предыдущее.
- Produces: закрытая задача.

- [ ] **Step 1: Обновить карту репозитория**

В `docs/structure.md` добавь новые файлы: `ConnectOzonAccountAction`,
`ConnectOzonAccountResult`, `InitialBackfillWindow`,
`ConnectOzonAccountController`, `ConnectOzonAccountRequest`,
`ConnectedAccountResponse`, папку `apps/seller/src/features/onboarding/`.

- [ ] **Step 2: Обновить паттерны**

В `docs/patterns.md` добавь две записи:

- частичный уникальный индекс как приём и фактический исход разведки
  из Task 1 (объявляется ли он на сущности или только в миграции);
- глобальная уникальность обеспечивается индексом с перехватом
  конфликта, а не межарендаторным запросом перед вставкой — со ссылкой
  на §4 и на расхождение с текстом ADR-021.

- [ ] **Step 3: Прогнать весь конвейер**

```bash
make ci-local
```

Ожидание: зелёный целиком.

- [ ] **Step 4: Проверить миграцию с пустой базы ещё раз**

```bash
make db-rebuild-check
```

Ожидание: зелёный. В ветке ровно одна миграция — проверь
`git diff --stat master -- api/migrations`.

- [ ] **Step 5: Прогнать сквозной сценарий регистрации**

```bash
make test-e2e
```

Это и есть проверка пунктов 1–3 исходного обращения: подтверждение
адреса в другом браузере, статус на уровне аккаунта, переход
в рабочий сценарий. Результат записывается в отчёт фактом.

- [ ] **Step 6: Пройти финальную самопроверку**

Пройди чеклист из CLAUDE.md по порядку, первый пункт первым. Красный
пункт означает, что задача не закончена: исправить и пройти чеклист
заново, а не отметить и объяснить.

- [ ] **Step 7: Собрать пакет и получить ревью**

```bash
REVIEW_RISK=high \
TASK="Онбординг: подключение кабинета Ozon" \
CRITERIA="Критерии готовности из docs/superpowers/specs/2026-09-05-onboarding-ozon-connect-design.md" \
CHECKS="make ci-local, make db-rebuild-check, make test-e2e" \
REVIEW_PATHS_FILE=var/review/onboarding-paths.txt \
ADR="docs/adr/0021-self-signup-email-confirmation-onboarding.md docs/adr/0011-audit-trail-sufficiency.md docs/adr/0006-ingestion.md" \
make review
```

`REVIEW_RISK=high` обязателен: затронуты миграции и схема, изоляция
арендаторов и контракт API. Это Claude плюс оба прохода Codex.
Незавершённый прогон проверкой не считается — таймаут, пустой ответ,
план вместо заключения и `status=incomplete` означают отказ проверки:
сократить предмет пакета и повторить.

- [ ] **Step 8: Разобрать замечания**

Принять нарушения записанных правил и реальные дефекты; отклонить
вкусовое и отвергнутое в ADR — с одной строкой причины. Задача
не закрывается с невыполненным принятым замечанием. После исправлений
повторить затронутые тесты и ревью изменённых частей.

- [ ] **Step 9: Commit и merge**

```bash
git add docs
git commit -m "Обновляет документацию по онбордингу"
```

Merge в `master` выполняется по постоянному разрешению из CLAUDE.md
при выполненных предусловиях: зелёный конвейер, пройденный порог ревью,
исправленные принятые замечания. Миграция применяется к боевым данным
отдельным шагом после выкладки, с проверкой результата на проде.

---

## Самопроверка плана

**Покрытие спецификации.** Каждый раздел спецификации имеет задачу:
`marketplace_account.name` и частичный индекс — Task 1; запись
и аудит — Task 2; ступень 1 загрузки — Task 3 и Task 4; проверка ключей
и три исхода — Task 4; HTTP-контракт и коды — Task 5; форма, разбор
ошибок и выбор компании — Task 6; гейт и «данные загружаются» — Task 7;
документация, e2e и ревью — Task 8.

**Идентификатор созданного подключения.** Ответ 201 обязан назвать
созданный ресурс, поэтому идентификатор поднимается снизу вверх, а не
дочитывается сверху: `MarketplaceAccountConnection` (Task 2) несёт его
из фасада, `ConnectOzonAccountOutcome` (Task 4) — из сценария, а
контроллер (Task 5) только подставляет. Второго чтения подключений
компании после записи нет нигде.

**Согласованность имён.** `connectOzonAccount`, `tryConnect`,
`ConnectOzonAccountResult`, `ConnectOzonAccountOutcome`,
`MarketplaceAccountConnection`, `MarketplaceAccountConnectionOutcome`,
`InitialBackfillWindow::businessDates`, `connectAccountFailure`,
`useConnectAccount`, `connectionsQueryKey` — используются в задачах
в одном написании.
