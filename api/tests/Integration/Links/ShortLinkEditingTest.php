<?php

declare(strict_types=1);

namespace App\Tests\Integration\Links;

use App\Identity\Application\Facade\IdentityAdminFacade;
use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use App\Identity\Infrastructure\Repository\DoctrineAuditRecordRepository;
use App\Links\Application\ChangeShortLinkStatusAction;
use App\Links\Application\ShortLinkMutationOutcome;
use App\Links\Application\UpdateShortLinkAction;
use App\Links\Domain\ShortLinkStatus;
use App\Links\Infrastructure\Persistence\DoctrineShortLinkRepository;
use App\Tests\Support\Builder\AdministratorBuilder;
use App\Tests\Support\Builder\ShortLinkBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ShortLinkEditingTest extends KernelTestCase
{
    public function testDetailsChangeIncrementsVersionAndRecordsBeforeAndAfter(): void
    {
        self::bootKernel();
        [$links, $identity, $adminId] = $this->dependencies();
        $link = ShortLinkBuilder::aShortLink()
            ->withName('Old name')
            ->withTargetUrl('https://example.com/old')
            ->withCreatedByAdminId(Uuid::fromString($adminId))
            ->persistWith($links);
        $update = new UpdateShortLinkAction($links, $identity);

        $result = $update(
            $link->id()->toRfc4122(),
            'New name',
            'https://example.com/new',
            1,
            $adminId,
        );

        self::assertSame(ShortLinkMutationOutcome::Saved, $result->outcome);
        self::assertSame(2, $result->link?->version());
        $audit = $this->auditRows($link->id()->toRfc4122());
        self::assertCount(1, $audit);
        self::assertSame('short_link.details_changed', $audit[0]['action']);
        self::assertSame('{"name":"Old name","targetUrl":"https://example.com/old"}', $audit[0]['previous_value']);
        self::assertSame('{"name":"New name","targetUrl":"https://example.com/new"}', $audit[0]['new_value']);
        self::assertSame($adminId, $audit[0]['actor_admin_id']);
    }

    public function testStaleDetailsChangeIsRefusedWithoutAudit(): void
    {
        self::bootKernel();
        [$links, $identity, $adminId] = $this->dependencies();
        $link = ShortLinkBuilder::aShortLink()
            ->withCreatedByAdminId(Uuid::fromString($adminId))
            ->persistWith($links);
        $update = new UpdateShortLinkAction($links, $identity);

        self::assertSame(
            ShortLinkMutationOutcome::Saved,
            $update($link->id()->toRfc4122(), 'First', 'https://example.com/first', 1, $adminId)->outcome,
        );
        self::assertSame(
            ShortLinkMutationOutcome::VersionConflict,
            $update($link->id()->toRfc4122(), 'Stale', 'https://example.com/stale', 1, $adminId)->outcome,
        );

        self::assertCount(1, $this->auditRows($link->id()->toRfc4122()));
        self::assertSame('First', $links->get($link->id())?->name());
    }

    public function testSameDetailsAreANoOpWithoutVersionOrAudit(): void
    {
        self::bootKernel();
        [$links, $identity, $adminId] = $this->dependencies();
        $link = ShortLinkBuilder::aShortLink()
            ->withCreatedByAdminId(Uuid::fromString($adminId))
            ->persistWith($links);
        $update = new UpdateShortLinkAction($links, $identity);

        $result = $update(
            $link->id()->toRfc4122(),
            $link->name(),
            $link->targetUrl(),
            1,
            $adminId,
        );

        self::assertSame(ShortLinkMutationOutcome::Unchanged, $result->outcome);
        self::assertSame(1, $result->link?->version());
        self::assertSame([], $this->auditRows($link->id()->toRfc4122()));
    }

    public function testDisableAndEnableEachRecordOneStatusAudit(): void
    {
        self::bootKernel();
        [$links, $identity, $adminId] = $this->dependencies();
        $link = ShortLinkBuilder::aShortLink()
            ->withCreatedByAdminId(Uuid::fromString($adminId))
            ->persistWith($links);
        $changeStatus = new ChangeShortLinkStatusAction($links, $identity);

        $disabled = $changeStatus($link->id()->toRfc4122(), ShortLinkStatus::Disabled, 1, $adminId);
        $enabled = $changeStatus($link->id()->toRfc4122(), ShortLinkStatus::Active, 2, $adminId);

        self::assertSame(ShortLinkMutationOutcome::Saved, $disabled->outcome);
        self::assertSame(ShortLinkMutationOutcome::Saved, $enabled->outcome);
        self::assertSame(3, $enabled->link?->version());
        $audit = $this->auditRows($link->id()->toRfc4122());
        self::assertSame(['short_link.disabled', 'short_link.activated'], array_column($audit, 'action'));
        self::assertSame(['active', 'disabled'], array_column($audit, 'previous_value'));
        self::assertSame(['disabled', 'active'], array_column($audit, 'new_value'));
    }

    public function testUnknownLinkReturnsNotFound(): void
    {
        self::bootKernel();
        [$links, $identity, $adminId] = $this->dependencies();
        $update = new UpdateShortLinkAction($links, $identity);

        $result = $update(
            Uuid::v7()->toRfc4122(),
            'Missing',
            'https://example.com/missing',
            1,
            $adminId,
        );

        self::assertSame(ShortLinkMutationOutcome::NotFound, $result->outcome);
        self::assertNull($result->link);
    }

    /**
     * @return array{DoctrineShortLinkRepository, IdentityAdminFacade, string}
     */
    private function dependencies(): array
    {
        $entityManager = $this->entityManager();
        $administrators = new DoctrineAdministratorRepository($entityManager);
        $administrator = AdministratorBuilder::anAdministrator()->persistWith($administrators);
        $identity = new IdentityAdminFacade(
            $administrators,
            new DoctrineAuditRecordRepository($entityManager),
        );

        self::assertSame($administrator->id()->toRfc4122(), $identity->administratorId($administrator->email()));

        return [
            new DoctrineShortLinkRepository($entityManager),
            $identity,
            $administrator->id()->toRfc4122(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditRows(string $subjectId): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT action, previous_value, new_value, actor_admin_id FROM audit_record WHERE subject_id = ? ORDER BY id',
            [$subjectId],
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }
}
