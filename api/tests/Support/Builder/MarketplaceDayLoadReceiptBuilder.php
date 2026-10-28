<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\FactCaptureOrigin;
use App\Ingestion\Domain\MarketplaceDayLoadRepository;

/** Schema-only receipt fixture, persisted through the actual load-mark writer. */
final class MarketplaceDayLoadReceiptBuilder
{
    private function __construct(
        private MarketplaceDayLoadBuilder $load,
        private FactCaptureOrigin $origin = FactCaptureOrigin::Unknown,
    ) {
    }

    public static function aMarketplaceDayLoadReceipt(): self
    {
        return new self(MarketplaceDayLoadBuilder::aMarketplaceDayLoad());
    }

    public function withLoad(MarketplaceDayLoadBuilder $load): self
    {
        $clone = clone $this;
        $clone->load = $load;

        return $clone;
    }

    public function withOrigin(FactCaptureOrigin $origin): self
    {
        $clone = clone $this;
        $clone->origin = $origin;

        return $clone;
    }

    public function persistWith(MarketplaceDayLoadRepository $repository): void
    {
        $this->load->persistWith($repository, $this->origin);
    }
}
