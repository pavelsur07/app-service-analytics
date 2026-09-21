<?php

declare(strict_types=1);

namespace App\Planning\Domain;

interface DailyPlanRepository
{
    public function get(string $companyId, string $marketplaceAccountId, string $marketplaceSku, \DateTimeImmutable $businessDate): ?DailyPlan;

    public function add(DailyPlan $plan, PlanChange $change): void;

    public function save(PlanChange $change): void;
}
