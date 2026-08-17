<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Infrastructure\Query;

/**
 * Одно поле, и всё же DTO, а не голая строка — по тем же соображениям,
 * что у `CompanySkuRow`: §5 требует маппить выборку в readonly DTO без
 * оговорок про число колонок, и одинаковая форма у всех Query-классов
 * дороже пятнадцати сэкономленных строк.
 */
final readonly class TrackedSkuRow
{
    public function __construct(
        public string $marketplaceSku,
    ) {
    }
}
