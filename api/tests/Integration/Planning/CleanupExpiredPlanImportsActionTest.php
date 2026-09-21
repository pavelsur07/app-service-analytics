<?php

declare(strict_types=1);

namespace App\Tests\Integration\Planning;

use App\Planning\Application\CleanupExpiredPlanImportsAcrossCompaniesAction;
use App\Planning\Infrastructure\Repository\DoctrinePlanImportPreviewRepository;
use App\Tests\Support\Builder\PlanImportPreviewBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CleanupExpiredPlanImportsActionTest extends KernelTestCase
{
    public function testDeletesExpiredReadyAndOldAppliedButKeepsRecentAppliedResult(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $repository = new DoctrinePlanImportPreviewRepository($entityManager);
        $now = new \DateTimeImmutable('2026-09-21 12:00:00 UTC');
        $expired = PlanImportPreviewBuilder::aPlanImportPreview()->withCreatedAt($now->modify('-25 hours'))->build();
        $oldApplied = PlanImportPreviewBuilder::aPlanImportPreview()->withCreatedAt($now->modify('-40 days'))->build();
        $oldApplied->markApplied(['created' => 1, 'updated' => 0, 'unchanged' => 0], $now->modify('-35 days'));
        $recentApplied = PlanImportPreviewBuilder::aPlanImportPreview()->withCreatedAt($now->modify('-2 days'))->build();
        $recentApplied->markApplied(['created' => 1, 'updated' => 0, 'unchanged' => 0], $now->modify('-1 day'));
        foreach ([$expired, $oldApplied, $recentApplied] as $preview) {
            $repository->add($preview);
        }

        /** @var CleanupExpiredPlanImportsAcrossCompaniesAction $cleanup */
        $cleanup = self::getContainer()->get(CleanupExpiredPlanImportsAcrossCompaniesAction::class);
        self::assertSame(2, $cleanup($now));

        $remaining = $entityManager->getConnection()->fetchFirstColumn('SELECT id::text FROM planning_import_preview');
        self::assertSame([$recentApplied->id()->toRfc4122()], $remaining);
    }
}
