<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\PlanningIngestionSourceRowRun;
use Symfony\Component\Uid\Uuid;

final class PlanningIngestionSourceRowRunBuilder
{
    private Uuid $companyId;
    private Uuid $accountId;
    private string $sourceRowId = 'TEST-POSTING|TEST-SKU';
    private int $lastSeenRun = 0;

    private function __construct()
    {
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
    }

    public static function aSourceRowRun(): self
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

    public function withSourceRowId(string $value): self
    {
        $clone = clone $this;
        $clone->sourceRowId = $value;

        return $clone;
    }

    public function withLastSeenRun(int $value): self
    {
        $clone = clone $this;
        $clone->lastSeenRun = $value;

        return $clone;
    }

    public function build(): PlanningIngestionSourceRowRun
    {
        return new PlanningIngestionSourceRowRun($this->companyId, $this->accountId, $this->sourceRowId, $this->lastSeenRun);
    }

    /** @return array{company_id: string, marketplace_account_id: string, source_row_id: string, last_seen_run: int} */
    public function row(): array
    {
        return [
            'company_id' => $this->companyId->toRfc4122(),
            'marketplace_account_id' => $this->accountId->toRfc4122(),
            'source_row_id' => $this->sourceRowId,
            'last_seen_run' => $this->lastSeenRun,
        ];
    }
}
