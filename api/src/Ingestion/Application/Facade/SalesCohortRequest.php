<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

use App\Shared\Domain\ValueObject\Money;

final readonly class SalesCohortRequest
{
    /** @param list<string> $marketplaceSkus */
    public function __construct(
        public array $marketplaceSkus,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public \DateTimeImmutable $accumulationFrom,
        public SalesCohortGrouping $groupBy = SalesCohortGrouping::Day,
        public ?string $valuationCurrency = null,
    ) {
        if (!array_is_list($marketplaceSkus) || \count($marketplaceSkus) > 50) {
            throw new \InvalidArgumentException('A cohort batch accepts at most 50 SKUs.');
        }
        foreach ($marketplaceSkus as $sku) {
            if ('' === trim($sku) || \strlen($sku) > 64) {
                throw new \InvalidArgumentException('A marketplace SKU must contain between 1 and 64 characters.');
            }
        }
        $utc = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable($accumulationFrom->format('Y-m-d'), $utc);
        $visible = new \DateTimeImmutable($from->format('Y-m-d'), $utc);
        $end = new \DateTimeImmutable($to->format('Y-m-d'), $utc);
        if ($start > $visible || $visible > $end || $start->diff($end)->days >= 184) {
            throw new \InvalidArgumentException('A cohort range must contain between 1 and 184 calendar days.');
        }
        if (null !== $valuationCurrency) {
            try {
                Money::ofMinor(0, $valuationCurrency);
            } catch (\Exception $invalid) {
                throw new \InvalidArgumentException('Unknown valuation currency.', previous: $invalid);
            }
        }
    }
}
