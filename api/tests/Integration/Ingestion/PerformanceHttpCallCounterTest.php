<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Infrastructure\Connector\OzonPerformance\DoctrineOzonPerformanceCallCounter;
use App\Tests\Support\Builder\PerformanceHttpLogicalCountBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
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
        // Commit the fixture independently too: an uncommitted fixture would
        // lock the row against the counter's independent connection.
        $fixtures = DriverManager::getConnection($connection->getParams());
        $initialLevel = $connection->getTransactionNestingLevel();
        try {
            $this->cleanOwnBuckets($fixtures);
            PerformanceHttpLogicalCountBuilder::aCount()->withAttempts(17)->persistWith($fixtures);
            $connection->beginTransaction();
            $counter->recordAcrossAccounts('token');
            $counter->recordAcrossAccounts('token');
            $connection->rollBack();
            $after = $this->attempts($connection, $bucket, 'token');
            self::assertSame(19, $after);
            $clock->sleep(1);
            $counter->recordAcrossAccounts('daily');
            self::assertSame(1, $this->attempts($connection, '2060-01-02 12:01:00+00', 'daily'));
        } finally {
            if ($connection->getTransactionNestingLevel() > $initialLevel) {
                $connection->rollBack();
            }
            $counter->reset();
            try {
                $this->cleanOwnBuckets($fixtures);
            } finally {
                $fixtures->close();
            }
        }
    }

    private function cleanOwnBuckets(Connection $connection): void
    {
        $connection->executeStatement("DELETE FROM performance_http_logical_count WHERE bucket_start IN ('2060-01-02 12:00:00+00', '2060-01-02 12:01:00+00') AND operation IN ('token', 'daily')");
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
