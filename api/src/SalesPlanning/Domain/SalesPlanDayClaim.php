<?php

declare(strict_types=1);

namespace App\SalesPlanning\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Занятость дня утверждённым планом (ADR-040 п. 9).
 * Metadata для схемы; записи создаёт только пакетный DBAL INSERT SELECT.
 * Скалярные ссылки без ORM FK следуют соглашению SalesPlanLine.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_plan_day_claim')]
#[ORM\Index(name: 'idx_sales_plan_claim_company_plan', columns: ['company_id', 'sales_plan_id'])]
class SalesPlanDayClaim
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $marketplaceAccountId;

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private readonly string $marketplaceSku;

    // Только metadata: сущности claims не гидрируются.
    #[ORM\Id]
    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $planDate;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $salesPlanId;

    public function __construct(SalesPlan $plan, string $marketplaceSku, \DateTimeImmutable $date)
    {
        $this->companyId = $plan->companyId();
        $this->marketplaceAccountId = $plan->marketplaceAccountId();
        $this->salesPlanId = $plan->id();
        $this->marketplaceSku = $marketplaceSku;
        $this->planDate = SalesPlan::calendarDay($date);
    }
}
