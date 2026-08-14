<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/**
 * Строка результата AccountFreshnessQuery (CLAUDE.md §5).
 */
final readonly class AccountFreshnessRow
{
    public function __construct(
        public string $marketplaceAccountId,
        public string $reportType,
        public string $lastReceivedAt,
    ) {
    }
}
