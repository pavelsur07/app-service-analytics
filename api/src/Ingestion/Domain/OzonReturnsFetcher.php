<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Одна страница /v1/returns/list. Курсор является opaque: его можно
 * передать обратно и проверить на повтор, но нельзя сортировать/сравнивать.
 */
interface OzonReturnsFetcher
{
    public const int MAX_LIMIT = 500;

    public function fetchPage(
        string $clientId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $lastId,
        int $limit = self::MAX_LIMIT,
    ): string;
}
