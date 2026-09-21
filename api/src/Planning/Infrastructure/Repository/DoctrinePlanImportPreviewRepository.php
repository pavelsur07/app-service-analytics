<?php

declare(strict_types=1);

namespace App\Planning\Infrastructure\Repository;

use App\Planning\Domain\PlanImportPreview;
use App\Planning\Domain\PlanImportPreviewRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePlanImportPreviewRepository implements PlanImportPreviewRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(PlanImportPreview $preview): void
    {
        $this->entityManager->persist($preview);
        $this->entityManager->flush();
    }

    public function get(string $companyId, string $marketplaceAccountId, string $previewId): ?PlanImportPreview
    {
        return $this->entityManager->getRepository(PlanImportPreview::class)->findOneBy([
            'id' => $previewId,
            'companyId' => $companyId,
            'marketplaceAccountId' => $marketplaceAccountId,
        ]);
    }
}
