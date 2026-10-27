<?php

declare(strict_types=1);

namespace App\SalesPlanning\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Сохраняемая отметка отдельной версии плана; границы сезона сюда не входят. */
#[ORM\Entity]
#[ORM\Table(name: 'sales_plan_calendar_entry')]
#[ORM\Index(name: 'idx_sales_plan_calendar_company_plan_date', columns: ['company_id', 'sales_plan_id', 'date_from', 'id'])]
#[ORM\Index(name: 'idx_sales_plan_calendar_company_plan_sku', columns: ['company_id', 'sales_plan_id', 'marketplace_sku'])]
class SalesPlanCalendarEntry
{
    public const int MaxEntries = 500;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $salesPlanId;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $marketplaceSku;

    #[ORM\Column(length: 32, enumType: SalesPlanCalendarKind::class)]
    private SalesPlanCalendarKind $kind;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateFrom;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateTo;

    private function __construct(SalesPlan $plan, PlannedCalendarEvent $event)
    {
        $plan->assertEditable();
        $event->assertWithin($plan);
        $this->id = Uuid::v7();
        $this->companyId = $plan->companyId();
        $this->salesPlanId = $plan->id();
        $this->assign($event);
    }

    public static function create(SalesPlan $plan, PlannedCalendarEvent $event): self
    {
        return new self($plan, $event);
    }

    public function change(SalesPlan $plan, PlannedCalendarEvent $event): void
    {
        if (!$plan->id()->equals($this->salesPlanId) || !$plan->companyId()->equals($this->companyId)) {
            throw new \InvalidArgumentException('Календарная запись принадлежит другому плану.');
        }
        $plan->assertEditable();
        $event->assertWithin($plan);
        $this->assign($event);
    }

    public function copyFor(SalesPlan $target): self
    {
        if (!$target->companyId()->equals($this->companyId)) {
            throw new \InvalidArgumentException('Нельзя переносить календарь в другую компанию.');
        }

        return self::create($target, $this->event());
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function salesPlanId(): Uuid
    {
        return $this->salesPlanId;
    }

    public function event(): PlannedCalendarEvent
    {
        return new PlannedCalendarEvent($this->kind, $this->marketplaceSku, $this->dateFrom, $this->dateTo);
    }

    private function assign(PlannedCalendarEvent $event): void
    {
        $this->marketplaceSku = $event->marketplaceSku;
        $this->kind = $event->kind;
        $this->dateFrom = $event->dateFrom;
        $this->dateTo = $event->dateTo;
    }
}
