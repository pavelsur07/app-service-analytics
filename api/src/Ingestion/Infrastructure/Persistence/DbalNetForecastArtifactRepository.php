<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\NetForecastArtifactPublication;
use App\Ingestion\Domain\NetForecastArtifactRepository;
use App\Ingestion\Domain\NetForwardArtifactKey;
use App\Ingestion\Domain\NetForwardArtifactKind;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class DbalNetForecastArtifactRepository implements NetForecastArtifactRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function publishFirst(string $companyId, string $accountId, NetForecastArtifactPublication $candidate, int $deadlineNs): NetForecastArtifactPublication
    {
        $candidate->root->assertScope($companyId, $accountId);
        if ($deadlineNs <= hrtime(true)) {
            throw new \OverflowException('Artifact publication deadline exceeded.');
        }
        $native = $this->connection->getNativeConnection();
        if ($this->connection->isTransactionActive() || ($native instanceof \PDO && $native->inTransaction())) {
            throw new \LogicException('Publication requires no active transaction.');
        }

        // transactional() returns only after COMMIT. A lost response is unavailable;
        // the original request identity resolves the committed winner on retry.
        return $this->connection->transactional(function () use ($companyId, $accountId, $candidate, $deadlineNs): NetForecastArtifactPublication {
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED, READ WRITE');
            $this->setRemainingTimeouts($deadlineNs);
            $key = $candidate->root;
            $this->connection->executeStatement('INSERT INTO net_forecast_artifact (company_id, marketplace_account_id, kind, request_id, request_hash, attempt_id, artifact_hash, manifest_byte_size, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP) ON CONFLICT (company_id, marketplace_account_id, kind, request_id) DO NOTHING', [$companyId, $accountId, $key->kind->value, $key->requestId, $candidate->requestHash, $key->attemptId, $key->sha256, $candidate->manifestByteSize]);
            // Separate statement: concurrent committed winner is visible under RC.
            $this->setRemainingTimeouts($deadlineNs);
            $winner = $this->find($companyId, $accountId, $key->kind, $key->requestId) ?? throw new \RuntimeException('Publication winner unavailable.');
            if ($winner->requestHash !== $candidate->requestHash) {
                throw new \DomainException('Artifact request identity conflicts.');
            }

            return $winner;
        });
    }

    private function setRemainingTimeouts(int $deadlineNs): void
    {
        $remaining = $deadlineNs - hrtime(true);
        if ($remaining < 1000000) {
            throw new \OverflowException('Artifact publication deadline exceeded.');
        }
        $timeoutMs = intdiv($remaining, 1000000);
        $this->connection->executeStatement('SET LOCAL statement_timeout = '.$timeoutMs);

        $remaining = $deadlineNs - hrtime(true);
        if ($remaining < 1000000) {
            throw new \OverflowException('Artifact publication deadline exceeded.');
        }
        $this->connection->executeStatement('SET LOCAL lock_timeout = '.intdiv($remaining, 1000000));
    }

    public function find(string $companyId, string $accountId, NetForwardArtifactKind $kind, string $requestId): ?NetForecastArtifactPublication
    {
        return $this->findBatch($companyId, $accountId, [['kind' => $kind, 'requestId' => $requestId]])[0] ?? null;
    }

    public function findBatch(string $companyId, string $accountId, array $identities): array
    {
        if (\count($identities) > 50 || !Uuid::isValid($companyId) || !Uuid::isValid($accountId)) {
            throw new \InvalidArgumentException('Invalid scoped artifact lookup.');
        }
        if ([] === $identities) {
            return [];
        }
        $clauses = [];
        $params = [$companyId, $accountId];
        foreach ($identities as $identity) {
            if (!Uuid::isValid($identity['requestId'])) {
                throw new \InvalidArgumentException('Invalid request UUID.');
            }
            $clauses[] = '(kind = ? AND request_id = ?)';
            $params[] = $identity['kind']->value;
            $params[] = $identity['requestId'];
        }
        $rows = $this->connection->fetchAllAssociative('SELECT company_id, marketplace_account_id, kind, request_id, request_hash, attempt_id, artifact_hash, manifest_byte_size FROM net_forecast_artifact WHERE company_id = ? AND marketplace_account_id = ? AND ('.implode(' OR ', $clauses).') ORDER BY kind, request_id LIMIT 50', $params);

        return array_map(static function (array $row): NetForecastArtifactPublication {
            foreach (['company_id', 'marketplace_account_id', 'kind', 'request_id', 'request_hash', 'attempt_id', 'artifact_hash'] as $column) {
                if (!\is_string($row[$column])) {
                    throw new \UnexpectedValueException('Invalid publication column.');
                }
            }
            if (!\is_int($row['manifest_byte_size'])) {
                throw new \UnexpectedValueException('Invalid publication size.');
            }

            return new NetForecastArtifactPublication(new NetForwardArtifactKey($row['company_id'], $row['marketplace_account_id'], NetForwardArtifactKind::from($row['kind']), $row['request_id'], $row['attempt_id'], $row['artifact_hash']), $row['request_hash'], $row['manifest_byte_size']);
        }, $rows);
    }
}
