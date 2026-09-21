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

    public function testReadyPreviewIsDeduplicatedAndBoundedPerActor(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $repository = new DoctrinePlanImportPreviewRepository($entityManager);
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $actorId = Uuid::v7();
        $now = new \DateTimeImmutable('2026-09-21 12:00:00 UTC');
        $first = null;
        for ($index = 0; $index < 11; ++$index) {
            $preview = PlanImportPreviewBuilder::aPlanImportPreview()->withCompanyId($companyId)
                ->withMarketplaceAccountId($accountId)->withActorId($actorId)
                ->withFingerprint(hash('sha256', 'preview-'.$index))->withCreatedAt($now->modify('+'.$index.' seconds'))->build();
            $stored = $repository->addOrGetReady($preview, $now);
            $first ??= $stored;
        }
        self::assertNotNull($first);
        $duplicate = PlanImportPreviewBuilder::aPlanImportPreview()->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)->withActorId($actorId)
            ->withFingerprint(hash('sha256', 'preview-10'))->withCreatedAt($now->modify('+1 minute'))->build();

        self::assertNull($repository->get($companyId->toRfc4122(), $accountId->toRfc4122(), $duplicate->id()->toRfc4122()));
        self::assertNotSame($duplicate->id()->toRfc4122(), $repository->addOrGetReady($duplicate, $now)->id()->toRfc4122());
        $count = $entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM planning_import_preview WHERE company_id = ? AND marketplace_account_id = ? AND actor_id = ? AND status = ?',
            [$companyId->toRfc4122(), $accountId->toRfc4122(), $actorId->toRfc4122(), 'ready'],
        );
        self::assertTrue(\is_int($count) || \is_string($count));
        self::assertSame(10, (int) $count);
        self::assertNull($repository->get($companyId->toRfc4122(), $accountId->toRfc4122(), $first->id()->toRfc4122()));
    }
}
