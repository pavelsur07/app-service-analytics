<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\FactCaptureOrigin;
use App\Ingestion\Domain\SalesFact;
use App\Ingestion\Domain\SalesFactRepository;

/** SalesFactRevision fixture: accepted history comes only from the real writer. */
final class SalesFactRevisionBuilder
{
    private function __construct(
        private SalesFactBuilder $fact,
        private FactCaptureOrigin $origin = FactCaptureOrigin::Unknown,
    ) {
    }

    public static function aSalesFactRevision(): self
    {
        return new self(SalesFactBuilder::aSalesFact());
    }

    public function withFact(SalesFactBuilder $fact): self
    {
        $clone = clone $this;
        $clone->fact = $fact;

        return $clone;
    }

    public function withOrigin(FactCaptureOrigin $origin): self
    {
        $clone = clone $this;
        $clone->origin = $origin;

        return $clone;
    }

    /** Returns the source fact; revision identity/ordinal are assigned by the writer. */
    public function persistWith(SalesFactRepository $repository): SalesFact
    {
        return $this->fact->persistWith($repository, $this->origin);
    }
}
