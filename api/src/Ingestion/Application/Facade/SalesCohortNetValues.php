<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

use App\Shared\Domain\ValueObject\Money;

/** Full exact scope result rounded once at the module boundary. */
final readonly class SalesCohortNetValues
{
    public function __construct(
        public ?string $quantity,
        public ?Money $revenue,
        public ?string $reason,
        public ?string $moneyReason,
        public SalesCohortNetQuality $quality,
    ) {
    }
}
