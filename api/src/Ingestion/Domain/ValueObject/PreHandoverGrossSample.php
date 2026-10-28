<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

/** Complete mature terminal training: gross already includes delivered and returned units. */
final readonly class PreHandoverGrossSample
{
    public function __construct(
        public int $sampleQuantity,
        public int $samplePostings,
        public int $grossQuantity,
        public int $t1Quantity,
        public int $t2Quantity,
        public int $pQuantity,
    ) {
        if ($sampleQuantity < 0 || $samplePostings < 0 || $samplePostings > $sampleQuantity
            || (0 === $samplePostings && 0 !== $sampleQuantity)) {
            throw new \InvalidArgumentException('Invalid gross training sample.');
        }
        $remaining = $sampleQuantity;
        foreach ([$grossQuantity, $t1Quantity, $t2Quantity, $pQuantity] as $quantity) {
            if ($quantity < 0 || $quantity > $remaining) {
                throw new \InvalidArgumentException('Inconsistent gross training counts.');
            }
            $remaining -= $quantity;
        }
        if (0 !== $remaining) {
            throw new \InvalidArgumentException('Incomplete gross training counts.');
        }
    }

    public function probability(): ?GrossProbability
    {
        if ($this->sampleQuantity < 100 || $this->samplePostings < 30) {
            return null;
        }

        return new GrossProbability($this->grossQuantity, $this->sampleQuantity);
    }
}
