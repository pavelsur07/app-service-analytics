<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Разобранная страница возвратов и курсор следующей страницы.
 */
final readonly class OzonReturnsPage
{
    /**
     * @param list<MarketplaceReturnFact> $facts
     */
    public function __construct(
        public array $facts,
        public bool $hasNext,
        public ?int $lastId,
    ) {
    }
}
