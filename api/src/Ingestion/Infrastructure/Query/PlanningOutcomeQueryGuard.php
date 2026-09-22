<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/** Keeps read planner settings local; write callbacks retain their transaction settings. */
final readonly class PlanningOutcomeQueryGuard
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
    public function read(callable $read, string $statementTimeout = '5s', bool $tuneAnalyticsPlanner = true): mixed
    {
        $native = $this->connection->getNativeConnection();
        if ($this->connection->isTransactionActive() || ($native instanceof \PDO && $native->inTransaction())) {
            return $this->withinSavepoint($read, $statementTimeout, $tuneAnalyticsPlanner);
        }

        return $this->connection->transactional(static function (Connection $connection) use ($read, $statementTimeout, $tuneAnalyticsPlanner): mixed {
            $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            self::configure($connection, $statementTimeout, $tuneAnalyticsPlanner);

            return $read();
        });
    }

    /** @param callable(): mixed $write */
    public function write(callable $write): void
    {
        if (!$this->connection->isTransactionActive()) {
            $this->connection->transactional(fn (): null => $this->writeInTransaction($write));

            return;
        }

        $this->writeInTransaction($write);
    }

    /** @param callable(): mixed $write */
    private function writeInTransaction(callable $write): null
    {
        $this->connection->createSavepoint('planning_outcome_write_guard');
        try {
            $write();
            $this->connection->releaseSavepoint('planning_outcome_write_guard');
        } catch (\Throwable $failure) {
            $this->connection->rollbackSavepoint('planning_outcome_write_guard');
            $this->connection->releaseSavepoint('planning_outcome_write_guard');
            throw $failure;
        }

        return null;
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function withinSavepoint(callable $work, string $statementTimeout, bool $tuneAnalyticsPlanner): mixed
    {
        $this->connection->createSavepoint('planning_outcome_guard');
        try {
            self::configure($this->connection, $statementTimeout, $tuneAnalyticsPlanner);

            return $work();
        } finally {
            $this->connection->rollbackSavepoint('planning_outcome_guard');
            $this->connection->releaseSavepoint('planning_outcome_guard');
        }
    }

    private static function configure(Connection $connection, string $statementTimeout, bool $tuneAnalyticsPlanner): void
    {
        if (!\in_array($statementTimeout, ['5s', '25s'], true)) {
            throw new \InvalidArgumentException('Некорректный таймаут запроса планирования.');
        }
        if ($tuneAnalyticsPlanner) {
            $connection->executeStatement('SET LOCAL jit = off');
            $connection->executeStatement('SET LOCAL enable_nestloop = off');
        }
        $connection->executeStatement("SET LOCAL statement_timeout = '".$statementTimeout."'");
    }
}
