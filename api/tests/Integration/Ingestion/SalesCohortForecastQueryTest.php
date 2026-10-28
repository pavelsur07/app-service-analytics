<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\MarketplaceReturnFactRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\Buyout\BuyoutDailyQuery;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortMetricsQuery;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortMetricsRow;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\MarketplaceReturnFactBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SalesCohortForecastQueryTest extends KernelTestCase
{
    public function testUsesStageRatesAccountFallbackAndSkuOverrideLikeExistingDailyReport(): void
    {
        self::bootKernel();
        $company = Uuid::v7();
        $account = Uuid::v7();
        $this->closed($company, $account, 'MATURITY', '2026-06-01', 30, 0);
        $this->closed($company, $account, 'BASE', '2026-08-01', 24, 6);
        $this->point($company, $account, 'PRE', 'awaiting_packaging', 10);
        $this->point($company, $account, 'POST', 'delivering', 10);
        $rows = $this->read($company, $account, ['PRE', 'POST']);
        self::assertSame(8, $rows['PRE']->currentProjectedBuyoutQuantity);
        self::assertSame(10, $rows['POST']->currentProjectedBuyoutQuantity);
        self::assertSame(10000, $rows['PRE']->currentProjectedBuyoutRateBps);
        self::assertSame(0, $rows['PRE']->unestimatedQuantity);
        self::assertNull($rows['PRE']->expectedNetSalesQuantity);
        self::assertSame('net_forecast_not_available', $rows['PRE']->netForecastUnavailableReason);

        $legacy = (new BuyoutDailyQuery($this->connection()))->build($company->toRfc4122(), 'PRE',
            new \DateTimeImmutable('2026-08-30'), new \DateTimeImmutable('2026-08-30'), new \DateTimeImmutable('2026-08-30T12:00:00Z'),
        )->executeQuery()->fetchAssociative();
        self::assertIsArray($legacy);
        self::assertSame(8, BuyoutDailyQuery::mapRow($legacy)->projectedBuyoutQuantity);

        // This SKU has its own sample >=30, which overrides the account.
        $this->closed($company, $account, 'SKU', '2026-08-01', 15, 15);
        $this->point($company, $account, 'SKU', 'awaiting_packaging', 10);
        self::assertSame(5, $this->read($company, $account, ['SKU'])['SKU']->currentProjectedBuyoutQuantity);
    }

    public function testUnknownAtTenPercentIsExtrapolatedButLargerShareSuppressesProjection(): void
    {
        self::bootKernel();
        $company = Uuid::v7();
        $account = Uuid::v7();
        $this->closed($company, $account, 'MATURITY', '2026-06-01', 30, 0);
        $this->closed($company, $account, 'BASE', '2026-08-01', 24, 6);
        $this->point($company, $account, 'EXACT', 'awaiting_packaging', 9);
        $this->point($company, $account, 'EXACT', 'cancelled', 1);
        $this->point($company, $account, 'ABOVE', 'awaiting_packaging', 8);
        $this->point($company, $account, 'ABOVE', 'cancelled', 2);
        $rows = $this->read($company, $account, ['EXACT', 'ABOVE']);
        self::assertSame(8, $rows['EXACT']->currentProjectedBuyoutQuantity);
        self::assertSame(1, $rows['EXACT']->unestimatedQuantity);
        self::assertSame(1000, $rows['EXACT']->unestimatedRateBps);
        self::assertIsString($rows['EXACT']->knownProjectedBuyoutQuantity);
        self::assertMatchesRegularExpression('/^7\.2(?:0*)$/', $rows['EXACT']->knownProjectedBuyoutQuantity);
        self::assertNull($rows['ABOVE']->currentProjectedBuyoutQuantity);
        self::assertNull($rows['ABOVE']->currentProjectedBuyoutRateBps);
        self::assertSame(2000, $rows['ABOVE']->unestimatedRateBps);
    }

    public function testForeignTrainingDoesNotInventAnEstimateAndAllReturnedIsTrueZero(): void
    {
        self::bootKernel();
        $company = Uuid::v7();
        $account = Uuid::v7();
        $this->closed(Uuid::v7(), $account, 'FOREIGN', '2026-08-01', 30, 0);
        $this->closed($company, Uuid::v7(), 'FOREIGN', '2026-08-01', 30, 0);
        $this->point($company, $account, 'UNTRAINED', 'awaiting_packaging', 10);
        $this->point($company, $account, 'RETURNED', 'delivered', 1);
        $this->returns()->upsertAll([
            MarketplaceReturnFactBuilder::aMarketplaceReturnFact()->withCompanyId($company)->withMarketplaceAccountId($account)
                ->withPostingNumber('RETURNED-delivered')->withOrderNumber('RETURNED-delivered')->withMarketplaceSku('RETURNED')
                ->withReturnType('ClientReturn')->withQuantity(1)->build(),
        ]);
        $rows = $this->read($company, $account, ['UNTRAINED', 'RETURNED']);
        self::assertNull($rows['UNTRAINED']->currentProjectedBuyoutQuantity);
        self::assertSame(10, $rows['UNTRAINED']->unestimatedQuantity);
        self::assertSame(10000, $rows['UNTRAINED']->unestimatedRateBps);
        self::assertSame(0, $rows['RETURNED']->currentProjectedBuyoutQuantity);
        self::assertNull($rows['RETURNED']->currentProjectedBuyoutRateBps);
        self::assertSame(0, $rows['RETURNED']->unestimatedQuantity);
    }

    private function closed(Uuid $company, Uuid $account, string $sku, string $date, int $delivered, int $cancelled): void
    {
        $facts = [];
        $statuses = [];
        $returns = [];
        $day = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
        for ($i = 0; $i < $delivered + $cancelled; ++$i) {
            $posting = $sku.'-'.$date.'-'.$i;
            $isDelivered = $i < $delivered;
            $facts[] = SalesFactBuilder::aSalesFact()->withCompanyId($company)->withMarketplaceAccountId($account)
                ->withMarketplaceSku($sku)->withBusinessDate($day)->withSourceRowId($posting.'|'.$sku)
                ->withPostingNumber($posting)->withOrderNumber($posting)->withQuantity(1)
                ->withStatus($isDelivered ? 'delivered' : 'cancelled')->build();
            $status = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($company)->withMarketplaceAccountId($account)
                ->withPostingNumber($posting)->withOrderNumber($posting);
            $statuses[] = $status->withRawDocumentId(Uuid::v7())->withStatus($isDelivered ? 'delivering' : 'awaiting_packaging')
                ->withObservedAt($day->setTime(10, 0))->build();
            $statuses[] = $status->withRawDocumentId(Uuid::v7())->withStatus($isDelivered ? 'delivered' : 'cancelled')
                ->withObservedAt($day->modify('+1 day')->setTime(1, 0))->build();
            if (!$isDelivered) {
                $returns[] = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()->withCompanyId($company)->withMarketplaceAccountId($account)
                    ->withPostingNumber($posting)->withOrderNumber($posting)->withMarketplaceSku($sku)->withSourceRowId('RET-'.$posting)
                    ->withReturnType('Cancellation')->withReturnReasonName('Покупатель отменил заказ')->withQuantity(1)->build();
            }
        }
        $this->sales()->upsertAll($facts);
        $this->statuses()->recordChanged($company->toRfc4122(), $statuses);
        $this->returns()->upsertAll($returns);
    }

    private function point(Uuid $company, Uuid $account, string $sku, string $state, int $quantity): void
    {
        $posting = $sku.'-'.$state;
        $this->sales()->upsertAll([
            SalesFactBuilder::aSalesFact()->withCompanyId($company)->withMarketplaceAccountId($account)
                ->withMarketplaceSku($sku)->withBusinessDate(new \DateTimeImmutable('2026-08-30'))
                ->withSourceRowId($posting.'|'.$sku)->withPostingNumber($posting)->withOrderNumber($posting)
                ->withStatus($state)->withQuantity($quantity)->build(),
        ]);
        $this->statuses()->recordChanged($company->toRfc4122(), [
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($company)->withMarketplaceAccountId($account)
                ->withPostingNumber($posting)->withOrderNumber($posting)->withStatus($state)
                ->withObservedAt(new \DateTimeImmutable('2026-08-30T10:00:00Z'))->build(),
        ]);
    }

    /** @param list<string> $skus
     * @return array<string, SalesCohortMetricsRow>
     */
    private function read(Uuid $company, Uuid $account, array $skus): array
    {
        $rows = (new SalesCohortMetricsQuery($this->connection()))->build($company->toRfc4122(), $account->toRfc4122(), $skus,
            new \DateTimeImmutable('2026-08-30'), new \DateTimeImmutable('2026-08-30'),
            calculatedAt: new \DateTimeImmutable('2026-08-30T12:00:00Z'),
        )->executeQuery()->fetchAllAssociative();
        $result = [];
        foreach ($rows as $raw) {
            $row = SalesCohortMetricsQuery::mapRow($raw);
            $result[$row->marketplaceSku] = $row->days[0];
        }

        return $result;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return $connection;
    }

    private function sales(): SalesFactRepository
    {
        $repository = self::getContainer()->get(SalesFactRepository::class);
        \assert($repository instanceof SalesFactRepository);

        return $repository;
    }

    private function statuses(): MarketplacePostingStatusRepository
    {
        $repository = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        \assert($repository instanceof MarketplacePostingStatusRepository);

        return $repository;
    }

    private function returns(): MarketplaceReturnFactRepository
    {
        $repository = self::getContainer()->get(MarketplaceReturnFactRepository::class);
        \assert($repository instanceof MarketplaceReturnFactRepository);

        return $repository;
    }
}
