<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * /v1/analytics/stocks — остатки FBO по складам с кластером (ADR-034).
 */
interface OzonStockFetcher
{
    /**
     * Пачка SKU — не больше OzonAnalyticsStocksParser::BATCH_SIZE.
     *
     * @param non-empty-list<string> $skus
     *
     * @return string тело ответа как есть
     */
    public function fetchStocks(string $clientId, string $apiKey, array $skus): string;
}
