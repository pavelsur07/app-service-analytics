<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Ingestion\Domain\OzonStockFetcher;

/**
 * Очередь ответов /v1/analytics/stocks: строка — тело ответа, исключение —
 * отказ площадки на этой пачке. Запоминает пачки SKU, с которыми его звали.
 */
final class FakeOzonStockFetcher implements OzonStockFetcher
{
    /** @var list<list<string>> */
    public array $requestedBatches = [];

    /**
     * @param list<string|\Throwable> $responses
     */
    public function __construct(private array $responses)
    {
    }

    public function fetchStocks(string $clientId, string $apiKey, array $skus): string
    {
        $this->requestedBatches[] = $skus;
        $response = array_shift($this->responses);
        if (null === $response) {
            throw new \LogicException('FakeOzonStockFetcher: ответы закончились — обработчик запросил лишнее.');
        }
        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }
}
