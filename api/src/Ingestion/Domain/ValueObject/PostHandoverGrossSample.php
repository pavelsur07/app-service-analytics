<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

/** Conditional post-handover population from a complete original terminal sample. */
final readonly class PostHandoverGrossSample
{
    public int $postHandoverQuantity;

    public function __construct(
        public PreHandoverGrossSample $source,
        public int $postHandoverPostings,
    ) {
        $this->postHandoverQuantity = $source->sampleQuantity - $source->t1Quantity;
        if ($postHandoverPostings < 0 || $postHandoverPostings > $source->samplePostings
            || $postHandoverPostings > $this->postHandoverQuantity
            || $source->samplePostings - $postHandoverPostings > $source->t1Quantity
            || (0 === $postHandoverPostings && 0 !== $this->postHandoverQuantity)) {
            throw new \InvalidArgumentException('Invalid post-handover gross sample.');
        }
    }

    public function probability(): ?GrossProbability
    {
        if ($this->postHandoverQuantity < 100 || $this->postHandoverPostings < 30) {
            return null;
        }

        return new GrossProbability($this->source->grossQuantity, $this->postHandoverQuantity);
    }
}
