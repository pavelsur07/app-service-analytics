<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Response;

use App\ProductResearchOzon\Domain\ValueObject\CatalogSearchResult;
use OpenApi\Attributes as OA;

final readonly class ResearchCategoriesResponse
{
    /** @var list<ResearchCategoryResponse> */
    public array $items;
    public int $revision;
    #[OA\Property(format: 'date-time')]
    public string $generatedAt;
    #[OA\Property(format: 'date-time')]
    public string $expiresAt;

    public function __construct(CatalogSearchResult $result)
    {
        $this->items = array_map(static fn (string $path): ResearchCategoryResponse => new ResearchCategoryResponse($path), $result->paths);
        $this->revision = $result->metadata->revision;
        $this->generatedAt = $result->metadata->generatedAt->format('Y-m-d\TH:i:s.uP');
        $this->expiresAt = $result->metadata->expiresAt()->format('Y-m-d\TH:i:s.uP');
    }
}
