<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\PurgeUnconfirmedAccountsAction;
use App\Identity\Application\ResendEmailVerificationAction;
use App\Identity\Domain\AuditAction;
use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\EmailVerificationToken;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Repository\DoctrineAuditRecordRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineExtensionTokenRepository;
use App\Identity\Infrastructure\Repository\DoctrineMarketplaceAccountRepository;
use App\Identity\Infrastructure\Repository\DoctrineUnconfirmedAccountCleaner;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceExpenseFactWriter;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceListingCostRepository;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceListingPriceWriter;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceListingWriter;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceRawDocumentRepository;
use App\Ingestion\Infrastructure\Persistence\DoctrineSalesFactWriter;
use App\PriceMonitoring\Infrastructure\Persistence\DoctrinePriceObservationWriter;
use App\PriceMonitoring\Infrastructure\Repository\DoctrineTrackedSkuRepository;
use App\Tests\Support\Builder\AuditRecordBuilder;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\EmailVerificationTokenBuilder;
use App\Tests\Support\Builder\ExtensionTokenBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceExpenseFactBuilder;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use App\Tests\Support\Builder\MarketplaceListingCostBuilder;
use App\Tests\Support\Builder\MarketplaceListingPriceBuilder;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Builder\PriceObservationBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use App\Tests\Support\Builder\TrackedSkuBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class PurgeUnconfirmedAccountsCommandTest extends KernelTestCase
{
    public function testResendTakesSharedLockBeforeLookingUpTheUser(): void
    {
        self::bootKernel();
        $maintenance = $this->independentConnection();
        $maintenance->beginTransaction();
        $maintenance->executeStatement(
            "SELECT pg_advisory_xact_lock(hashtextextended('conwix.identity.email-verification-maintenance', 0))",
        );

        try {
            $this->connection()->executeStatement("SET LOCAL lock_timeout = '100ms'");
            $this->expectException(DriverException::class);
            $this->expectExceptionMessage('lock timeout');

            $this->resendAction()('unknown-lock@example.test', new \DateTimeImmutable());
        } finally {
            $maintenance->rollBack();
            $maintenance->close();
        }
    }

    public function testCleanupTakesExclusiveLockAgainstEmailConfirmation(): void
    {
        self::bootKernel();
        $confirmation = $this->independentConnection();
        $confirmation->beginTransaction();
        $confirmation->executeStatement(
            "SELECT pg_advisory_xact_lock_shared(hashtextextended('conwix.identity.email-verification-maintenance', 0))",
        );

        try {
            $this->connection()->executeStatement("SET LOCAL lock_timeout = '100ms'");
            $this->expectException(DriverException::class);

            ($this->action())(new \DateTimeImmutable('1970-01-01T00:00:00+00:00'));
        } finally {
            $confirmation->rollBack();
            $confirmation->close();
        }
    }

    public function testCommandDeletesOnlyTheWholeAbandonedAccountGraph(): void
    {
        self::bootKernel();
        [$company, $user, $token] = $this->selfRegisteredAccount(new \DateTimeImmutable('-31 days'));

        $tester = new CommandTester($this->command());
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Удалено компаний: 1', $tester->getDisplay());
        self::assertStringContainsString('Граница создания:', $tester->getDisplay());
        self::assertFalse($this->rowExists('company', 'id', $company->id()->toRfc4122()));
        self::assertFalse($this->rowExists('company_member', 'company_id', $company->id()->toRfc4122()));
        self::assertFalse($this->rowExists('user', 'id', $user->id()->toRfc4122(), quotedTable: true));
        self::assertFalse($this->rowExists('email_verification_token', 'id', $token->id()->toRfc4122()));
        self::assertSame(0, $this->auditCount($company));
    }

    #[DataProvider('protectionKinds')]
    public function testAnyProtectedStateKeepsTheCompany(string $kind): void
    {
        self::bootKernel();
        $createdAt = new \DateTimeImmutable('newer_company' === $kind ? '-29 days' : '-31 days');
        [$company, $user, $token] = $this->selfRegisteredAccount(
            $createdAt,
            confirmed: 'confirmed_user' === $kind,
        );
        if (!\in_array($kind, ['confirmed_user', 'newer_company'], true)) {
            $this->addProtection($kind, $company, $user);
        }

        $deleted = ($this->action())(new \DateTimeImmutable('-30 days'));

        self::assertSame(0, $deleted, $kind);
        self::assertTrue($this->rowExists('company', 'id', $company->id()->toRfc4122()), $kind);
        self::assertTrue($this->rowExists('user', 'id', $user->id()->toRfc4122(), quotedTable: true), $kind);
        self::assertTrue($this->rowExists('email_verification_token', 'id', $token->id()->toRfc4122()), $kind);
        self::assertSame(1, $this->auditCount($company), $kind);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function protectionKinds(): iterable
    {
        yield 'confirmed user' => ['confirmed_user'];
        yield 'marketplace account' => ['marketplace_account'];
        yield 'extension token' => ['extension_token'];
        yield 'fresh confirmation token' => ['live_confirmation_token'];
        yield 'sales fact' => ['sales_fact'];
        yield 'expense fact' => ['marketplace_expense_fact'];
        yield 'raw document' => ['marketplace_raw_document'];
        yield 'listing' => ['marketplace_listing'];
        yield 'listing cost' => ['marketplace_listing_cost'];
        yield 'listing price' => ['marketplace_listing_price'];
        yield 'tracked sku' => ['tracked_sku'];
        yield 'price observation' => ['price_observation'];
        yield 'newer than cutoff' => ['newer_company'];
    }

    public function testCompanyCreatedExactlyAtCutoffIsRetained(): void
    {
        self::bootKernel();
        $cutoff = new \DateTimeImmutable('2026-08-03T12:00:00+00:00');
        [$company] = $this->selfRegisteredAccount($cutoff);

        self::assertSame(0, ($this->action())($cutoff));
        self::assertTrue($this->rowExists('company', 'id', $company->id()->toRfc4122()));
    }

    public function testSharedUserSurvivesWithMembershipAndTokenInRetainedCompany(): void
    {
        self::bootKernel();
        [$abandoned, $user, $token] = $this->selfRegisteredAccount(new \DateTimeImmutable('-31 days'));
        /** @var CompanyRepository $companies */
        $companies = self::getContainer()->get(CompanyRepository::class);
        $retained = CompanyBuilder::aCompany()->withName('Retained tenant')->persistWith($companies);
        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($retained)
            ->withUser($user)
            ->persistWith(
                $companies,
                new DoctrineUserRepository($this->entityManager()),
                new DoctrineCompanyMemberRepository($this->entityManager()),
            );

        self::assertSame(1, ($this->action())(new \DateTimeImmutable('-30 days')));
        self::assertFalse($this->rowExists('company', 'id', $abandoned->id()->toRfc4122()));
        self::assertTrue($this->rowExists('company', 'id', $retained->id()->toRfc4122()));
        self::assertTrue($this->rowExists('user', 'id', $user->id()->toRfc4122(), quotedTable: true));
        self::assertTrue($this->rowExists('email_verification_token', 'id', $token->id()->toRfc4122()));
        self::assertTrue($this->rowExists('company_member', 'company_id', $retained->id()->toRfc4122()));
    }

    /**
     * @return array{Company, User, EmailVerificationToken}
     */
    private function selfRegisteredAccount(\DateTimeImmutable $companyCreatedAt, bool $confirmed = false): array
    {
        /** @var CompanyRepository $companies */
        $companies = self::getContainer()->get(CompanyRepository::class);
        $users = new DoctrineUserRepository($this->entityManager());
        $members = new DoctrineCompanyMemberRepository($this->entityManager());
        $company = CompanyBuilder::aCompany()
            ->withName('Abandoned '.Uuid::v7()->toRfc4122())
            ->withCreatedAt($companyCreatedAt)
            ->persistWith($companies);
        $userBuilder = UserBuilder::aUser()
            ->withEmail(\sprintf('abandoned-%s@example.test', Uuid::v7()->toRfc4122()));
        if (!$confirmed) {
            $userBuilder = $userBuilder->unconfirmed();
        }
        $user = $userBuilder->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($company)
            ->withUser($user)
            ->persistWith($companies, $users, $members);
        $token = EmailVerificationTokenBuilder::aToken($user, hash('sha256', Uuid::v7()->toRfc4122()))
            ->withIssuedAt($companyCreatedAt)
            ->persistWith($this->entityManager());
        AuditRecordBuilder::anAuditRecord()
            ->withCompanyId($company->id())
            ->withActorUserId($user->id())
            ->withAction(AuditAction::CompanyRegistered)
            ->withSubjectId($company->id())
            ->withChange(null, $user->email())
            ->withOccurredAt($companyCreatedAt)
            ->persistWith(new DoctrineAuditRecordRepository($this->entityManager()));
        $this->entityManager()->flush();

        return [$company, $user, $token];
    }

    private function addProtection(string $kind, Company $company, User $user): void
    {
        $connection = $this->connection();

        match ($kind) {
            'marketplace_account' => MarketplaceAccountBuilder::aMarketplaceAccount()
                ->withCompany($company)
                ->withExternalShopId(Uuid::v7()->toRfc4122())
                ->persistWith($this->companies(), new DoctrineMarketplaceAccountRepository($this->entityManager())),
            'extension_token' => ExtensionTokenBuilder::anExtensionToken()
                ->withCompany($company)
                ->withUser($user)
                ->persistWith(
                    $this->companies(),
                    new DoctrineUserRepository($this->entityManager()),
                    new DoctrineExtensionTokenRepository($this->entityManager()),
                ),
            'live_confirmation_token' => EmailVerificationTokenBuilder::aToken(
                $user,
                hash('sha256', Uuid::v7()->toRfc4122()),
            )->withIssuedAt(new \DateTimeImmutable())->persistWith($this->entityManager()),
            'sales_fact' => SalesFactBuilder::aSalesFact()
                ->withCompanyId($company->id())
                ->withSourceRowId(Uuid::v7()->toRfc4122())
                ->persistWith(new DoctrineSalesFactWriter($connection)),
            'marketplace_expense_fact' => MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()
                ->withCompanyId($company->id())
                ->withAccrualId(random_int(1, \PHP_INT_MAX))
                ->persistWith(new DoctrineMarketplaceExpenseFactWriter($connection)),
            'marketplace_raw_document' => MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
                ->withCompanyId($company->id())
                ->withRawBody(json_encode(['id' => Uuid::v7()->toRfc4122()], \JSON_THROW_ON_ERROR))
                ->persistWith(new DoctrineMarketplaceRawDocumentRepository($connection)),
            'marketplace_listing' => $this->protectWithListing($company),
            'marketplace_listing_cost' => MarketplaceListingCostBuilder::aMarketplaceListingCost()
                ->withCompanyId($company->id())
                ->persistWith(new DoctrineMarketplaceListingCostRepository($this->entityManager())),
            'marketplace_listing_price' => $this->protectWithListingPrice($company),
            'tracked_sku' => TrackedSkuBuilder::aTrackedSku()
                ->withCompany($company)
                ->withCreatedBy($user)
                ->persistWith(new DoctrineTrackedSkuRepository($connection)),
            'price_observation' => PriceObservationBuilder::aPriceObservation()
                ->withCompany($company)
                ->withCapturedBy($user)
                ->persistWith(new DoctrinePriceObservationWriter($connection)),
            default => throw new \LogicException('Unknown protection kind '.$kind),
        };
    }

    private function protectWithListing(Company $company): void
    {
        $accountId = Uuid::v7();
        $listing = MarketplaceListingBuilder::aMarketplaceListing()
            ->withCompanyId($company->id())
            ->withMarketplaceAccountId($accountId)
            ->build();
        (new DoctrineMarketplaceListingWriter($this->connection()))->replaceForAccount(
            $company->id()->toRfc4122(),
            $accountId,
            [$listing],
        );
    }

    private function protectWithListingPrice(Company $company): void
    {
        $price = MarketplaceListingPriceBuilder::aMarketplaceListingPrice()
            ->withCompanyId($company->id())
            ->withRawDocumentId(Uuid::v7())
            ->build();
        (new DoctrineMarketplaceListingPriceWriter($this->connection()))->recordChanged(
            $company->id()->toRfc4122(),
            [$price],
        );
    }

    private function rowExists(string $table, string $column, string $value, bool $quotedTable = false): bool
    {
        $tableSql = $quotedTable ? '"'.$table.'"' : $table;

        return false !== $this->connection()->fetchOne(
            "SELECT 1 FROM {$tableSql} WHERE {$column} = :value LIMIT 1",
            ['value' => $value],
        );
    }

    private function auditCount(Company $company): int
    {
        $count = $this->connection()->fetchOne(
            'SELECT count(*) FROM audit_record WHERE company_id = :company',
            ['company' => $company->id()->toRfc4122()],
        );
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function action(): PurgeUnconfirmedAccountsAction
    {
        return new PurgeUnconfirmedAccountsAction(new DoctrineUnconfirmedAccountCleaner($this->connection()));
    }

    private function resendAction(): ResendEmailVerificationAction
    {
        /** @var ResendEmailVerificationAction $action */
        $action = self::getContainer()->get(ResendEmailVerificationAction::class);

        return $action;
    }

    private function command(): Command
    {
        /** @var \Symfony\Component\HttpKernel\KernelInterface $kernel */
        $kernel = self::$kernel;

        return (new Application($kernel))->find('app:identity:purge-unconfirmed-accounts');
    }

    private function companies(): CompanyRepository
    {
        /** @var CompanyRepository $companies */
        $companies = self::getContainer()->get(CompanyRepository::class);

        return $companies;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }

    private function independentConnection(): Connection
    {
        $params = $this->connection()->getParams();

        return DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => $params['host'],
            'port' => $params['port'],
            'user' => $params['user'],
            'password' => $params['password'],
            'dbname' => $params['dbname'],
            'serverVersion' => $params['serverVersion'],
        ]);
    }
}
