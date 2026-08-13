<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Ingestion\Domain\OzonCatalogFetcher;

/**
 * ADR-005: обращения к внешним API в тестах запрещены.
 *
 * В отличие от FakeOzonPostingsFetcher отдаёт страницы по очереди
 * и запоминает запрошенные курсоры: курсорная пагинация — ровно то
 * поведение обработчика, которое проверяется, и заглушка с одним телом
 * на все запросы его бы не показала.
 */
final class FakeOzonCatalogFetcher implements OzonCatalogFetcher
{
    /** @var list<string> */
    public array $requestedCursors = [];

    /** @var list<string> */
    private array $pages;

    /**
     * @param list<string> $pages тела ответов по порядку страниц
     */
    public function __construct(array $pages)
    {
        $this->pages = $pages;
    }

    public function fetchPage(string $clientId, string $apiKey, string $lastId, int $limit = 1000): string
    {
        $this->requestedCursors[] = $lastId;

        $page = array_shift($this->pages);
        if (null === $page) {
            throw new \LogicException('Обработчик запросил больше страниц, чем задано в тесте.');
        }

        return $page;
    }
}
