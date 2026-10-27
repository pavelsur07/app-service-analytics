<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

final class SalesPlanPerformanceReadException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $httpStatus)
    {
        parent::__construct($errorCode);
    }
}
