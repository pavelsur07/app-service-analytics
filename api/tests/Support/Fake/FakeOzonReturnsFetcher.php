<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Ingestion\Domain\OzonReturnsFetcher;

final class FakeOzonReturnsFetcher implements OzonReturnsFetcher
{
    /**
     * @var list<array{from: \DateTimeImmutable, to: \DateTimeImmutable, lastId: int, limit: int}>
     */
    public array $requests = [];

    /** @var list<string|\Throwable> */
    private array $responses;

    /**
     * @param list<string|\Throwable> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function fetchPage(
        string $clientId,
        string $apiKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $lastId,
        int $limit = self::MAX_LIMIT,
    ): string {
        $this->requests[] = compact('from', 'to', 'lastId', 'limit');
        $response = array_shift($this->responses);
        if (null === $response) {
            throw new \LogicException('Обработчик запросил больше страниц returns, чем задано в тесте.');
        }
        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }
}
