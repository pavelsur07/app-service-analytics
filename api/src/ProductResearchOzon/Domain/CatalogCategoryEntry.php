<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

use App\ProductResearchOzon\Domain\ValueObject\CatalogCategory;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'product_research_ozon_category')]
#[ORM\UniqueConstraint(name: 'uq_research_category_path', columns: ['company_id', 'source_connection_id', 'path_key'])]
class CatalogCategoryEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $id;
    #[ORM\ManyToOne(targetEntity: CatalogProjection::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'company_id', nullable: false)]
    #[ORM\JoinColumn(name: 'source_connection_id', referencedColumnName: 'source_connection_id', nullable: false)]
    private readonly CatalogProjection $catalog;
    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private readonly string $pathKey;
    #[ORM\Column(length: 2048)]
    private readonly string $path;

    public function __construct(CatalogProjection $catalog, string $path)
    {
        $category = new CatalogCategory($path);
        $this->id = Uuid::v7();
        $this->catalog = $catalog;
        $this->pathKey = $category->key;
        $this->path = $category->path;
    }
}
