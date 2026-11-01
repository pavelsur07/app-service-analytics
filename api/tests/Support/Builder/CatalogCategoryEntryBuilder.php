<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\ProductResearchOzon\Domain\CatalogCategoryEntry;
use App\ProductResearchOzon\Domain\CatalogProjection;
use Doctrine\ORM\EntityManagerInterface;

final class CatalogCategoryEntryBuilder
{
    private CatalogProjection $catalog;
    private string $path = 'Аксессуары/Хиджабы и тюрбаны женские';

    private function __construct()
    {
        $this->catalog = CatalogProjectionBuilder::aCatalog()->build();
    }

    public static function aCategory(): self
    {
        return new self();
    }

    public function inCatalog(CatalogProjection $catalog): self
    {
        $copy = clone $this;
        $copy->catalog = $catalog;

        return $copy;
    }

    public function withPath(string $path): self
    {
        $copy = clone $this;
        $copy->path = $path;

        return $copy;
    }

    public function build(): CatalogCategoryEntry
    {
        return new CatalogCategoryEntry($this->catalog, $this->path);
    }

    public function persistWith(EntityManagerInterface $em): CatalogCategoryEntry
    {
        $entry = $this->build();
        $em->persist($this->catalog);
        $em->persist($entry);
        $em->flush();

        return $entry;
    }
}
