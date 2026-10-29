<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Infrastructure\Query\NetForecast\NetForwardEvidenceQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetForwardEvidenceRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetRequest;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use Doctrine\DBAL\Connection;

final readonly class ReadNetForwardEvidenceAction
{
    public function __construct(private Connection $connection, private NetForwardEvidenceQuery $evidence)
    {
    }

    /** One source calculation in the caller's pinned RR; transfer remains <=200 rows/1MiB per page.
     * @return \Generator<int, list<NetForwardEvidenceRow>> */
    public function pages(string $companyId, string $accountId, NetTrainingContext $context, NetTargetRequest $request, string $population, ?int $deadlineNs = null): \Generator
    {
        $context->assertScope($companyId, $accountId);
        if (!$this->connection->isTransactionActive() || 'on' !== $this->connection->fetchOne('SHOW transaction_read_only') || !\in_array($this->connection->fetchOne('SHOW transaction_isolation'), ['repeatable read', 'serializable'], true) || $context->snapshotId !== $this->connection->fetchOne('SELECT txid_current_snapshot()::text')) {
            throw new \LogicException('Evidence cursor requires the matching pinned read-only snapshot.');
        }
        if (null !== $deadlineNs && hrtime(true) >= $deadlineNs) {
            throw new \OverflowException('Evidence cursor deadline exceeded.');
        }
        $query = $this->evidence->buildCursor($companyId, $accountId, $context, $request, $population);
        $cursor = 'net_evidence_'.bin2hex(random_bytes(8));
        $this->connection->executeStatement('DECLARE '.$cursor.' NO SCROLL CURSOR FOR '.$query->getSQL(), $query->getParameters(), $query->getParameterTypes());
        $completed = false;
        try {
            do {
                if (null !== $deadlineNs && hrtime(true) >= $deadlineNs) {
                    throw new \OverflowException('Evidence cursor deadline exceeded.');
                }
                $page = NetForwardEvidenceQuery::mapRows($this->connection->executeQuery('FETCH FORWARD '.NetForwardEvidenceQuery::PAGE_SIZE.' FROM '.$cursor));
                if ([] !== $page) {
                    yield $page;
                }
            } while (NetForwardEvidenceQuery::PAGE_SIZE === \count($page));
            $completed = true;
        } finally {
            if ($completed) {
                $this->connection->executeStatement('CLOSE '.$cursor);
            } else {
                try {
                    $this->connection->executeStatement('CLOSE '.$cursor);
                } catch (\Throwable) {
                    // Preserve the original failure; the owning transaction also closes cursors on rollback.
                }
            }
        }
    }
}
