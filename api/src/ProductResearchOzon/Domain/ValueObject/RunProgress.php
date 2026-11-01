<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain\ValueObject;

final readonly class RunProgress
{
    /** @var list<RunMonthState> */
    public array $months;

    /** @param list<RunMonthState> $months */
    public function __construct(public string $companyId, public string $runId, public string $sourceId, public string $status, public int $revision, array $months)
    {
        if (!self::validId($companyId) || !self::validId($runId) || !self::validId($sourceId) || $revision < 1 || $revision > 2147483647 || !\in_array($status, ['queued', 'running', 'ready', 'error'], true) || [] === $months || \count($months) > 24) {
            throw new \InvalidArgumentException('invalid_run_progress');
        }
        $keys = [];
        $active = false;
        $failed = false;
        foreach ($months as $month) {
            if (isset($keys[$month->period->month])) {
                throw new \InvalidArgumentException('invalid_run_progress');
            }
            $keys[$month->period->month] = true;
            $active = $active || \in_array($month->status, ['queued', 'running'], true);
            $failed = $failed || 'error' === $month->status;
            if ('queued' === $status && 'queued' !== $month->status) {
                throw new \InvalidArgumentException('invalid_run_progress');
            }
        }
        if (('ready' === $status && ($active || $failed)) || ('error' === $status && ($active || !$failed)) || ('running' === $status && !$active)) {
            throw new \InvalidArgumentException('invalid_run_progress');
        }
        usort($months, static fn (RunMonthState $left, RunMonthState $right): int => strcmp($left->period->month, $right->period->month));
        $this->months = $months;
    }

    public static function validId(string $id): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return ['schema_version' => '1', 'company_id' => $this->companyId, 'run_id' => $this->runId, 'status' => $this->status, 'revision' => $this->revision, 'months' => array_map(static fn (RunMonthState $month): array => $month->payload(), $this->months)];
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->payload(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }
}
