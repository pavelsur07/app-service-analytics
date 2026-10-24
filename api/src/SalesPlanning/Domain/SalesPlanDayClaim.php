<?php

declare(strict_types=1);

namespace App\SalesPlanning\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Занятый день SKU подключения утверждённым планом (ADR-040 п. 9).
 *
 * Ключ — `(company_id, marketplace_account_id, marketplace_sku, plan_date)`:
 * на SKU в день действует ровно один утверждённый план, и держит это
 * сам ключ — утверждение вставляет дни с `ON CONFLICT DO NOTHING`
 * и сверяет число вставленных строк, проверки «занято ли» перед
 * вставкой нет (CLAUDE.md §4).
 *
 * Та же таблица отвечает, какой план действует на SKU в день, — будущее
 * сравнение с фактом читает её, а не перебирает статусы планов.
 *
 * Не пишется ORM: только DBAL в транзакции утверждения, отмены
 * и вытеснения (как снимочный факт остатков). Класс существует для
 * migrations:diff, schema:validate (CLAUDE.md §6).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_plan_day_claim')]
#[ORM\Index(name: 'idx_sales_plan_day_claim_company_plan', columns: ['company_id', 'sales_plan_id'])]
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

    #[ORM\Id]
    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $planDate;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $salesPlanId;

    private function __construct(Uuid $companyId, Uuid $marketplaceAccountId, string $marketplaceSku, \DateTimeImmutable $planDate, Uuid $salesPlanId)
    {
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->marketplaceSku = $marketplaceSku;
        $this->planDate = $planDate;
        $this->salesPlanId = $salesPlanId;
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function marketplaceAccountId(): Uuid
    {
        return $this->marketplaceAccountId;
    }

    public function marketplaceSku(): string
    {
        return $this->marketplaceSku;
    }

    public function planDate(): \DateTimeImmutable
    {
        return $this->planDate;
    }

    public function salesPlanId(): Uuid
    {
        return $this->salesPlanId;
    }
}
