<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\MarketplacePostingStatus;
use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\MarketplaceReturnFactRepository;
use App\Ingestion\Domain\SalesFact;
use App\Ingestion\Domain\SalesFactRepository;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\MarketplaceReturnFactBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** ADR-030: buyout_outcome_as_of не видит будущего и на «сейчас» равен view. */
final class BuyoutOutcomeAsOfTest extends KernelTestCase
{
    private Uuid $companyId;
    private Uuid $accountId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
    }

    public function testSourcesLoadedOrObservedAfterAsOfAreInvisible(): void
    {
        $this->sales()->upsertAll([$this->sale('EARLY', 'ORDER-EARLY', 2)]);
        // Момент загрузки первой продажи и есть дата «на» — следующая
        // загрузка по построению позже.
        $asOf = $this->firstLoadedAt('EARLY|SKU');
        // first_loaded_at хранится с точностью до секунды: всё, что
        // загружено после даты «на», должно попасть в следующую секунду.
        sleep(1);

        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), [
            $this->postingStatus('EARLY', 'ORDER-EARLY', 'delivering', $asOf->modify('-1 hour')),
            $this->postingStatus('EARLY', 'ORDER-EARLY', 'delivered', $asOf->modify('+1 hour')),
        ]);
        $this->returns()->upsertAll([
            MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
                ->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($this->accountId)
                ->withSourceRowId('RET-EARLY')
                ->withPostingNumber('EARLY')
                ->withOrderNumber('ORDER-EARLY')
                ->withMarketplaceSku('SKU')
                ->withReturnType('ClientReturn')
                ->withReturnReasonName('Возврат покупателя')
                ->withQuantity(1)
                ->build(),
        ]);
        $this->sales()->upsertAll([$this->sale('LATE', 'ORDER-LATE', 1)]);

        $past = $this->outcomes($asOf->format('Y-m-d H:i:s'));
        self::assertSame([['EARLY', null, true, 2]], $past);

        $present = $this->outcomes('infinity');
        self::assertSame([
            ['EARLY', 'D', false, 1],
            ['EARLY', 'R', false, 1],
            ['LATE', null, false, 1],
        ], $present);
    }

    public function testAtInfinityMatchesViewRowForRowAndStaysTenantScoped(): void
    {
        $this->sales()->upsertAll([
            $this->sale('DELIVERED', 'ORDER-D', 3),
            $this->sale('PENDING', 'ORDER-P', 1),
        ]);
        $observed = new \DateTimeImmutable('2026-08-01 10:00:00', new \DateTimeZone('UTC'));
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), [
            $this->postingStatus('DELIVERED', 'ORDER-D', 'delivering', $observed),
            $this->postingStatus('DELIVERED', 'ORDER-D', 'delivered', $observed->modify('+1 day')),
            $this->postingStatus('PENDING', 'ORDER-P', 'awaiting_packaging', $observed),
        ]);

        $foreignCompany = Uuid::v7();
        $this->sales()->upsertAll([
            SalesFactBuilder::aSalesFact()
                ->withCompanyId($foreignCompany)
                ->withMarketplaceAccountId(Uuid::v7())
                ->withSourceRowId('FOREIGN|SKU')
                ->withPostingNumber('FOREIGN')
                ->withOrderNumber('FOREIGN')
                ->withMarketplaceSku('SKU')
                ->build(),
        ]);

        $companyId = $this->companyId->toRfc4122();
        $difference = $this->connection()->fetchAllNumeric(
            <<<'SQL'
                (SELECT * FROM buyout_outcome WHERE company_id = :companyId
                 EXCEPT ALL
                 SELECT * FROM buyout_outcome_as_of(:companyId, 'infinity'))
                UNION ALL
                (SELECT * FROM buyout_outcome_as_of(:companyId, 'infinity')
                 EXCEPT ALL
                 SELECT * FROM buyout_outcome WHERE company_id = :companyId)
                SQL,
            ['companyId' => $companyId],
        );

        self::assertSame([], $difference);
        self::assertEquals(2, $this->connection()->fetchOne(
            "SELECT COUNT(*) FROM buyout_outcome_as_of(:companyId, 'infinity')",
            ['companyId' => $companyId],
        ));
    }

    /** @return list<array{string, ?string, bool, int}> */
    private function outcomes(string $asOf): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            <<<'SQL'
                SELECT posting_number, outcome, is_in_flight, quantity
                FROM buyout_outcome_as_of(:companyId, CAST(:asOf AS timestamp))
                ORDER BY posting_number, outcome NULLS LAST
                SQL,
            ['companyId' => $this->companyId->toRfc4122(), 'asOf' => $asOf],
        );

        return array_map(static function (array $row): array {
            $posting = $row['posting_number'];
            $outcome = $row['outcome'];
            $quantity = $row['quantity'];
            self::assertIsString($posting);
            self::assertTrue(null === $outcome || \is_string($outcome));
            self::assertIsBool($row['is_in_flight']);
            self::assertIsInt($quantity);

            return [$posting, $outcome, $row['is_in_flight'], $quantity];
        }, $rows);
    }

    private function firstLoadedAt(string $sourceRowId): \DateTimeImmutable
    {
        $value = $this->connection()->fetchOne(
            'SELECT first_loaded_at FROM sales_fact WHERE company_id = ? AND source_row_id = ?',
            [$this->companyId->toRfc4122(), $sourceRowId],
        );
        self::assertIsString($value);

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    private function sale(string $posting, string $order, int $quantity): SalesFact
    {
        return SalesFactBuilder::aSalesFact()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withSourceRowId($posting.'|SKU')
            ->withPostingNumber($posting)
            ->withOrderNumber($order)
            ->withMarketplaceSku('SKU')
            ->withStatus('delivered')
            ->withQuantity($quantity)
            ->withBusinessDate(new \DateTimeImmutable('2026-08-01'))
            ->build();
    }

    private function postingStatus(string $posting, string $order, string $status, \DateTimeImmutable $observedAt): MarketplacePostingStatus
    {
        return MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withPostingNumber($posting)
            ->withOrderNumber($order)
            ->withStatus($status)
            ->withObservedAt($observedAt)
            ->withRawDocumentId(Uuid::v7())
            ->build();
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }

    private function sales(): SalesFactRepository
    {
        /** @var SalesFactRepository $repository */
        $repository = self::getContainer()->get(SalesFactRepository::class);

        return $repository;
    }

    private function postingStatuses(): MarketplacePostingStatusRepository
    {
        /** @var MarketplacePostingStatusRepository $repository */
        $repository = self::getContainer()->get(MarketplacePostingStatusRepository::class);

        return $repository;
    }

    private function returns(): MarketplaceReturnFactRepository
    {
        /** @var MarketplaceReturnFactRepository $repository */
        $repository = self::getContainer()->get(MarketplaceReturnFactRepository::class);

        return $repository;
    }
}
