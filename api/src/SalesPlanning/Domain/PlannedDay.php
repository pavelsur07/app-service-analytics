<?php

declare(strict_types=1);

namespace App\SalesPlanning\Domain;

/**
 * День SKU в плане: штуки и цена за штуку в минорных единицах валюты
 * плана (ADR-040 п. 3, 6). Значение, а не сущность: строки плана пишутся
 * пакетом (ADR-041), и сценарию нужны значения дней, а не объекты ORM.
 */
final readonly class PlannedDay
{
    public function __construct(
        public \DateTimeImmutable $date,
        public int $quantity,
        public int $priceMinor,
    ) {
    }
}
