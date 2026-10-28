<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use Doctrine\DBAL\Connection;

final readonly class NetTrainingSnapshot
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @template T
     *
     * @param callable(string): T $read
     *
     * @return T
     */
    public function read(callable $read): mixed
    {
        $native = $this->connection->getNativeConnection();
        if ($this->connection->isTransactionActive() || ($native instanceof \PDO && $native->inTransaction())) {
            if (!\in_array($this->connection->fetchOne('SHOW transaction_isolation'), ['repeatable read', 'serializable'], true)
                || 'on' !== $this->connection->fetchOne('SHOW transaction_read_only')) {
                throw new NetTrainingReadException('training_snapshot_unsupported');
            }
            $this->connection->createSavepoint('net_training_read_guard');
            try {
                return $read($this->pin());
            } finally {
                $this->connection->rollbackSavepoint('net_training_read_guard');
                $this->connection->releaseSavepoint('net_training_read_guard');
            }
        }

        return $this->connection->transactional(function () use ($read): mixed {
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $read($this->pin());
        });
    }

    private function pin(): string
    {
        // Establish the actual visibility snapshot before Clock/readAt, not after it.
        $snapshot = $this->connection->fetchOne('SELECT txid_current_snapshot()::text');
        if (!\is_string($snapshot) || '' === $snapshot) {
            throw new NetTrainingReadException('training_snapshot_unsupported');
        }

        return $snapshot;
    }
}
