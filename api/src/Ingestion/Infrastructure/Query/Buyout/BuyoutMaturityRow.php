<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Buyout;

/** Percentiles времени от конца дня заказа до закрытия (ADR-029) для одного кабинета. */
final readonly class BuyoutMaturityRow
{
    public function __construct(
        public string $companyId,
        public string $marketplaceAccountId,
        public int $sampleSize,
        public ?int $p50Seconds,
        public ?int $p90Seconds,
        public ?int $p95Seconds,
    ) {
    }

    public function isCohortMature(\DateTimeImmutable $cohortBoundary, \DateTimeImmutable $asOf): bool
    {
        return null !== $this->p95Seconds
            && $asOf->getTimestamp() - $cohortBoundary->getTimestamp() > $this->p95Seconds;
    }
}
