<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\PlanningIngestionDayCoverage;
use Symfony\Component\Uid\Uuid;

final class PlanningIngestionDayCoverageBuilder
{
    private Uuid $companyId;
    private Uuid $accountId;
    private \DateTimeImmutable $date;
    private ?\DateTimeImmutable $postingsCheckedAt = null;
    private ?\DateTimeImmutable $returnsCheckedAt = null;

    private function __construct()
    {
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
        $this->date = new \DateTimeImmutable('2026-09-20');
    }

    public static function aDayCoverage(): self
    {
        return new self();
    }

    public function withCompanyId(Uuid $value): self
    {
        $clone = clone $this;
        $clone->companyId = $value;

        return $clone;
    }

    public function withMarketplaceAccountId(Uuid $value): self
    {
        $clone = clone $this;
        $clone->accountId = $value;

        return $clone;
    }

    public function withObservationDate(\DateTimeImmutable $value): self
    {
        $clone = clone $this;
        $clone->date = $value;

        return $clone;
    }

    public function withChecks(?\DateTimeImmutable $postings, ?\DateTimeImmutable $returns): self
    {
        $clone = clone $this;
        $clone->postingsCheckedAt = $postings;
        $clone->returnsCheckedAt = $returns;

        return $clone;
    }

    public function build(): PlanningIngestionDayCoverage
    {
        return new PlanningIngestionDayCoverage($this->companyId, $this->accountId, $this->date, $this->postingsCheckedAt, $this->returnsCheckedAt);
    }

    /** @return array{company_id: string, marketplace_account_id: string, observation_date: string, postings_checked_at: ?string, returns_checked_at: ?string} */
    public function row(): array
    {
        return [
            'company_id' => $this->companyId->toRfc4122(),
            'marketplace_account_id' => $this->accountId->toRfc4122(),
            'observation_date' => $this->date->format('Y-m-d'),
            'postings_checked_at' => $this->postingsCheckedAt?->format('Y-m-d H:i:s'),
            'returns_checked_at' => $this->returnsCheckedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
