<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Connector\OzonPerformance;

use App\Ingestion\Domain\OzonPerformanceCallCounter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Operational cross-account counts only, no tenant facts (CLAUDE.md §1).
 * Independent autocommit connection: application rollback must not erase a
 * call which may already have reached Ozon. Uses this application's own DB.
 * A failed reservation prevents the call; it never creates uncounted traffic.
 */
final readonly class DoctrineOzonPerformanceCallCounter implements OzonPerformanceCallCounter, ResetInterface
{
    private Connection $usage;

    public function __construct(Connection $application, private ClockInterface $clock)
    {
        // Reuse resolved DB name (including test suffix), not connection state,
        // middleware, application transaction or credentials in a new env var.
        $this->usage = DriverManager::getConnection($application->getParams());
    }

    public function recordAcrossAccounts(string $operation): void
    {
        if (!\in_array($operation, ['token', 'campaigns', 'campaign_products', 'expense', 'daily', 'sku', 'report_order', 'report_status', 'report_download', 'cpo_order'], true)) {
            throw new \InvalidArgumentException('Invalid Performance operation.');
        }
        $at = $this->clock->now()->format('Y-m-d H:i:s.uP');
        $this->usage->transactional(static function (Connection $connection) use ($operation, $at): void {
            $connection->executeStatement("SET LOCAL lock_timeout='2s'");
            $connection->executeStatement("SET LOCAL statement_timeout='5s'");
            $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO performance_http_logical_count (bucket_start, operation, attempts)
                    VALUES (date_trunc('minute', CAST(:at AS TIMESTAMP WITH TIME ZONE)), :operation, 1)
                    ON CONFLICT (bucket_start, operation) DO UPDATE
                    SET attempts = performance_http_logical_count.attempts + 1
                    SQL,
                ['at' => $at, 'operation' => $operation],
            );
        });
    }

    public function reset(): void
    {
        $this->usage->close();
    }
}
