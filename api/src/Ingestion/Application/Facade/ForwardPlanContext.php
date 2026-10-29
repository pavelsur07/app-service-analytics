<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

/** SalesPlanning-owned visible values. No cross-module query or Entity crosses this boundary. */
final readonly class ForwardPlanContext
{
    /** @param non-empty-list<string> $marketplaceSkus
     * @param list<array<string, mixed>> $goalRows */
    public function __construct(public string $planId, public int $planVersion, public int $versionNumber, public string $baselineId, public int $baselineVersion, public string $metric, public string $currency, public string $accumulationFrom, public array $marketplaceSkus, public array $goalRows)
    {
        if ([] === $marketplaceSkus || \count($marketplaceSkus) > 50 || \count($goalRows) !== \count($marketplaceSkus) || !\in_array($metric, ['retained_sales', 'orders_excluding_pre_handover_cancellations'], true)) {
            throw new \InvalidArgumentException('Invalid bounded plan capture context.');
        }
    }
}
