<?php

declare(strict_types=1);

namespace App\Tests\Integration\Planning;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\MarketplaceListingRepository;
use App\Planning\Application\ApplyPlanImportAction;
use App\Planning\Application\SaveDailyPlanAction;
use App\Planning\Domain\DailyPlanRepository;
use App\Planning\Domain\PlanChange;
use App\Planning\Domain\PlanImportPreview;
use App\Planning\Domain\PlanImportPreviewRow;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\DailyPlanBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use App\Tests\Support\Builder\PlanImportPreviewBuilder;
use App\Tests\Support\Builder\UserBuilder;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[SkipDatabaseRollback]
final class PlanImportConcurrencyTest extends KernelTestCase
{
    public function testSecondApplyWaitsForThePreviewLockHeldByFirstApply(): void
    {
        [$company, $account, $actor, $preview] = $this->fixture();
        $competing = $this->independentConnection();
        $competing->beginTransaction();
        $competing->executeQuery('SELECT id FROM planning_import_preview WHERE id = ? FOR UPDATE', [$preview->id()->toRfc4122()]);

        try {
            $this->connection()->executeStatement("SET lock_timeout = '100ms'");
            try {
                $this->apply()($company->id()->toRfc4122(), $account->id()->toRfc4122(), $preview->id()->toRfc4122(), $actor->id()->toRfc4122());
                self::fail('Второй apply не должен проходить блокировку preview первого apply.');
            } catch (DriverException $exception) {
                self::assertStringContainsString('lock timeout', $exception->getMessage());
            }
        } finally {
            $competing->rollBack();
            $competing->close();
            $this->cleanup($company, $actor);
        }
    }

    public function testManualSaveWaitsForThePlanLockHeldByImport(): void
    {
        [$company, $account, $actor] = $this->fixture();
        $plan = DailyPlanBuilder::aDailyPlan()->withCompanyId($company->id())->withMarketplaceAccountId($account->id())
            ->withUpdatedBy($actor->id())->withBusinessDate(new \DateTimeImmutable('2026-09-22'))->build();
        $this->plans()->add($plan, PlanChange::created($plan));
        $this->entityManager()->clear();

        $competing = $this->independentConnection();
        $competing->beginTransaction();
        $competing->executeQuery(
            'SELECT id FROM planning_daily_plan WHERE company_id = ? AND marketplace_account_id = ? AND marketplace_sku = ? AND business_date = ? FOR UPDATE',
            [$company->id()->toRfc4122(), $account->id()->toRfc4122(), 'SKU-1', '2026-09-22'],
        );

        try {
            $this->connection()->executeStatement("SET lock_timeout = '100ms'");
            try {
                $this->save()(
                    $company->id()->toRfc4122(), $account->id()->toRfc4122(), 'SKU-1',
                    new \DateTimeImmutable('2026-09-22'), 13, 1, $actor->id()->toRfc4122(),
                );
                self::fail('Ручное сохранение не должно проходить блокировку плана, удерживаемую import apply.');
            } catch (DriverException $exception) {
                self::assertStringContainsString('lock timeout', $exception->getMessage());
            }
        } finally {
            $competing->rollBack();
            $competing->close();
            $this->cleanup($company, $actor);
        }
    }

    /** @return array{Company, MarketplaceAccount, User, PlanImportPreview} */
    private function fixture(): array
    {
        self::bootKernel();
        $companies = $this->companies();
        $users = new DoctrineUserRepository($this->entityManager());
        $members = new DoctrineCompanyMemberRepository($this->entityManager());
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $actor = UserBuilder::aUser()->withEmail('planning-concurrency-'.bin2hex(random_bytes(6)).'@example.test')->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($actor)->persistWith($companies, $users, $members);
        $account = MarketplaceAccountBuilder::aMarketplaceAccount()->withCompany($company)
            ->withExternalShopId('planning-concurrency-'.bin2hex(random_bytes(6)))->persistWith($companies, $this->accounts());
        $this->listings()->replaceForAccount($company->id()->toRfc4122(), $account->id(), [
            MarketplaceListingBuilder::aMarketplaceListing()->withCompanyId($company->id())
                ->withMarketplaceAccountId($account->id())->withMarketplaceSku('SKU-1')->build(),
        ]);
        $preview = PlanImportPreviewBuilder::aPlanImportPreview()->withCompanyId($company->id())
            ->withMarketplaceAccountId($account->id())->withActorId($actor->id())
            ->withCreatedAt(new \DateTimeImmutable())->withRows([
                new PlanImportPreviewRow(2, 'SKU-1', null, '2026-09-22', 12, 0, null, 'new'),
            ])->build();
        $this->entityManager()->persist($preview);
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        return [$company, $account, $actor, $preview];
    }

    private function cleanup(Company $company, User $actor): void
    {
        $connection = $this->connection();
        while ($connection->getTransactionNestingLevel() > 0) {
            $connection->rollBack();
        }
        $connection->executeStatement("SET lock_timeout = '0'");
        $this->entityManager()->clear();
        $companyId = $company->id()->toRfc4122();
        foreach (['planning_plan_change', 'planning_daily_plan', 'planning_import_preview', 'marketplace_listing', 'marketplace_account', 'company_member', 'audit_record'] as $table) {
            $connection->executeStatement("DELETE FROM {$table} WHERE company_id = ?", [$companyId]);
        }
        $connection->executeStatement('DELETE FROM company WHERE id = ?', [$companyId]);
        $connection->executeStatement('DELETE FROM "user" WHERE id = ?', [$actor->id()->toRfc4122()]);
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

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function companies(): CompanyRepository
    {
        /** @var CompanyRepository $repository */
        $repository = self::getContainer()->get(CompanyRepository::class);

        return $repository;
    }

    private function accounts(): MarketplaceAccountRepository
    {
        /** @var MarketplaceAccountRepository $repository */
        $repository = self::getContainer()->get(MarketplaceAccountRepository::class);

        return $repository;
    }

    private function listings(): MarketplaceListingRepository
    {
        /** @var MarketplaceListingRepository $repository */
        $repository = self::getContainer()->get(MarketplaceListingRepository::class);

        return $repository;
    }

    private function plans(): DailyPlanRepository
    {
        /** @var DailyPlanRepository $repository */
        $repository = self::getContainer()->get(DailyPlanRepository::class);

        return $repository;
    }

    private function apply(): ApplyPlanImportAction
    {
        /** @var ApplyPlanImportAction $action */
        $action = self::getContainer()->get(ApplyPlanImportAction::class);

        return $action;
    }

    private function save(): SaveDailyPlanAction
    {
        /** @var SaveDailyPlanAction $action */
        $action = self::getContainer()->get(SaveDailyPlanAction::class);

        return $action;
    }
}
