<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/**
 * Строка результата RecentlyIngestedAccountsQuery (CLAUDE.md §5:
 * результат DBAL-запроса маппится в readonly DTO, а не разъезжается
 * массивами по вызывающему коду).
 */
final readonly class RecentlyIngestedAccountRow
{
    public function __construct(
        public string $companyId,
        public string $marketplaceAccountId,
    ) {
    }
}
