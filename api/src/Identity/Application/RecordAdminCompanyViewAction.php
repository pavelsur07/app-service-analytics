<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Administrator;
use App\Identity\Domain\AuditAction;
use App\Identity\Domain\AuditRecord;
use App\Identity\Domain\AuditRecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** Аудит входа администратора в данные клиента (CLAUDE.md, ADR-007, ADR-011). */
final readonly class RecordAdminCompanyViewAction
{
    public function __construct(private AuditRecordRepository $audit, private EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(string $companyId, Administrator $actor): void
    {
        $this->audit->addToUnitOfWork(AuditRecord::recordByAdmin(
            companyId: Uuid::fromString($companyId), actorAdminId: $actor->id(),
            action: AuditAction::CompanyViewed, subjectId: Uuid::fromString($companyId),
            previousValue: null, newValue: null, occurredAt: new \DateTimeImmutable(),
        ));
        $this->entityManager->flush();
    }
}
