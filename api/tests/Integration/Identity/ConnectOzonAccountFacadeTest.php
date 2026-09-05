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
        self::assertIsNumeric($count);
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
