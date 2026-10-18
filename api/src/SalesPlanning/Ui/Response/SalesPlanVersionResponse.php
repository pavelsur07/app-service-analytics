<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

/**
 * Новая версия плана после правки — с ней идёт следующая правка (ADR-008).
 */
final readonly class SalesPlanVersionResponse
{
    public function __construct(
        public int $version,
    ) {
    }
}
