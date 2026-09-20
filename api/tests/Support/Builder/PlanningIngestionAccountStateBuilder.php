<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\PlanningIngestionAccountState;
use Symfony\Component\Uid\Uuid;

final class PlanningIngestionAccountStateBuilder
{
    private Uuid $companyId;
    private Uuid $accountId;
    private int $generation = 1;
    private \DateTimeImmutable $updatedAt;
    private ?\DateTimeImmutable $baselineCompletedAt = null;

    private function __construct()
    {
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
        $this->updatedAt = new \DateTimeImmutable('2026-09-20 10:00:00');
    }

    public static function anAccountState(): self
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

    public function withGeneration(int $value): self
    {
        $clone = clone $this;
        $clone->generation = $value;

        return $clone;
    }

    public function withUpdatedAt(\DateTimeImmutable $value): self
    {
        $clone = clone $this;
        $clone->updatedAt = $value;

        return $clone;
    }

    public function withBaselineCompletedAt(\DateTimeImmutable $value): self
    {
        $clone = clone $this;
        $clone->baselineCompletedAt = $value;

        return $clone;
    }

    public function build(): PlanningIngestionAccountState
    {
        return new PlanningIngestionAccountState($this->companyId, $this->accountId, $this->generation, $this->updatedAt, $this->baselineCompletedAt);
    }

    /** @return array{company_id: string, marketplace_account_id: string, generation: int, updated_at: string, baseline_completed_at: ?string} */
    public function row(): array
    {
        return [
            'company_id' => $this->companyId->toRfc4122(),
            'marketplace_account_id' => $this->accountId->toRfc4122(),
            'generation' => $this->generation,
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
            'baseline_completed_at' => $this->baselineCompletedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
