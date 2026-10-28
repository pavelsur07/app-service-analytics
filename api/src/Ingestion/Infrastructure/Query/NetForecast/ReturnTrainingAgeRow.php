<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

final readonly class ReturnTrainingAgeRow
{
    public function __construct(
        public int $age,
        public int $returnedQuantity,
        public int $atRiskQuantity,
        public int $atRiskPostingsLowerBound,
    ) {
    }
}
