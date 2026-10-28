<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

final readonly class NetTrainingRequest
{
    /** @var list<string> */
    public array $marketplaceSkus;

    /** @param array<mixed> $marketplaceSkus */
    public function __construct(array $marketplaceSkus)
    {
        if (!array_is_list($marketplaceSkus) || [] === $marketplaceSkus || \count($marketplaceSkus) > 50) {
            throw new \InvalidArgumentException('Training requires 1..50 distinct SKU values.');
        }
        foreach ($marketplaceSkus as $sku) {
            if (!\is_string($sku) || '' === trim($sku) || 1 !== preg_match('//u', $sku) || mb_strlen($sku) > 64 || 1 === preg_match('/[\x00-\x1F\x7F]/u', $sku)) {
                throw new \InvalidArgumentException('Invalid training SKU.');
            }
        }
        if (\count(array_unique($marketplaceSkus)) !== \count($marketplaceSkus)) {
            throw new \InvalidArgumentException('Training requires distinct SKU values.');
        }
        $this->marketplaceSkus = $marketplaceSkus;
    }
}
