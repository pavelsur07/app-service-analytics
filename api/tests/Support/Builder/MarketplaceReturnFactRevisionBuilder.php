<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\FactCaptureOrigin;
use App\Ingestion\Domain\MarketplaceReturnFact;
use App\Ingestion\Domain\MarketplaceReturnFactRepository;

/** MarketplaceReturnFactRevision fixture: history is captured by the real writer. */
final class MarketplaceReturnFactRevisionBuilder
{
    private function __construct(
        private MarketplaceReturnFactBuilder $fact,
        private FactCaptureOrigin $origin = FactCaptureOrigin::Unknown,
    ) {
    }

    public static function aMarketplaceReturnFactRevision(): self
    {
        return new self(MarketplaceReturnFactBuilder::aMarketplaceReturnFact());
    }

    public function withFact(MarketplaceReturnFactBuilder $fact): self
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
    public function persistWith(MarketplaceReturnFactRepository $repository): MarketplaceReturnFact
    {
        return $this->fact->persistWith($repository, $this->origin);
    }
}
