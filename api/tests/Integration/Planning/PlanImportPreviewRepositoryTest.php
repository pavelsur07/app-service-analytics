<?php

declare(strict_types=1);

namespace App\Tests\Integration\Planning;

use App\Planning\Infrastructure\Repository\DoctrinePlanImportPreviewRepository;
use App\Tests\Support\Builder\PlanImportPreviewBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PlanImportPreviewRepositoryTest extends KernelTestCase
{
    public function testPreviewIsReadOnlyInsideItsCompanyAndAccount(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $repository = new DoctrinePlanImportPreviewRepository($entityManager);
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $preview = PlanImportPreviewBuilder::aPlanImportPreview()
            ->withCompanyId($companyId)->withMarketplaceAccountId($accountId)->build();
        $repository->add($preview);
        $id = $preview->id()->toRfc4122();
        $entityManager->clear();

        $loaded = $repository->get($companyId->toRfc4122(), $accountId->toRfc4122(), $id);

        self::assertNotNull($loaded);
        self::assertSame('SKU-1', $loaded->rows()[0]->marketplaceSku);
        self::assertNull($repository->get(Uuid::v7()->toRfc4122(), $accountId->toRfc4122(), $id));
        self::assertNull($repository->get($companyId->toRfc4122(), Uuid::v7()->toRfc4122(), $id));
    }
}
