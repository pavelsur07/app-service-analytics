<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/**
 * Число кабинетов allowlist с загруженным PHP днём — по ресурсу сервиса.
 */
final readonly class PhpDayLoadRow
{
    public function __construct(
        public string $resourceType,
        /** `Y-m-d`, день по часам площадки */
        public string $periodKey,
        public int $cabinets,
    ) {
    }
}
