<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Identity\Domain\AuditAction;
use App\Identity\Domain\AuditRecord;
use App\Identity\Domain\AuditRecordRepository;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-005: валидные умолчания, неизменяем, разделяет build()
 * и persistWith().
 *
 * «Было» и «стало» задаются снаружи: именно они и проверяются в тестах
 * журнала, а билдер не вычисляет проверяемое значение сам.
 *
 * Актор один из двух (ADR-017). Умолчание — продавец, каким журнал был
 * до системного контура; withActorAdminId() переключает на другой
 * контур и гасит продавца, чтобы билдер не мог собрать строку,
 * которую база отвергнет.
 */
final class AuditRecordBuilder
{
    private ?Uuid $companyId;
    private ?Uuid $actorUserId;
    private ?Uuid $actorAdminId = null;
    private string $action = AuditAction::MarketplaceCredentialsReplaced;
    private Uuid $subjectId;
    private ?string $previousValue = null;
    private ?string $newValue = null;
    private \DateTimeImmutable $occurredAt;

    private function __construct()
    {
        $this->companyId = Uuid::v7();
        $this->actorUserId = Uuid::v7();
        $this->subjectId = Uuid::v7();
        $this->occurredAt = new \DateTimeImmutable('2026-08-14 10:00:00');
    }

    public static function anAuditRecord(): self
    {
        return new self();
    }

    public function withCompanyId(Uuid $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    /** Событие системного контура: «заведён Admin» ничьей компании не касается. */
    public function withoutCompany(): self
    {
        $clone = clone $this;
        $clone->companyId = null;

        return $clone;
    }

    public function withActorUserId(Uuid $actorUserId): self
    {
        $clone = clone $this;
        $clone->actorUserId = $actorUserId;
        $clone->actorAdminId = null;

        return $clone;
    }

    public function withActorAdminId(Uuid $actorAdminId): self
    {
        $clone = clone $this;
        $clone->actorAdminId = $actorAdminId;
        $clone->actorUserId = null;

        return $clone;
    }

    public function withAction(string $action): self
    {
        $clone = clone $this;
        $clone->action = $action;

        return $clone;
    }

    public function withSubjectId(Uuid $subjectId): self
    {
        $clone = clone $this;
        $clone->subjectId = $subjectId;

        return $clone;
    }

    public function withChange(?string $previousValue, ?string $newValue): self
    {
        $clone = clone $this;
        $clone->previousValue = $previousValue;
        $clone->newValue = $newValue;

        return $clone;
    }

    public function withOccurredAt(\DateTimeImmutable $occurredAt): self
    {
        $clone = clone $this;
        $clone->occurredAt = $occurredAt;

        return $clone;
    }

    public function build(): AuditRecord
    {
        if (null !== $this->actorAdminId) {
            return AuditRecord::recordByAdmin(
                companyId: $this->companyId,
                actorAdminId: $this->actorAdminId,
                action: $this->action,
                subjectId: $this->subjectId,
                previousValue: $this->previousValue,
                newValue: $this->newValue,
                occurredAt: $this->occurredAt,
            );
        }

        \assert(null !== $this->actorUserId && null !== $this->companyId);

        return AuditRecord::record(
            companyId: $this->companyId,
            actorUserId: $this->actorUserId,
            action: $this->action,
            subjectId: $this->subjectId,
            previousValue: $this->previousValue,
            newValue: $this->newValue,
            occurredAt: $this->occurredAt,
        );
    }

    public function persistWith(AuditRecordRepository $repository): AuditRecord
    {
        $record = $this->build();
        $repository->addToUnitOfWork($record);

        return $record;
    }
}
