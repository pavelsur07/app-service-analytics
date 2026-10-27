<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

use App\SalesPlanning\Domain\ClaimedSalesPlanDay;

final readonly class SalesPlanDayConflictResponse
{
    public int $status;
    public string $code;
    public string $message;

    /** @param list<ClaimedSalesPlanDay> $conflicts */
    public function __construct(public array $conflicts)
    {
        $this->status = 409;
        $this->code = 'sales_plan_day_conflict';
        $this->message = 'Дни уже заняты утверждённым планом. Показано до 200 пересечений.';
    }
}
