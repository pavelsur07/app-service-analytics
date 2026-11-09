<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Infrastructure\Connector\OzonPerformance\DoctrineOzonPerformanceCallCounter;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\NativeHttpClient;

final class PerformanceHttpCallCounterTest extends KernelTestCase
{
    public function testProductionTransportIsNativeWithoutCurlReplay(): void
    {
        self::bootKernel();
        self::assertInstanceOf(NativeHttpClient::class, self::getContainer()->get('ozon_performance.client'));
    }

    public function testCommittedAccountingSurvivesApplicationRollbackAndMinuteRollover(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $clock = new MockClock('2060-01-02 12:00:59 UTC');
        $counter = new DoctrineOzonPerformanceCallCounter($connection, $clock);
        $bucket = '2060-01-02 12:00:00+00';
        $before = $this->attempts($connection, $bucket, 'token');
        $connection->beginTransaction();
        $counter->recordAcrossAccounts('token');
        $counter->recordAcrossAccounts('token');
        $connection->rollBack();
        $after = $this->attempts($connection, $bucket, 'token');
        self::assertSame($before + 2, $after);
        $clock->sleep(1);
        $counter->recordAcrossAccounts('daily');
        $counter->reset();
        self::assertGreaterThanOrEqual(1, $this->attempts($connection, '2060-01-02 12:01:00+00', 'daily'));
    }

    private function attempts(Connection $connection, string $bucket, string $operation): int
    {
        $value = $connection->fetchOne('SELECT attempts FROM performance_http_logical_count WHERE bucket_start=CAST(? AS TIMESTAMP WITH TIME ZONE) AND operation=?', [$bucket, $operation]);
        if (false === $value) {
            return 0;
        }
        self::assertTrue(\is_int($value) || \is_string($value));

        return (int) $value;
    }
}
