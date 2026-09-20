<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\PlanningIngestionSourceRawDocument;
use Symfony\Component\Uid\Uuid;

final class PlanningIngestionSourceRawDocumentBuilder
{
    private Uuid $companyId;
    private Uuid $accountId;
    private Uuid $rawId;

    private function __construct()
    {
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
        $this->rawId = Uuid::v7();
    }

    public static function aSourceRawDocument(): self
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

    public function withRawDocumentId(Uuid $value): self
    {
        $clone = clone $this;
        $clone->rawId = $value;

        return $clone;
    }

    public function build(): PlanningIngestionSourceRawDocument
    {
        $day = new \DateTimeImmutable('2026-09-20');

        return new PlanningIngestionSourceRawDocument($this->companyId, $this->accountId, 'postings', $day, $day, $this->rawId);
    }
}
