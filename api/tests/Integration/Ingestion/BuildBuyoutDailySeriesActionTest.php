<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\BuildBuyoutDailySeriesAction;
use App\Ingestion\Infrastructure\Query\BuyoutDailyQuery;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BuildBuyoutDailySeriesActionTest extends KernelTestCase
{
    public function testAppliesBoundedPlannerPolicyAndReturnsRows(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $action = new BuildBuyoutDailySeriesAction($connection, new BuyoutDailyQuery($connection));
        $plannerSettings = [
            'jit' => $connection->fetchOne('SHOW jit'),
            'enable_nestloop' => $connection->fetchOne('SHOW enable_nestloop'),
            'statement_timeout' => $connection->fetchOne('SHOW statement_timeout'),
        ];

        $rows = $action(
            companyId: Uuid::v7()->toRfc4122(),
            marketplaceSku: 'MISSING',
            from: new \DateTimeImmutable('2026-08-01'),
            to: new \DateTimeImmutable('2026-08-30'),
            asOf: new \DateTimeImmutable('2026-08-31 00:00:00 UTC'),
        );

        self::assertSame([], $rows);
        self::assertSame($plannerSettings['jit'], $connection->fetchOne('SHOW jit'));
        self::assertSame($plannerSettings['enable_nestloop'], $connection->fetchOne('SHOW enable_nestloop'));
        self::assertSame($plannerSettings['statement_timeout'], $connection->fetchOne('SHOW statement_timeout'));
    }
}
