<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;

final readonly class PlanningSourceStateQuery
{
    // Bump when parser or buyout_outcome interpretation changes without raw changes.
    private const string INTERPRETATION_VERSION = '1';

    public function __construct(private Connection $connection)
    {
    }

    /** @phpstan-impure */
    public function version(string $companyId, string $accountId): string
    {
        $version = $this->connection->fetchOne(
            'SELECT generation FROM planning_ingestion_account_state WHERE company_id = ? AND marketplace_account_id = ?',
            [$companyId, $accountId],
        );

        if (false === $version) {
            return self::INTERPRETATION_VERSION.':0';
        }
        if (!\is_int($version) && !\is_string($version)) {
            throw new \UnexpectedValueException('Planning account generation is not an integer.');
        }

        return self::INTERPRETATION_VERSION.':'.$version;
    }

    /**
     * @return array{complete: bool, lastCompleteAt: ?string}
     *
     * @phpstan-impure
     */
    public function orderCoverage(string $companyId, string $accountId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $state = $this->connection->fetchAssociative(
            <<<'SQL'
                WITH days AS (
                    SELECT day::date AS business_date FROM generate_series(?::date, ?::date, INTERVAL '1 day') AS day
                ), covered AS (
                    SELECT d.business_date,
                           p.last_complete_at AS posting_check,
                           r.last_complete_at AS return_check
                    FROM days d
                    LEFT JOIN planning_ingestion_source_state p
                      ON p.company_id = ? AND p.marketplace_account_id = ? AND p.source_kind = 'postings'
                     AND p.from_date = d.business_date AND p.to_date = d.business_date
                    LEFT JOIN LATERAL (
                        SELECT MAX(last_complete_at) AS last_complete_at
                        FROM planning_ingestion_source_state s
                        WHERE s.company_id = ? AND s.marketplace_account_id = ? AND s.source_kind = 'returns'
                          AND s.from_date <= d.business_date AND s.to_date >= d.business_date
                    ) r ON TRUE
                )
                SELECT COUNT(*) FILTER (WHERE posting_check IS NOT NULL AND return_check IS NOT NULL)::int AS covered_days,
                       MIN(LEAST(posting_check, return_check)) FILTER (WHERE posting_check IS NOT NULL AND return_check IS NOT NULL)::text AS oldest_check
                FROM covered
                SQL,
            [$from->format('Y-m-d'), $to->format('Y-m-d'), $companyId, $accountId, $companyId, $accountId],
        );
        if (false === $state || (!\is_int($state['covered_days'] ?? null) && !\is_string($state['covered_days'] ?? null))) {
            throw new \UnexpectedValueException('Planning order coverage query returned invalid data.');
        }
        $expectedDays = $this->dayCount($from, $to);
        $covered = (int) $state['covered_days'];
        $lastCheck = $state['oldest_check'] ?? null;

        return ['complete' => $covered === $expectedDays, 'lastCompleteAt' => \is_string($lastCheck) ? $lastCheck : null];
    }

    /**
     * @return array{complete: bool, lastCompleteAt: ?string}
     *
     * @phpstan-impure
     */
    public function observationCoverage(string $companyId, string $accountId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $state = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT COUNT(*)::int AS covered_days,
                       MIN(LEAST(postings_checked_at, returns_checked_at))::text AS oldest_check
                FROM planning_ingestion_day_coverage coverage
                JOIN planning_ingestion_account_state account
                  ON account.company_id = coverage.company_id
                 AND account.marketplace_account_id = coverage.marketplace_account_id
                WHERE coverage.company_id = ? AND coverage.marketplace_account_id = ?
                  AND coverage.observation_date BETWEEN ? AND ?
                  AND account.baseline_completed_at IS NOT NULL
                  AND coverage.observation_date > account.baseline_completed_at::date
                  AND postings_checked_at IS NOT NULL AND returns_checked_at IS NOT NULL
                SQL,
            [$companyId, $accountId, $from->format('Y-m-d'), $to->format('Y-m-d')],
        );
        if (false === $state || (!\is_int($state['covered_days'] ?? null) && !\is_string($state['covered_days'] ?? null))) {
            throw new \UnexpectedValueException('Planning observation coverage query returned invalid data.');
        }
        $expectedDays = $this->dayCount($from, $to);
        $covered = (int) $state['covered_days'];
        $lastCheck = $state['oldest_check'] ?? null;

        return ['complete' => $covered === $expectedDays, 'lastCompleteAt' => \is_string($lastCheck) ? $lastCheck : null];
    }

    private function dayCount(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $first = new \DateTimeImmutable($from->format('Y-m-d'));
        $last = new \DateTimeImmutable($to->format('Y-m-d'));

        return (int) $first->diff($last)->format('%a') + 1;
    }
}
