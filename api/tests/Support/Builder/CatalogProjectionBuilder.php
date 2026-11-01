<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\ProductResearchOzon\Domain\CatalogProjection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class CatalogProjectionBuilder
{
    private Uuid $company;
    private Uuid $source;
    private int $revision = 1;
    private ?\DateTimeImmutable $generatedAt;

    private function __construct()
    {
        $this->company = Uuid::v7();
        $this->source = Uuid::v7();
        $this->generatedAt = new \DateTimeImmutable('2026-04-15T09:00:00Z');
    }

    public static function aCatalog(): self
    {
        return new self();
    }

    public function withCompanyId(Uuid $company): self
    {
        $copy = clone $this;
        $copy->company = $company;

        return $copy;
    }

    public function withSourceId(Uuid $source): self
    {
        $copy = clone $this;
        $copy->source = $source;

        return $copy;
    }

    public function withGeneration(int $revision, ?\DateTimeImmutable $at): self
    {
        $copy = clone $this;
        $copy->revision = $revision;
        $copy->generatedAt = $at;

        return $copy;
    }

    public function build(): CatalogProjection
    {
        return new CatalogProjection($this->company, $this->source, $this->revision, $this->generatedAt);
    }

    public function persistWith(EntityManagerInterface $em): CatalogProjection
    {
        $catalog = $this->build();
        $em->persist($catalog);
        $em->flush();

        return $catalog;
    }
}
