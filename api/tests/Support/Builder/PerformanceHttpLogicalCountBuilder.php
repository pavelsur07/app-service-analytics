<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\PerformanceHttpLogicalCount;
use Doctrine\DBAL\Connection;

final class PerformanceHttpLogicalCountBuilder
{
    private \DateTimeImmutable $bucketStart;
    private string $operation = 'token';
    private int $attempts = 17;

    private function __construct()
    {
        $this->bucketStart = new \DateTimeImmutable('2060-01-02 12:00:00 UTC');
    }

    public static function aCount(): self
    {
        return new self();
    }

    public function withBucketStart(\DateTimeImmutable $bucketStart): self
    {
        $copy = clone $this;
        $copy->bucketStart = $bucketStart;

        return $copy;
    }

    public function withOperation(string $operation): self
    {
        $copy = clone $this;
        $copy->operation = $operation;

        return $copy;
    }

    public function withAttempts(int $attempts): self
    {
        $copy = clone $this;
        $copy->attempts = $attempts;

        return $copy;
    }

    public function build(): PerformanceHttpLogicalCount
    {
        return new PerformanceHttpLogicalCount($this->bucketStart, $this->operation, $this->attempts);
    }

    public function persistWith(Connection $connection): PerformanceHttpLogicalCount
    {
        $count = $this->build();
        $connection->executeStatement(
            'INSERT INTO performance_http_logical_count (bucket_start, operation, attempts) VALUES (:at, :operation, :attempts) ON CONFLICT (bucket_start, operation) DO UPDATE SET attempts=EXCLUDED.attempts',
            ['at' => $count->bucketStart()->format('Y-m-d H:i:sP'), 'operation' => $count->operation(), 'attempts' => $count->attempts()],
        );

        return $count;
    }
}
