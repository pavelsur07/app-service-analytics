<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

final readonly class ReturnCurvePoint
{
    public function __construct(
        public int $age,
        public int $returnedQuantity,
        public int $atRiskQuantity,
        public int $atRiskPostingsLowerBound,
    ) {
        if ($age < 0 || $age > 60 || $returnedQuantity < 0 || $atRiskQuantity < 0 || $atRiskPostingsLowerBound < 0) {
            throw new \InvalidArgumentException('Invalid return curve point.');
        }
    }
}
