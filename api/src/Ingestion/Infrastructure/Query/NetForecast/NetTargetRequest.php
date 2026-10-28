<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

final readonly class NetTargetRequest
{
    public \DateTimeImmutable $from;
    public \DateTimeImmutable $to;
    public ?\DateTimeImmutable $accumulationFrom;

    /** @param list<string> $marketplaceSkus */
    public function __construct(public array $marketplaceSkus, \DateTimeImmutable $from, \DateTimeImmutable $to, ?\DateTimeImmutable $accumulationFrom = null)
    {
        if (!array_is_list($marketplaceSkus) || \count($marketplaceSkus) > 50) {
            throw new \InvalidArgumentException('Target requires at most 50 distinct SKU.');
        }
        foreach ($marketplaceSkus as $sku) {
            if (!\is_string($sku) || 1 !== preg_match('/^[^\p{C}]{1,64}$/uD', $sku)) {
                throw new \InvalidArgumentException('Invalid target SKU.');
            }
        }
        if (\count(array_unique($marketplaceSkus)) !== \count($marketplaceSkus)) {
            throw new \InvalidArgumentException('Duplicate target SKU.');
        }
        $utc = new \DateTimeZone('UTC');
        $this->from = new \DateTimeImmutable($from->format('Y-m-d'), $utc);
        $this->to = new \DateTimeImmutable($to->format('Y-m-d'), $utc);
        $this->accumulationFrom = null === $accumulationFrom ? null : new \DateTimeImmutable($accumulationFrom->format('Y-m-d'), $utc);
        $start = $this->accumulationFrom ?? $this->from;
        if ($start > $this->from || $this->from > $this->to || (int) $start->diff($this->to)->format('%a') >= 184) {
            throw new \InvalidArgumentException('Target calendar range must contain 1..184 days including accumulation.');
        }
    }
}
