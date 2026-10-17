<?php

declare(strict_types=1);

namespace App\Tests\Integration\SalesPlanning;

use App\SalesPlanning\Domain\SalesPlan;
use App\SalesPlanning\Domain\SalesPlanLine;
use App\SalesPlanning\Infrastructure\Persistence\DoctrineSalesPlanRepository;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\SalesPlanBuilder;
use App\Tests\Support\Builder\SalesPlanLineBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineSalesPlanRepositoryTest extends KernelTestCase
{
    public function testStoresPlanWithEveryDayOfEverySku(): void
    {
        self::bootKernel();
        $repository = new DoctrineSalesPlanRepository($this->entityManager());
        $plan = SalesPlanBuilder::aSalesPlan()->build();
        $lines = [
            ...SalesPlanLineBuilder::linesOf($plan)->withMarketplaceSku('111')->withTotalQuantity(100)->build(),
            ...SalesPlanLineBuilder::linesOf($plan)->withMarketplaceSku('222')->withTotalQuantity(7)->build(),
        ];

        $repository->add($plan, $lines);

        $rows = $this->connection()->fetchAllAssociative(
            'SELECT marketplace_sku, COUNT(*) AS days, SUM(quantity) AS quantity
               FROM sales_plan_line
              WHERE company_id = :companyId AND sales_plan_id = :planId
              GROUP BY marketplace_sku
              ORDER BY marketplace_sku',
            ['companyId' => $plan->companyId()->toRfc4122(), 'planId' => $plan->id()->toRfc4122()],
        );

        self::assertSame([
            ['marketplace_sku' => '111', 'days' => 31, 'quantity' => 100],
            ['marketplace_sku' => '222', 'days' => 31, 'quantity' => 7],
        ], $rows);
    }

    public function testPlanIsNotReadableThroughAnotherCompany(): void
    {
        self::bootKernel();
        $repository = new DoctrineSalesPlanRepository($this->entityManager());
        $plan = SalesPlanBuilder::aSalesPlan()->persistWith($repository);
        $this->entityManager()->clear();

        self::assertNull($repository->get(Uuid::v7()->toRfc4122(), $plan->id()));

        $loaded = $repository->get($plan->companyId()->toRfc4122(), $plan->id());
        self::assertNotNull($loaded);
        self::assertTrue($loaded->id()->equals($plan->id()));
    }

    public function testDayChangeRaisesPlanVersion(): void
    {
        self::bootKernel();
        $entityManager = $this->entityManager();
        $repository = new DoctrineSalesPlanRepository($entityManager);
        $plan = SalesPlanBuilder::aSalesPlan()->build();
        $repository->add($plan, SalesPlanLineBuilder::linesOf($plan)->build());
        $entityManager->clear();

        $loaded = $repository->get($plan->companyId()->toRfc4122(), $plan->id());
        self::assertNotNull($loaded);
        self::assertSame(1, $loaded->version());
        $line = $entityManager->createQuery(
            'SELECT l FROM '.SalesPlanLine::class.' l WHERE l.companyId = :companyId AND l.salesPlanId = :planId ORDER BY l.planDate',
        )
            ->setParameter('companyId', $plan->companyId(), 'uuid')
            ->setParameter('planId', $plan->id(), 'uuid')
            ->setMaxResults(1)
            ->getSingleResult();
        \assert($line instanceof SalesPlanLine);

        $line->changeTo($loaded, 5, Money::ofMinor(99_900, 'RUB'), new \DateTimeImmutable('2026-06-26 09:00:00'));
        $entityManager->flush();

        self::assertSame(2, $loaded->version());
    }

    public function testSecondDraftInOneLineageIsRefusedByDatabase(): void
    {
        self::bootKernel();
        $plan = SalesPlanBuilder::aSalesPlan()->persistWith(new DoctrineSalesPlanRepository($this->entityManager()));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertCopy($plan, ['status' => 'draft', 'version_number' => 2]);
    }

    public function testDatabaseRefusesNonPositivePrice(): void
    {
        self::bootKernel();
        $plan = SalesPlanBuilder::aSalesPlan()->persistWith(new DoctrineSalesPlanRepository($this->entityManager()));

        $this->expectException(DriverException::class);

        $this->connection()->insert('sales_plan_line', [
            'id' => Uuid::v7()->toRfc4122(),
            'company_id' => $plan->companyId()->toRfc4122(),
            'sales_plan_id' => $plan->id()->toRfc4122(),
            'marketplace_sku' => '111',
            'plan_date' => '2026-07-01',
            'quantity' => 1,
            'price_minor' => 0,
        ]);
    }

    /**
     * Вторая шапка той же линии в обход домена: домен второй черновик
     * создать не даёт, а проверяется здесь последняя линия — индекс.
     *
     * @param array<string, int|string> $overrides
     */
    private function insertCopy(SalesPlan $plan, array $overrides): void
    {
        $this->connection()->insert('sales_plan', [
            'id' => Uuid::v7()->toRfc4122(),
            'company_id' => $plan->companyId()->toRfc4122(),
            'marketplace_account_id' => $plan->marketplaceAccountId()->toRfc4122(),
            'name' => $plan->name(),
            'date_from' => $plan->dateFrom()->format('Y-m-d'),
            'date_to' => $plan->dateTo()->format('Y-m-d'),
            'currency' => $plan->currency(),
            'lineage_id' => $plan->lineageId()->toRfc4122(),
            'created_by_user_id' => $plan->createdByUserId()->toRfc4122(),
            'created_at' => '2026-06-25 12:00:00',
            'updated_at' => '2026-06-25 12:00:00',
            ...$overrides,
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
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
