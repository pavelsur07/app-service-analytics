<?php

declare(strict_types=1);

namespace App\SalesPlanning\Domain;

final class SalesPlanApprovalConflict extends \RuntimeException
{
    /** @param list<ClaimedSalesPlanDay> $conflicts */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct('Дни уже заняты утверждённым планом.');
    }
}
