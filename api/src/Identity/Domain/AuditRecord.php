<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Запись аудит-журнала (ADR-007, CLAUDE.md «Безопасность и аудит»):
 * изменение себестоимости, изменение планов, добавление и изменение
 * учётных данных подключений, вход администратора в данные клиента.
 *
 * Никогда не изменяется и не удаляется — только добавляется. Журнал,
 * который можно поправить, журналом не является.
 *
 * Содержимого изменения здесь нет намеренно. Для учётных данных оно
 * означало бы хранить рядом с записью либо сам ключ, либо его остаток,
 * — а ценность аудита в том, кто и когда действие совершил, а не в том,
 * что именно было в секрете. Понадобится сравнивать «до и после»
 * по неcекретным полям — добавится колонка, это дёшево.
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_record')]
#[ORM\Index(name: 'idx_audit_record_company_occurred', columns: ['company_id', 'occurred_at'])]
class AuditRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    /**
     * Кто совершил действие. Не nullable: у каждого записываемого сюда
     * события есть человек — системные задачи в журнал не пишут,
     * их след это логи и raw-слой.
     */
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $actorUserId;

    #[ORM\Column(length: 64)]
    private readonly string $action;

    /**
     * Над чем действие совершено — идентификатор подключения, плана,
     * позиции себестоимости. Тип объекта отдельной колонкой не хранится:
     * он однозначно следует из action, а две колонки, которые обязаны
     * согласовываться, рано или поздно разойдутся.
     */
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $subjectId;

    #[ORM\Column]
    private readonly \DateTimeImmutable $occurredAt;

    private function __construct(
        Uuid $id,
        Uuid $companyId,
        Uuid $actorUserId,
        string $action,
        Uuid $subjectId,
        \DateTimeImmutable $occurredAt,
    ) {
        $this->id = $id;
        $this->companyId = $companyId;
        $this->actorUserId = $actorUserId;
        $this->action = $action;
        $this->subjectId = $subjectId;
        $this->occurredAt = $occurredAt;
    }

    public static function record(
        Uuid $companyId,
        Uuid $actorUserId,
        string $action,
        Uuid $subjectId,
        \DateTimeImmutable $occurredAt,
    ): self {
        return new self(Uuid::v7(), $companyId, $actorUserId, $action, $subjectId, $occurredAt);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function actorUserId(): Uuid
    {
        return $this->actorUserId;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function subjectId(): Uuid
    {
        return $this->subjectId;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
