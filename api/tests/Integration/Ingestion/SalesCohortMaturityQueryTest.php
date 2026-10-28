<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortMetricsQuery;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortMetricsRow;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SalesCohortMaturityQueryTest extends KernelTestCase
{
    public function testMaturityIsPerSkuDayRatherThanOneSeasonBoundary(): void
    {
        self::bootKernel();
        $company = Uuid::v7();
        $account = Uuid::v7();
        $this->seedTraining($company, $account, 30);
        foreach (['2026-08-26', '2026-08-27', '2026-08-28'] as $day) {
            $this->seedPoint($company, $account, 'GAP', $day, 'delivered', 3);
        }
        $this->seedPoint($company, $account, 'GAP', '2026-08-27', 'delivering', 1);
        $days = $this->read($company, $account, ['GAP'])['GAP'];
        self::assertSame(['mature', 'preliminary', 'mature'], array_column($days, 'maturityStatus'));
        self::assertSame([0, 2500, 0], array_column($days, 'inFlightRateBps'));
        self::assertSame([10000, null, 10000], array_column($days, 'actualBuyoutRateBps'));
        // The observed quantity survives even when its cohort is preliminary.
        self::assertSame([3, 3, 3], array_column($days, 'observedNetSalesQuantity'));
    }

    public function testAgeAndUnroundedThreePercentLimitAreBothRequired(): void
    {
        self::bootKernel();
        $company = Uuid::v7();
        $account = Uuid::v7();
        $this->seedTraining($company, $account, 30);
        $this->seedPoint($company, $account, 'EXACT', '2026-08-28', 'delivered', 97);
        $this->seedPoint($company, $account, 'EXACT', '2026-08-28', 'delivering', 3);
        $this->seedPoint($company, $account, 'ABOVE', '2026-08-28', 'delivered', 96);
        $this->seedPoint($company, $account, 'ABOVE', '2026-08-28', 'delivering', 3);
        $this->seedPoint($company, $account, 'FRESH', '2026-08-30', 'delivered', 1);
        $rows = $this->read($company, $account, ['EXACT', 'ABOVE', 'FRESH']);
        self::assertSame('mature', $rows['EXACT'][0]->maturityStatus);
        self::assertSame(300, $rows['EXACT'][0]->inFlightRateBps);
        self::assertSame('preliminary', $rows['ABOVE'][0]->maturityStatus);
        self::assertSame(303, $rows['ABOVE'][0]->inFlightRateBps);
        self::assertSame('preliminary', $rows['FRESH'][0]->maturityStatus);
        self::assertSame(0, $rows['FRESH'][0]->inFlightRateBps);
        self::assertNull($rows['FRESH'][0]->actualBuyoutRateBps);
        self::assertSame(10000, $rows['FRESH'][0]->knownBuyoutRateBps);
    }

    public function testTrainingRequiresThirtyUniqueObservedPostingsInTheSameCompanyAndAccount(): void
    {
        self::bootKernel();
        $company = Uuid::v7();
        $account = Uuid::v7();
        // Quantity cannot replace the number of unique postings.
        $this->seedTraining($company, $account, 29);
        $this->seedTraining(Uuid::v7(), $account, 30);
        $this->seedTraining($company, Uuid::v7(), 30);
        $this->seedPoint($company, $account, 'SMALL', '2026-08-28', 'delivered', 3);
        $row = $this->read($company, $account, ['SMALL'])['SMALL'][0];
        self::assertSame('preliminary', $row->maturityStatus);
        self::assertSame(0, $row->inFlightRateBps);
        self::assertNull($row->actualBuyoutRateBps);
        self::assertSame(10000, $row->knownBuyoutRateBps);
    }

    public function testUnknownIsNotInFlightAndDoesNotDiluteConditionalBuyoutRate(): void
    {
        self::bootKernel();
        $company = Uuid::v7();
        $account = Uuid::v7();
        $this->seedTraining($company, $account, 30);
        $this->seedPoint($company, $account, 'UNKNOWN', '2026-08-28', 'delivered', 2);
        $this->seedPoint($company, $account, 'UNKNOWN', '2026-08-28', 'cancelled', 18);
        $row = $this->read($company, $account, ['UNKNOWN'])['UNKNOWN'][0];
        self::assertSame('mature', $row->maturityStatus);
        self::assertSame(20, $row->effectiveOrderedQuantity);
        self::assertSame(0, $row->inFlightQuantity);
        self::assertSame(2, $row->buyoutResolvedQuantity);
        self::assertSame(10000, $row->actualBuyoutRateBps);
    }

    private function seedTraining(Uuid $company, Uuid $account, int $count): void
    {
        $sale = SalesFactBuilder::aSalesFact()->withCompanyId($company)->withMarketplaceAccountId($account)
            ->withMarketplaceSku('TRAIN')->withBusinessDate(new \DateTimeImmutable('2026-07-01'))
            ->withStatus('delivered')->withQuantity(100);
        $status = MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($company)->withMarketplaceAccountId($account);
        $facts = [];
        $statuses = [];
        for ($i = 0; $i < $count; ++$i) {
            $posting = 'TRAIN-'.$i;
            $facts[] = $sale->withSourceRowId($posting.'|TRAIN')->withPostingNumber($posting)->withOrderNumber($posting)->build();
            $observation = $status->withPostingNumber($posting)->withOrderNumber($posting);
            $statuses[] = $observation->withRawDocumentId(Uuid::v7())->withStatus('awaiting_packaging')->withObservedAt(new \DateTimeImmutable('2026-07-01 10:00:00 UTC'))->build();
            $statuses[] = $observation->withRawDocumentId(Uuid::v7())->withStatus('delivered')->withObservedAt(new \DateTimeImmutable('2026-07-02 01:00:00 UTC'))->build();
        }
        $this->sales()->upsertAll($facts);
        $this->statuses()->recordChanged($company->toRfc4122(), $statuses);
    }

    private function seedPoint(Uuid $company, Uuid $account, string $sku, string $day, string $state, int $quantity): void
    {
        $posting = $sku.'-'.$day.'-'.$state;
        $this->sales()->upsertAll([
            SalesFactBuilder::aSalesFact()->withCompanyId($company)->withMarketplaceAccountId($account)
                ->withMarketplaceSku($sku)->withBusinessDate(new \DateTimeImmutable($day))
                ->withSourceRowId($posting.'|'.$sku)->withPostingNumber($posting)->withOrderNumber($posting)
                ->withStatus($state)->withQuantity($quantity)->build(),
        ]);
        $this->statuses()->recordChanged($company->toRfc4122(), [
            MarketplacePostingStatusBuilder::aMarketplacePostingStatus()->withCompanyId($company)->withMarketplaceAccountId($account)
                ->withPostingNumber($posting)->withOrderNumber($posting)->withStatus($state)
                ->withObservedAt(new \DateTimeImmutable($day.' 10:00:00 UTC'))->build(),
        ]);
    }

    /** @param list<string> $skus
     * @return array<string, list<SalesCohortMetricsRow>>
     */
    private function read(Uuid $company, Uuid $account, array $skus): array
    {
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $rows = (new SalesCohortMetricsQuery($connection))->build($company->toRfc4122(), $account->toRfc4122(), $skus,
            new \DateTimeImmutable('2026-08-26'), new \DateTimeImmutable('2026-08-30'),
            calculatedAt: new \DateTimeImmutable('2026-08-30T12:00:00Z'),
        )->executeQuery()->fetchAllAssociative();
        $result = [];
        foreach ($rows as $raw) {
            $row = SalesCohortMetricsQuery::mapRow($raw);
            $result[$row->marketplaceSku] = $row->days;
        }

        return $result;
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
}
