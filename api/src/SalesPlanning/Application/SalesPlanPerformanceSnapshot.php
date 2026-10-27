<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use Doctrine\DBAL\Connection;

/** Actual transaction properties, never an assumed isolation based on DBAL defaults. */
final readonly class SalesPlanPerformanceSnapshot
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $read
     *
     * @return T
     */
    public function read(callable $read): mixed
    {
        $native = $this->connection->getNativeConnection();
        if ($this->connection->isTransactionActive() || ($native instanceof \PDO && $native->inTransaction())) {
            $isolation = $this->connection->fetchOne('SHOW transaction_isolation');
            $readOnly = $this->connection->fetchOne('SHOW transaction_read_only');
            if (!\in_array($isolation, ['repeatable read', 'serializable'], true) || 'on' !== $readOnly) {
                throw new SalesPlanPerformanceReadException('performance_snapshot_unsupported', 409);
            }

            return $read();
        }

        return $this->connection->transactional(function () use ($read): mixed {
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $read();
        });
    }
}
