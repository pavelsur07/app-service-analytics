<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Repository;

use App\ProductResearchOzon\Domain\RunDeliveryRepository;
use App\ProductResearchOzon\Domain\RunSyncFailure;
use App\ProductResearchOzon\Domain\ValueObject\RunDelivery;
use App\ProductResearchOzon\Domain\ValueObject\RunProgress;
use App\ProductResearchOzon\Infrastructure\Query\ResearchRunsQuery;
use Doctrine\DBAL\Connection;

final readonly class DbalRunDeliveryRepository implements RunDeliveryRepository
{
    public function __construct(private Connection $db, private ResearchRunsQuery $query)
    {
    }

    public function claimDelivery(string $companyId, string $runId, string $owner, \DateTimeImmutable $at): ?RunDelivery
    {
        $row = $this->db->fetchAssociative("UPDATE product_research_ozon_run SET lease_id = :owner, lease_until = clock_timestamp() + INTERVAL '60 seconds', delivery_attempts = delivery_attempts + 1 WHERE company_id = :company AND id = :run AND delivered_at IS NULL AND finished_at IS NULL AND next_delivery_at <= :at AND (lease_until IS NULL OR lease_until <= clock_timestamp()) RETURNING project_id, source_id, payload, payload_hash, delivery_attempts", self::params($companyId, $runId, $owner, $at));
        if (false === $row) {
            return null;
        }
        if (!\is_int($row['delivery_attempts'])) {
            throw new RunSyncFailure('invalid_run_response');
        }

        return new RunDelivery($companyId, $runId, ResearchRunsQuery::text($row, 'project_id'), ResearchRunsQuery::text($row, 'source_id'), ResearchRunsQuery::text($row, 'payload'), ResearchRunsQuery::text($row, 'payload_hash'), $row['delivery_attempts']);
    }

    public function delivered(string $companyId, string $runId, string $owner, \DateTimeImmutable $at): bool
    {
        return 1 === $this->db->executeStatement('UPDATE product_research_ozon_run SET delivered_at = :at, next_poll_at = :at, lease_id = NULL, lease_until = NULL, error_code = NULL WHERE company_id = :company AND id = :run AND lease_id = :owner AND lease_until > clock_timestamp() AND delivered_at IS NULL', self::params($companyId, $runId, $owner, $at));
    }

    public function claimPoll(string $companyId, string $runId, string $owner, \DateTimeImmutable $at, bool $repair = false): ?string
    {
        $condition = $repair ? '' : 'AND finished_at IS NULL AND next_poll_at <= :at';
        $params = self::params($companyId, $runId, $owner, $at);
        if ($repair) {
            unset($params['at']);
        }
        $row = $this->db->fetchAssociative("UPDATE product_research_ozon_run SET lease_id = :owner, lease_until = clock_timestamp() + INTERVAL '60 seconds' WHERE company_id = :company AND id = :run AND delivered_at IS NOT NULL $condition AND (lease_until IS NULL OR lease_until <= clock_timestamp()) RETURNING source_id", $params);

        return false === $row ? null : ResearchRunsQuery::text($row, 'source_id');
    }

    public function apply(string $companyId, string $runId, string $owner, RunProgress $progress, \DateTimeImmutable $at): bool
    {
        return $this->db->transactional(function () use ($companyId, $runId, $owner, $progress, $at): bool {
            $row = $this->db->fetchAssociative('SELECT project_id, source_id, revision, progress_hash, progress_format FROM product_research_ozon_run WHERE company_id = :company AND id = :run AND lease_id = :owner AND lease_until > clock_timestamp() AND delivered_at IS NOT NULL FOR UPDATE', ['company' => $companyId, 'run' => $runId, 'owner' => $owner]);
            if (false === $row) {
                return false;
            }
            if ($progress->companyId !== $companyId || $progress->runId !== $runId || $progress->sourceId !== $row['source_id']) {
                throw new RunSyncFailure('run_source_mismatch');
            }
            if ($progress->revision < $row['revision']) {
                $this->db->executeStatement('UPDATE product_research_ozon_run SET lease_id=NULL,lease_until=NULL,next_poll_at=:next WHERE company_id=:company AND id=:run AND lease_id=:owner', ['next' => $at->modify('+60 seconds')->format('Y-m-d H:i:s.uP'), 'company' => $companyId, 'run' => $runId, 'owner' => $owner]);

                return true;
            }
            $view = ResearchRunsQuery::map($this->query->detail($companyId, ResearchRunsQuery::text($row, 'project_id'), $runId)->executeQuery()->fetchAllAssociative());
            if (null === $view || \count($view->progress->months) !== \count($progress->months)) {
                throw new RunSyncFailure('run_progress_conflict');
            }
            if (\in_array($view->progress->status, ['ready', 'error'], true) && !\in_array($progress->status, ['ready', 'error'], true)) {
                throw new RunSyncFailure('run_progress_conflict');
            }
            if (1 === $row['progress_format'] && $progress->revision === $row['revision'] && $view->progress->payload() !== $progress->payload()) {
                throw new RunSyncFailure('run_progress_conflict');
            }
            foreach ($view->progress->months as $index => $old) {
                $new = $progress->months[$index];
                if ($old->periodPayload() !== $new->periodPayload() || ('ready' === $old->status && $old->payload() !== $new->payload()) || (null !== $old->provenance && $old->provenance->payload() !== $new->provenance?->payload())) {
                    throw new RunSyncFailure('run_progress_conflict');
                }
            }
            if ($progress->revision === $row['revision'] && 2 === $row['progress_format'] && $progress->hash() !== $row['progress_hash']) {
                throw new RunSyncFailure('run_progress_conflict');
            }
            if ($progress->revision > $row['revision'] || ($progress->revision === $row['revision'] && 1 === $row['progress_format'])) {
                $values = json_encode(array_map(static fn ($month): array => [...$month->payload(), ...($month->provenance?->payload() ?? ['origin' => null, 'collected_at' => null, 'used_at' => null])], $progress->months), \JSON_THROW_ON_ERROR);
                $this->db->executeStatement("UPDATE product_research_ozon_run_month m SET status = value.status, snapshot_id = value.snapshot_id, row_count = value.row_count, error_code = value.error_code, origin = value.origin, collected_at = value.collected_at, used_at = value.used_at FROM jsonb_to_recordset(CAST(:values AS jsonb)) AS value(period jsonb, status text, snapshot_id uuid, row_count integer, error_code text, origin text, collected_at timestamptz, used_at timestamptz) WHERE m.company_id = :company AND m.run_id = :run AND m.month = value.period->>'month'", ['values' => $values, 'company' => $companyId, 'run' => $runId]);
            }
            $accepted = $progress->revision >= $row['revision'];
            $terminal = $accepted && \in_array($progress->status, ['ready', 'error'], true);
            $params = self::params($companyId, $runId, $owner, $at) + ['next' => $at->modify('+60 seconds')->format('Y-m-d H:i:s.uP'), 'finished' => $terminal ? $at->format('Y-m-d H:i:s.uP') : null, 'status' => $accepted ? $progress->status : $view->progress->status, 'revision' => $accepted ? $progress->revision : $row['revision'], 'hash' => $accepted ? $progress->hash() : $row['progress_hash']];
            unset($params['at']);
            $this->db->executeStatement('UPDATE product_research_ozon_run SET status = :status, revision = :revision, progress_hash = :hash, progress_format = 2, finished_at = COALESCE(finished_at, :finished), next_poll_at = :next, lease_id = NULL, lease_until = NULL, error_code = NULL WHERE company_id = :company AND id = :run AND lease_id = :owner', $params);

            return true;
        });
    }

    public function failed(string $companyId, string $runId, string $owner, string $reason, \DateTimeImmutable $at): void
    {
        $safe = new RunSyncFailure($reason);
        $params = self::params($companyId, $runId, $owner, $at) + ['reason' => $safe->reason];
        $this->db->executeStatement("UPDATE product_research_ozon_run SET error_code = :reason, next_delivery_at = CAST(:at AS timestamptz) + LEAST(3600, 60 * power(2, LEAST(delivery_attempts, 6))) * INTERVAL '1 second', next_poll_at = CAST(:at AS timestamptz) + INTERVAL '60 seconds', lease_id = NULL, lease_until = NULL WHERE company_id = :company AND id = :run AND lease_id = :owner AND lease_until > clock_timestamp()", $params);
    }

    /** @return array{company: string, run: string, owner: string, at: string} */
    private static function params(string $companyId, string $runId, string $owner, \DateTimeImmutable $at): array
    {
        return ['company' => $companyId, 'run' => $runId, 'owner' => $owner, 'at' => $at->format('Y-m-d H:i:s.uP')];
    }
}
