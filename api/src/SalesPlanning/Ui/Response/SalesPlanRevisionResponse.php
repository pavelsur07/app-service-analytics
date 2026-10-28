<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

final readonly class SalesPlanRevisionResponse
{
    public function __construct(public string $id, public int $version, public int $versionNumber)
    {
    }
}
