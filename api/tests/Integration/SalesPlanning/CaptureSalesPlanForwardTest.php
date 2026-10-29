<?php

declare(strict_types=1);

namespace App\Tests\Integration\SalesPlanning;

use App\Ingestion\Application\NetForecast\NetForwardArtifactReader;
use App\Ingestion\Domain\NetForwardArtifactKey;
use App\Ingestion\Domain\NetForwardArtifactKind;
use App\Ingestion\Domain\NetForwardArtifactMissing;
use App\Ingestion\Domain\NetForwardArtifactStorage;
use App\SalesPlanning\Application\CaptureSalesPlanForwardAction;
use App\SalesPlanning\Domain\SalesPlanMetric;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\Tests\Support\Builder\SalesPlanBuilder;
use App\Tests\Support\Builder\SalesPlanLineBuilder;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

#[SkipDatabaseRollback]
final class CaptureSalesPlanForwardTest extends KernelTestCase
{
    public function testPlanValuesAreSealedAndRetryDoesNotReadMutatedPlan(): void
    {
        self::bootKernel();
        self::getContainer()->set(NetForwardArtifactStorage::class, new class implements NetForwardArtifactStorage {
            /** @var array<string, string> */
            private array $objects = [];

            public function putContent(string $companyId, string $accountId, NetForwardArtifactKey $key, string $body): void
            {
                $key->assertScope($companyId, $accountId);
                $this->objects[$key->toString()] = $body;
            }

            public function get(string $companyId, string $accountId, NetForwardArtifactKey $key, int $maxBytes): string
            {
                $key->assertScope($companyId, $accountId);

                return $this->objects[$key->toString()] ?? throw new NetForwardArtifactMissing();
            }
        });
        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        /** @var SalesPlanRepository $plans */
        $plans = self::getContainer()->get(SalesPlanRepository::class);
        /** @var CaptureSalesPlanForwardAction $capture */
        $capture = self::getContainer()->get(CaptureSalesPlanForwardAction::class);
        /** @var NetForwardArtifactReader $reader */
        $reader = self::getContainer()->get(NetForwardArtifactReader::class);
        $day = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $plan = SalesPlanBuilder::aSalesPlan()->withPeriod($day, $day)->withMetric(SalesPlanMetric::RetainedSales)->build();
        $lines = SalesPlanLineBuilder::linesOf($plan)->withMarketplaceSku('SKU')->withTotalQuantity(42)->build();
        $plan->approve(Uuid::v7(), $day);
        $company = $plan->companyId()->toRfc4122();
        $account = $plan->marketplaceAccountId()->toRfc4122();
        $id = $plan->id()->toRfc4122();
        try {
            $plans->add($plan, $lines);
            $request = Uuid::v7()->toRfc4122();
            $ref = $capture($company, $account, $id, $request, $day->format('Y-m-d'), $day->format('Y-m-d'));
            $artifact = $reader->read($company, $account, NetForwardArtifactKind::Input, $request, $ref->artifactHash);
            $sealedPlan = null;
            foreach ($reader->segments($company, $account, $artifact) as $segment) {
                if ('plan' === $segment['type']) {
                    $sealedPlan = $segment['rows'][0]['value'];
                }
            }
            self::assertIsArray($sealedPlan);
            self::assertIsArray($sealedPlan['fields']);
            self::assertSame($id, $sealedPlan['fields']['planId']);
            self::assertSame('retained_sales', $sealedPlan['fields']['metric']);
            $db->executeStatement('UPDATE sales_plan_line SET quantity = 999 WHERE company_id = ? AND sales_plan_id = ?', [$company, $id]);
            self::assertEquals($ref, $capture($company, $account, $id, $request, $day->format('Y-m-d'), $day->format('Y-m-d')));
            self::assertFalse($db->isTransactionActive());
            $this->expectException(NetForwardArtifactMissing::class);
            $reader->read($company, Uuid::v7()->toRfc4122(), NetForwardArtifactKind::Input, $request, $ref->artifactHash);
        } finally {
            foreach (['net_forecast_artifact', 'sales_plan_day_claim', 'sales_plan_line', 'sales_plan'] as $table) {
                $db->executeStatement('DELETE FROM '.$table.' WHERE company_id = ?', [$company]);
            }
        }
    }
}
