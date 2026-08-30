<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Ingestion\Domain\OzonPostingsFetcher;

final class FakeSequentialOzonPostingsFetcher implements OzonPostingsFetcher
{
    private int $calls = 0;

    /**
     * @param non-empty-list<string> $bodies
     */
    public function __construct(
        private readonly array $bodies,
    ) {
    }

    public function fetch(string $clientId, string $apiKey, \DateTimeImmutable $since, \DateTimeImmutable $to): string
    {
        $index = min($this->calls, \count($this->bodies) - 1);
        ++$this->calls;

        return $this->bodies[$index];
    }

    public function calls(): int
    {
        return $this->calls;
    }
}
