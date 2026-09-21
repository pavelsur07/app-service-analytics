<?php

declare(strict_types=1);

namespace App\Tests\Integration\Planning;

use App\Planning\Application\CleanupExpiredPlanImportsAcrossCompaniesAction;
use App\Planning\Infrastructure\Repository\DoctrinePlanImportPreviewRepository;
use App\Tests\Support\Builder\PlanImportPreviewBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

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

    public function testCommandRunsOneCleanupPassWhenIntervalIsOmitted(): void
    {
        self::bootKernel();
        self::assertNotNull(self::$kernel);
        $command = (new Application(self::$kernel))->find('app:planning:imports:cleanup');
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Очистка preview: удалено 0.', $tester->getDisplay());
    }

    public function testCommandRejectsNonPositiveLoopInterval(): void
    {
        self::bootKernel();
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:planning:imports:cleanup'));

        self::assertSame(Command::INVALID, $tester->execute(['--interval' => '0']));
    }
}
