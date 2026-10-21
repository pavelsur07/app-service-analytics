<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Ingestion\Domain\OzonPostingsFetcher;

final class FakeSequentialOzonPostingsFetcher implements OzonPostingsFetcher
{
    private int $calls = 0;

    /** @var list<string> */
    private array $cursors = [];

    /**
     * @param non-empty-list<string> $bodies
     */
    public function __construct(
        private readonly array $bodies,
    ) {
    }

    public function fetchPage(string $clientId, string $apiKey, \DateTimeImmutable $since, \DateTimeImmutable $to, string $cursor): string
    {
        $this->cursors[] = $cursor;
        $index = min($this->calls, \count($this->bodies) - 1);
        ++$this->calls;

        return $this->bodies[$index];
    }

    public function calls(): int
    {
        return $this->calls;
    }

    /**
     * Курсоры запросов по порядку — проверка, что листание передаёт
     * курсор предыдущей страницы.
     *
     * @return list<string>
     */
    public function cursors(): array
    {
        return $this->cursors;
    }
}
