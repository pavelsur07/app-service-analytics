<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/**
 * Одно поле, и всё же DTO, а не голая строка: правило §5 требует
 * маппить выборку в readonly DTO без оговорок про число колонок,
 * и одинаковая форма у всех Query-классов дороже пятнадцати
 * сэкономленных строк — читающему не приходится выяснять, почему
 * этот запрос устроен иначе.
 */
final readonly class CompanySkuRow
{
    public function __construct(
        public string $marketplaceSku,
    ) {
    }
}
