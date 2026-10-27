<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\MarketplaceReturnFactRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortMetricsQuery;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\MarketplaceReturnFactBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SalesCohortMetricsQueryTest extends KernelTestCase
{
    public function testSubtractsOnlyConfirmedPreHandoverCancellationsWithoutChangingFacts(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $date = new \DateTimeImmutable('2026-08-01');
        $sale = SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withMarketplaceSku('COHORT')
            ->withBusinessDate($date);
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        $sales->upsertAll([
            $sale->withSourceRowId('CANCELLED|COHORT')->withPostingNumber('CANCELLED')
                ->withOrderNumber('CANCELLED')->withStatus('cancelled')->withQuantity(10)->build(),
            $sale->withSourceRowId('PENDING|COHORT')->withPostingNumber('PENDING')
                ->withOrderNumber('PENDING')->withStatus('awaiting_packaging')->withQuantity(90)->build(),
            $sale->withCompanyId(Uuid::v7())->withSourceRowId('FOREIGN|COHORT')
                ->withPostingNumber('FOREIGN')->withOrderNumber('FOREIGN')->withQuantity(777)->build(),
            $sale->withMarketplaceAccountId(Uuid::v7())->withSourceRowId('OTHER-ACCOUNT|COHORT')
                ->withPostingNumber('OTHER-ACCOUNT')->withOrderNumber('OTHER-ACCOUNT')->withQuantity(888)->build(),
            $sale->withMarketplaceSku('OUTSIDE')->withSourceRowId('OUTSIDE|OUTSIDE')
                ->withPostingNumber('OUTSIDE')->withOrderNumber('OUTSIDE')->withQuantity(333)->build(),
            $sale->withBusinessDate($date->modify('+1 day'))->withSourceRowId('TOMORROW|COHORT')
                ->withPostingNumber('TOMORROW')->withOrderNumber('TOMORROW')->withQuantity(444)->build(),
        ]);
        $status = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId);
        /** @var MarketplacePostingStatusRepository $statuses */
        $statuses = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $statuses->recordChanged($companyId->toRfc4122(), [
            $status->withPostingNumber('CANCELLED')->withOrderNumber('CANCELLED')
                ->withStatus('awaiting_packaging')->withObservedAt(new \DateTimeImmutable('2026-08-01 10:00:00'))
                ->withRawDocumentId(Uuid::v7())->build(),
            $status->withPostingNumber('CANCELLED')->withOrderNumber('CANCELLED')
                ->withStatus('cancelled')->withObservedAt(new \DateTimeImmutable('2026-08-02 10:00:00'))
                ->withRawDocumentId(Uuid::v7())->build(),
            $status->withPostingNumber('PENDING')->withOrderNumber('PENDING')
                ->withStatus('awaiting_packaging')->withObservedAt(new \DateTimeImmutable('2026-08-01 10:00:00'))
                ->withRawDocumentId(Uuid::v7())->build(),
        ]);
        /** @var MarketplaceReturnFactRepository $returns */
        $returns = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        $returns->upsertAll([
            MarketplaceReturnFactBuilder::aMarketplaceReturnFact()
                ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
                ->withSourceRowId('CANCELLED-RETURN')->withPostingNumber('CANCELLED')
                ->withOrderNumber('CANCELLED')->withMarketplaceSku('COHORT')
                ->withReturnType('Cancellation')->withReturnReasonName('Покупатель отменил заказ')
                ->withQuantity(10)->build(),
        ]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $rawRows = (new SalesCohortMetricsQuery($connection))
            ->build($companyId->toRfc4122(), $accountId->toRfc4122(), ['COHORT'], $date, $date)
            ->executeQuery()->fetchAllAssociative();

        self::assertCount(1, $rawRows);
        $sku = SalesCohortMetricsQuery::mapRow($rawRows[0]);
        self::assertSame('COHORT', $sku->marketplaceSku);
        self::assertCount(1, $sku->days);
        $row = $sku->days[0];
        self::assertSame('COHORT', $row->marketplaceSku);
        self::assertSame('2026-08-01', $row->date);
        self::assertSame(100, $row->rawOrderedQuantity);
        self::assertSame(10, $row->preHandoverCancelledQuantity);
        self::assertSame(90, $row->effectiveOrderedQuantity);
        self::assertSame($row->rawOrderedQuantity, $row->effectiveOrderedQuantity + $row->preHandoverCancelledQuantity);
        self::assertSame(100, $connection->fetchOne(
            'SELECT SUM(quantity) FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND marketplace_sku = ? AND business_date = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), 'COHORT', '2026-08-01'],
        ));
    }

    /** @param list<string> $skus */
    #[DataProvider('invalidRequests')]
    public function testRejectsInvalidOrUnboundedRequests(array $skus, string $from, string $to): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $query = new SalesCohortMetricsQuery($connection);

        $this->expectException(\InvalidArgumentException::class);
        $query->build(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), $skus, new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }

    /** @return iterable<string, array{list<string>, string, string}> */
    public static function invalidRequests(): iterable
    {
        yield 'no SKU' => [[], '2026-01-01', '2026-01-01'];
        yield 'too many SKUs' => [array_map(strval(...), range(1, 51)), '2026-01-01', '2026-01-01'];
        yield 'blank SKU' => [[' '], '2026-01-01', '2026-01-01'];
        yield 'reverse dates' => [['SKU'], '2026-01-02', '2026-01-01'];
        yield '185 days' => [['SKU'], '2026-01-01', '2026-07-04'];
    }
}
