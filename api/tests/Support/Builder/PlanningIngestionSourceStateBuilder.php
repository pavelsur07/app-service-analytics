<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\PlanningIngestionSourceState;
use Symfony\Component\Uid\Uuid;

final class PlanningIngestionSourceStateBuilder
{
    private Uuid $companyId;
    private Uuid $accountId;
    private string $kind = 'postings';
    private \DateTimeImmutable $from;
    private \DateTimeImmutable $to;

    private function __construct()
    {
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
        $this->from = new \DateTimeImmutable('2026-09-20');
        $this->to = $this->from;
    }

    public static function aSourceState(): self
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

    public function withWindow(string $kind, \DateTimeImmutable $from, \DateTimeImmutable $to): self
    {
        $clone = clone $this;
        $clone->kind = $kind;
        $clone->from = $from;
        $clone->to = $to;

        return $clone;
    }

    public function build(): PlanningIngestionSourceState
    {
        return new PlanningIngestionSourceState(
            $this->companyId, $this->accountId, $this->kind, $this->from, $this->to,
            hash('sha256', 'source-state-builder'), new \DateTimeImmutable('2026-09-20 10:00:00'),
            null, null, 'rescan',
        );
    }
}
