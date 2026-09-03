<?php

declare(strict_types=1);

namespace App\Identity\Application\Facade;

use App\Identity\Domain\AdministratorRepository;
use App\Identity\Domain\AuditRecord;
use App\Identity\Domain\AuditRecordRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Единственная граница Links → Identity для системного администратора.
 */
final readonly class IdentityAdminFacade
{
    public function __construct(
        private AdministratorRepository $administrators,
        private AuditRecordRepository $auditRecords,
    ) {
    }

    public function administratorId(string $identifier): string
    {
        $administrator = $this->administrators->findByEmail($identifier);
        if (null === $administrator) {
            throw new \LogicException('Authenticated administrator is no longer available.');
        }

        return $administrator->id()->toRfc4122();
    }

    public function recordAuditEntry(
        string $actorAdminId,
        string $action,
        string $subjectId,
        ?string $previousValue,
        ?string $newValue,
        \DateTimeImmutable $occurredAt,
    ): void {
        $this->auditRecords->addToUnitOfWork(AuditRecord::recordByAdmin(
            companyId: null,
            actorAdminId: Uuid::fromString($actorAdminId),
            action: $action,
            subjectId: Uuid::fromString($subjectId),
            previousValue: $previousValue,
            newValue: $newValue,
            occurredAt: $occurredAt,
        ));
    }
}
