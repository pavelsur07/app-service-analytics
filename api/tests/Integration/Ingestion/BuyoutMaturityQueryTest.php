<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\BuyoutMaturityQuery;
use App\Ingestion\Infrastructure\Query\BuyoutMaturityRow;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BuyoutMaturityQueryTest extends KernelTestCase
{
    private Uuid $companyId;
    private Uuid $accountId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
    }

    public function testP95RequiresThirtyUniqueResolvedPostingsAndUsesDiscretePercentiles(): void
    {
        for ($hours = 1; $hours <= 29; ++$hours) {
            $this->deliveredPosting('MAT-'.$hours, $hours);
        }

        $atTwentyNine = $this->maturity($this->accountId);
        self::assertSame(29, $atTwentyNine->sampleSize);
        self::assertSame(15 * 3600, $atTwentyNine->p50Seconds);
        self::assertSame(27 * 3600, $atTwentyNine->p90Seconds);
        self::assertNull($atTwentyNine->p95Seconds);
        self::assertFalse($atTwentyNine->isCohortMature(
            new \DateTimeImmutable('2026-08-20 00:00:00Z'),
            new \DateTimeImmutable('2026-08-30 00:00:00Z'),
        ));

        $this->deliveredPosting('MAT-30', 30);
        $atThirty = $this->maturity($this->accountId);
        self::assertSame(30, $atThirty->sampleSize);
        self::assertSame(15 * 3600, $atThirty->p50Seconds);
        self::assertSame(27 * 3600, $atThirty->p90Seconds);
        self::assertSame(29 * 3600, $atThirty->p95Seconds);

        $boundary = new \DateTimeImmutable('2026-08-20 00:00:00Z');
        self::assertFalse($atThirty->isCohortMature($boundary, $boundary->modify('+29 hours')));
        self::assertTrue($atThirty->isCohortMature($boundary, $boundary->modify('+29 hours +1 second')));
    }

    public function testExcludesFutureAndNegativeIntervalsAndScopesByAccount(): void
    {
        $this->deliveredPosting('VALID', 10);
        $this->deliveredPosting('FUTURE', 10, '2026-09-02 00:00:00');
        $this->negativePosting('NEGATIVE');

        $otherAccount = Uuid::v7();
        $this->deliveredPosting('OTHER', 20, '2026-08-01 00:00:00', $otherAccount);

        $ours = $this->maturity($this->accountId);
        $theirs = $this->maturity($otherAccount);

        self::assertSame(1, $ours->sampleSize);
        self::assertSame(10 * 3600, $ours->p50Seconds);
        self::assertSame(1, $theirs->sampleSize);
        self::assertSame(20 * 3600, $theirs->p50Seconds);
    }

    public function testAsOfRepresentsTheSameInstantRegardlessOfInputTimezone(): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $query = new BuyoutMaturityQuery($connection);

        $utc = $query->build(
            $this->companyId->toRfc4122(),
            $this->accountId->toRfc4122(),
            new \DateTimeImmutable('2026-08-30 12:00:00Z'),
        );
        $moscow = $query->build(
            $this->companyId->toRfc4122(),
            $this->accountId->toRfc4122(),
            new \DateTimeImmutable('2026-08-30 15:00:00 Europe/Moscow'),
        );

        self::assertSame($utc->getParameter('asOf'), $moscow->getParameter('asOf'));
    }

    private function deliveredPosting(
        string $posting,
        int $hours,
        string $handedAt = '2026-08-01 00:00:00',
        ?Uuid $accountId = null,
    ): void {
        $accountId ??= $this->accountId;
        $handed = new \DateTimeImmutable($handedAt);
        $this->sales()->upsertAll([
            SalesFactBuilder::aSalesFact()
                ->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($accountId)
                ->withSourceRowId($posting.'|SKU-'.$posting)
                ->withPostingNumber($posting)
                ->withOrderNumber('ORDER-'.$posting)
                ->withMarketplaceSku('SKU-'.$posting)
                ->withStatus('delivered')
                ->build(),
        ]);
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), [
            $this->postingStatus($accountId, $posting, 'delivering', $handed),
            $this->postingStatus($accountId, $posting, 'delivered', $handed->modify('+'.$hours.' hours')),
        ]);
    }

    private function negativePosting(string $posting): void
    {
        $this->sales()->upsertAll([
            SalesFactBuilder::aSalesFact()
                ->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($this->accountId)
                ->withSourceRowId($posting.'|SKU')
                ->withPostingNumber($posting)
                ->withOrderNumber('ORDER-'.$posting)
                ->withMarketplaceSku('SKU')
                ->withStatus('delivered')
                ->build(),
        ]);
        // delivering увиден уже после terminal: такой интервал нельзя
        // интерпретировать как скорость доставки.
        $this->postingStatuses()->recordChanged($this->companyId->toRfc4122(), [
            $this->postingStatus($this->accountId, $posting, 'delivered', new \DateTimeImmutable('2026-08-02 10:00:00')),
            $this->postingStatus($this->accountId, $posting, 'delivering', new \DateTimeImmutable('2026-08-02 11:00:00')),
        ]);
    }

    private function postingStatus(Uuid $accountId, string $posting, string $status, \DateTimeImmutable $observedAt): \App\Ingestion\Domain\MarketplacePostingStatus
    {
        return MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($accountId)
            ->withPostingNumber($posting)
            ->withOrderNumber('ORDER-'.$posting)
            ->withStatus($status)
            ->withObservedAt($observedAt)
            ->withRawDocumentId(Uuid::v7())
            ->build();
    }

    private function maturity(Uuid $accountId): BuyoutMaturityRow
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $query = new BuyoutMaturityQuery($connection);
        $row = $query->build(
            $this->companyId->toRfc4122(),
            $accountId->toRfc4122(),
            new \DateTimeImmutable('2026-08-30 00:00:00Z'),
        )->executeQuery()->fetchAssociative();
        self::assertNotFalse($row);

        return BuyoutMaturityQuery::mapRow($row);
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
}
