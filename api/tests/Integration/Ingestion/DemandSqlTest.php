<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\Demand\DemandSql;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Определение спроса, общее для «Остатков» и матрицы спроса
 * (docs/plan/ozon-demand-matrix-report.md, решения 1, 2 и 4).
 */
final class DemandSqlTest extends KernelTestCase
{
    private const string FROM = '2026-09-01';
    private const string TO = '2026-09-28';

    private Uuid $companyId;
    private Uuid $accountId;
    private int $postingSeq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
    }

    public function testDemandBelongsToTheBuyerClusterNotTheShippingOne(): void
    {
        $this->sale('A', 3, clusterFrom: 'Москва', clusterTo: 'Омск');

        self::assertSame(['A|Омск' => 3], $this->demand());
    }

    public function testCancelledOrdersAreNotDemand(): void
    {
        $this->sale('A', 2);
        $this->sale('A', 5, status: 'cancelled');

        self::assertSame(['A|Омск' => 2], $this->demand());
    }

    public function testWindowBoundsAreInclusive(): void
    {
        $this->sale('A', 1, day: '2026-08-31');
        $this->sale('A', 2, day: self::FROM);
        $this->sale('A', 4, day: self::TO);
        $this->sale('A', 8, day: '2026-09-29');

        self::assertSame(['A|Омск' => 6], $this->demand());
    }

    public function testMissingBuyerClusterStaysVisibleAsEmpty(): void
    {
        $this->sale('A', 2, clusterFrom: 'Москва', clusterTo: null);

        self::assertSame(['A|' => 2], $this->demand());
    }

    public function testAnotherCompanysSalesAreNotVisible(): void
    {
        $this->sale('A', 2);
        $this->sale('A', 7, company: Uuid::v7());

        self::assertSame(['A|Омск' => 2], $this->demand());
    }

    private function sale(
        string $sku,
        int $quantity,
        string $day = '2026-09-10',
        string $status = 'delivered',
        ?string $clusterFrom = 'Омск',
        ?string $clusterTo = 'Омск',
        ?Uuid $company = null,
    ): void {
        /** @var SalesFactRepository $repository */
        $repository = self::getContainer()->get(SalesFactRepository::class);
        $posting = 'P-'.(++$this->postingSeq);
        $repository->upsertAll([SalesFactBuilder::aSalesFact()
            ->withCompanyId($company ?? $this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withSourceRowId("{$posting}|{$sku}")
            ->withPostingNumber($posting)
            ->withMarketplaceSku($sku)
            ->withQuantity($quantity)
            ->withStatus($status)
            ->withBusinessDate(new \DateTimeImmutable($day))
            ->withClusters($clusterFrom, $clusterTo)
            ->build()]);
    }

    /**
     * @return array<string, int> «SKU|кластер» → штуки
     */
    private function demand(): array
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $rows = $connection->fetchAllAssociative(
            'WITH '.DemandSql::linesCte()."
             SELECT marketplace_sku || '|' || COALESCE(cluster, '') AS k, SUM(quantity)::bigint AS quantity
             FROM demand_lines
             GROUP BY marketplace_sku, cluster
             ORDER BY marketplace_sku, cluster",
            ['companyId' => $this->companyId->toRfc4122(), 'demandFrom' => self::FROM, 'windowEnd' => self::TO],
        );

        $demand = [];
        foreach ($rows as $row) {
            self::assertIsString($row['k']);
            self::assertIsInt($row['quantity']);
            $demand[$row['k']] = $row['quantity'];
        }

        return $demand;
    }
}
