<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

final readonly class SalesCohortSku
{
    /** @param list<SalesCohortPoint> $points */
    public function __construct(public string $marketplaceSku, public array $points)
    {
    }
}
