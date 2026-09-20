<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\PlanningIngestionResolutionObservation;
use Symfony\Component\Uid\Uuid;

final class PlanningIngestionResolutionObservationBuilder
{
    private Uuid $companyId;
    private Uuid $accountId;
    private string $sourceRowId = 'TEST-POSTING|TEST-SKU';
    private string $allocationKey = '1';
    private string $outcome = 'D';
    private \DateTimeImmutable $firstObservedAt;
    private ?\DateTimeImmutable $firstKnownOutcomeAt = null;
    private ?\DateTimeImmutable $firstRegularlyObservedAt = null;
    private ?string $dateLineage = null;
    private bool $backfill = true;

    private function __construct()
    {
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
        $this->firstObservedAt = new \DateTimeImmutable('2026-09-20 10:00:00');
    }

    public static function anObservation(): self
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

    public function withAllocation(string $sourceRowId, string $allocationKey, string $outcome): self
    {
        $clone = clone $this;
        $clone->sourceRowId = $sourceRowId;
        $clone->allocationKey = $allocationKey;
        $clone->outcome = $outcome;

        return $clone;
    }

    public function withFirstObservedAt(\DateTimeImmutable $value): self
    {
        $clone = clone $this;
        $clone->firstObservedAt = $value;

        return $clone;
    }

    public function withFirstKnownOutcomeAt(?\DateTimeImmutable $value): self
    {
        $clone = clone $this;
        $clone->firstKnownOutcomeAt = $value;

        return $clone;
    }

    public function withFirstRegularlyObservedAt(?\DateTimeImmutable $value): self
    {
        $clone = clone $this;
        $clone->firstRegularlyObservedAt = $value;

        return $clone;
    }

    public function withBackfill(bool $value): self
    {
        $clone = clone $this;
        $clone->backfill = $value;

        return $clone;
    }

    public function withDateLineage(?string $value): self
    {
        $clone = clone $this;
        $clone->dateLineage = $value;

        return $clone;
    }

    public function build(): PlanningIngestionResolutionObservation
    {
        return new PlanningIngestionResolutionObservation(
            $this->companyId, $this->accountId, $this->sourceRowId, $this->allocationKey, $this->outcome,
            $this->firstObservedAt, $this->firstKnownOutcomeAt, $this->firstRegularlyObservedAt,
            null, $this->backfill, null, 0, $this->dateLineage,
        );
    }

    /** @return array<string, int|string|null> */
    public function row(): array
    {
        return [
            'company_id' => $this->companyId->toRfc4122(),
            'marketplace_account_id' => $this->accountId->toRfc4122(),
            'source_row_id' => $this->sourceRowId,
            'allocation_key' => $this->allocationKey,
            'outcome' => $this->outcome,
            'first_observed_at' => $this->firstObservedAt->format('Y-m-d H:i:s'),
            'undated_since_at' => null,
            'first_known_outcome_at' => $this->firstKnownOutcomeAt?->format('Y-m-d H:i:s'),
            'first_regularly_observed_at' => $this->firstRegularlyObservedAt?->format('Y-m-d H:i:s'),
            'date_lineage' => $this->dateLineage,
            'source_event_at' => null,
            'backfill' => $this->backfill ? 'true' : 'false',
            'raw_document_id' => null,
            'last_seen_run' => 0,
        ];
    }
}
