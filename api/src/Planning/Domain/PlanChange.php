<?php

declare(strict_types=1);

namespace App\Planning\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'planning_plan_change')]
#[ORM\Index(name: 'idx_planning_plan_change_key', columns: ['company_id', 'marketplace_account_id', 'marketplace_sku', 'business_date', 'changed_at'])]
#[ORM\Index(name: 'idx_planning_plan_change_actor', columns: ['company_id', 'actor_id'])]
class PlanChange
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $marketplaceAccountId;

    #[ORM\Column(length: 64)]
    private readonly string $marketplaceSku;

    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $businessDate;

    #[ORM\Column(nullable: true)]
    private readonly ?int $oldQuantity;

    #[ORM\Column(nullable: true)]
    private readonly ?int $newQuantity;

    #[ORM\Column]
    private readonly int $oldVersion;

    #[ORM\Column]
    private readonly int $newVersion;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $actorId;

    #[ORM\Column]
    private readonly \DateTimeImmutable $changedAt;

    private function __construct(
        DailyPlan $plan,
        ?int $oldQuantity,
        ?int $newQuantity,
        int $oldVersion,
        int $newVersion,
        Uuid $actorId,
        \DateTimeImmutable $changedAt,
    ) {
        $this->id = Uuid::v7();
        $this->companyId = $plan->companyId();
        $this->marketplaceAccountId = $plan->marketplaceAccountId();
        $this->marketplaceSku = $plan->marketplaceSku();
        $this->businessDate = $plan->businessDate();
        $this->oldQuantity = $oldQuantity;
        $this->newQuantity = $newQuantity;
        $this->oldVersion = $oldVersion;
        $this->newVersion = $newVersion;
        $this->actorId = $actorId;
        $this->changedAt = $changedAt;
    }

    public static function created(DailyPlan $plan): self
    {
        if (1 !== $plan->version() || null === $plan->quantity()) {
            throw new \InvalidArgumentException('Начальное состояние изменения плана некорректно.');
        }

        return new self($plan, null, $plan->quantity(), 0, 1, $plan->updatedBy(), $plan->updatedAt());
    }

    public static function changed(DailyPlan $plan, ?int $oldQuantity, int $oldVersion): self
    {
        if (null !== $oldQuantity && ($oldQuantity < 0 || $oldQuantity > DailyPlan::MAX_QUANTITY)) {
            throw new \InvalidArgumentException('Количество в изменении плана выходит за допустимый диапазон.');
        }
        if ($oldVersion < 1 || $oldVersion > DailyPlan::MAX_MUTABLE_VERSION || $plan->version() !== $oldVersion) {
            throw new \InvalidArgumentException('Версии изменения плана некорректны.');
        }
        if ($oldQuantity === $plan->quantity()) {
            throw new \InvalidArgumentException('Изменение плана не меняет количество.');
        }

        return new self(
            $plan,
            $oldQuantity,
            $plan->quantity(),
            $oldVersion,
            $oldVersion + 1,
            $plan->updatedBy(),
            $plan->updatedAt(),
        );
    }
}
