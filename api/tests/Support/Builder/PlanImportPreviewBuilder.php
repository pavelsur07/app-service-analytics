<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Planning\Domain\PlanImportPreview;
use App\Planning\Domain\PlanImportPreviewRow;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class PlanImportPreviewBuilder
{
    private Uuid $companyId;
    private Uuid $marketplaceAccountId;
    private Uuid $actorId;
    private string $fingerprint;
    /** @var list<PlanImportPreviewRow> */
    private array $rows;
    private \DateTimeImmutable $createdAt;
    /** @var array{created: int, updated: int, unchanged: int}|null */
    private ?array $applyResult = null;
    private ?\DateTimeImmutable $appliedAt = null;

    private function __construct()
    {
        $this->companyId = Uuid::v7();
        $this->marketplaceAccountId = Uuid::v7();
        $this->actorId = Uuid::v7();
        $this->fingerprint = hash('sha256', 'planning-preview');
        $this->rows = [new PlanImportPreviewRow(2, 'SKU-1', 'offer-1', '2026-09-22', 12, 0, null, 'new')];
        $this->createdAt = new \DateTimeImmutable('2026-09-21 12:00:00 UTC');
    }

    public static function aPlanImportPreview(): self
    {
        return new self();
    }

    public function withCompanyId(Uuid $value): self
    {
        $clone = clone $this;
        $clone->companyId = $value;

        return $clone;
    }

    public function withMarketplaceAccountId(Uuid $value): self
    {
        $clone = clone $this;
        $clone->marketplaceAccountId = $value;

        return $clone;
    }

    public function withActorId(Uuid $value): self
    {
        $clone = clone $this;
        $clone->actorId = $value;

        return $clone;
    }

    public function withFingerprint(string $value): self
    {
        $clone = clone $this;
        $clone->fingerprint = $value;

        return $clone;
    }

    /** @param list<PlanImportPreviewRow> $value */
    public function withRows(array $value): self
    {
        $clone = clone $this;
        $clone->rows = $value;

        return $clone;
    }

    public function withCreatedAt(\DateTimeImmutable $value): self
    {
        $clone = clone $this;
        $clone->createdAt = $value;

        return $clone;
    }

    /** @param array{created: int, updated: int, unchanged: int} $result */
    public function asApplied(array $result, \DateTimeImmutable $appliedAt): self
    {
        $clone = clone $this;
        $clone->applyResult = $result;
        $clone->appliedAt = $appliedAt;

        return $clone;
    }

    public function build(): PlanImportPreview
    {
        $preview = PlanImportPreview::create($this->companyId, $this->marketplaceAccountId, $this->actorId, $this->fingerprint, $this->rows, $this->createdAt);
        if (null !== $this->applyResult && null !== $this->appliedAt) {
            $preview->markApplied($this->applyResult, $this->appliedAt);
        }

        return $preview;
    }

    public function persistWith(EntityManagerInterface $entityManager): PlanImportPreview
    {
        $preview = $this->build();
        $entityManager->persist($preview);
        $entityManager->flush();

        return $preview;
    }
}
