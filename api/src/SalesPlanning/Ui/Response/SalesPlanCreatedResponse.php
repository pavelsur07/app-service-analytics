<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

final readonly class SalesPlanCreatedResponse
{
    public function __construct(
        public string $id,
        public int $version,
    ) {
    }
}
