<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Ingestion\Domain\OzonProductInfoFetcher;

/**
 * ADR-005: обращения к внешним API в тестах запрещены.
 *
 * Отдаёт тела по очереди — по одному на страницу каталога — и запоминает
 * запрошенные идентификаторы: то, что запрос имён уходит именно по товарам
 * этой страницы, и есть проверяемое поведение.
 */
final class FakeOzonProductInfoFetcher implements OzonProductInfoFetcher
{
    /** @var list<list<int>> */
    public array $requestedProductIds = [];

    /** @var list<string> */
    private array $responses;

    /**
     * @param list<string> $responses тела ответов по порядку запросов
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function fetchNames(string $clientId, string $apiKey, array $productIds): string
    {
        $this->requestedProductIds[] = $productIds;

        $response = array_shift($this->responses);
        if (null === $response) {
            throw new \LogicException('Обработчик запросил имена чаще, чем задано в тесте.');
        }

        return $response;
    }
}
