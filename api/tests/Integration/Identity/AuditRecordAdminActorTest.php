<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\AuditAction;
use App\Identity\Domain\AuditRecordRepository;
use App\Tests\Support\Builder\AuditRecordBuilder;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Второй актор журнала (ADR-017): администратор системного контура.
 *
 * Проверяется не «колонка добавилась», а инвариант строки — ровно один
 * актор — и то, что он держится базой. Проверку в коде можно обойти,
 * CHECK нельзя, и именно это здесь и утверждается.
 */
final class AuditRecordAdminActorTest extends KernelTestCase
{
    public function testAdministratorEventIsStoredWithoutCompany(): void
    {
        self::bootKernel();
        $entityManager = $this->entityManager();
        $auditRecords = $this->auditRecords();

        $adminId = Uuid::v7();
        $newAdminId = Uuid::v7();

        $record = AuditRecordBuilder::anAuditRecord()
            ->withoutCompany()
            ->withActorAdminId($adminId)
            ->withAction(AuditAction::AdministratorCreated)
            ->withSubjectId($newAdminId)
            ->withChange(null, 'ops@conwix.local')
            ->persistWith($auditRecords);
        $entityManager->flush();

        $stored = $entityManager->getConnection()->fetchAssociative(
            'SELECT company_id, actor_user_id, actor_admin_id, action FROM audit_record WHERE id = :id',
            ['id' => (string) $record->id()],
        );

        self::assertIsArray($stored);
        self::assertNull($stored['company_id'], 'у события системного контура компании нет');
        self::assertNull($stored['actor_user_id']);
        self::assertSame((string) $adminId, $stored['actor_admin_id']);
        self::assertSame(AuditAction::AdministratorCreated, $stored['action']);
    }

    public function testSellerEventStillStoresCompanyAndSellerActor(): void
    {
        self::bootKernel();
        $entityManager = $this->entityManager();
        $auditRecords = $this->auditRecords();

        $companyId = Uuid::v7();
        $actorUserId = Uuid::v7();

        $record = AuditRecordBuilder::anAuditRecord()
            ->withCompanyId($companyId)
            ->withActorUserId($actorUserId)
            ->withChange('old-fingerprint', 'new-fingerprint')
            ->persistWith($auditRecords);
        $entityManager->flush();

        $stored = $entityManager->getConnection()->fetchAssociative(
            'SELECT company_id, actor_user_id, actor_admin_id FROM audit_record WHERE id = :id',
            ['id' => (string) $record->id()],
        );

        self::assertIsArray($stored);
        self::assertSame((string) $companyId, $stored['company_id']);
        self::assertSame((string) $actorUserId, $stored['actor_user_id']);
        self::assertNull($stored['actor_admin_id'], 'у события продавца администратора нет');
    }

    public function testRowWithTwoActorsIsRejectedByTheDatabase(): void
    {
        self::bootKernel();

        $this->expectException(DriverException::class);

        // Через доменный конструктор такую строку не собрать — поэтому
        // вставка сырая: проверяется гарантия базы, а не дисциплина
        // вызывающего.
        $this->insertRaw(actorUserId: (string) Uuid::v7(), actorAdminId: (string) Uuid::v7());
    }

    public function testRowWithNoActorIsRejectedByTheDatabase(): void
    {
        self::bootKernel();

        $this->expectException(DriverException::class);

        // Более вероятная ошибка, чем два актора: актора просто забыли.
        $this->insertRaw(actorUserId: null, actorAdminId: null);
    }

    private function insertRaw(?string $actorUserId, ?string $actorAdminId): void
    {
        $this->entityManager()->getConnection()->insert('audit_record', [
            'id' => (string) Uuid::v7(),
            'company_id' => (string) Uuid::v7(),
            'actor_user_id' => $actorUserId,
            'actor_admin_id' => $actorAdminId,
            'action' => AuditAction::CompanyBlocked,
            'subject_id' => (string) Uuid::v7(),
            'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function auditRecords(): AuditRecordRepository
    {
        /** @var AuditRecordRepository $auditRecords */
        $auditRecords = self::getContainer()->get(AuditRecordRepository::class);

        return $auditRecords;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
