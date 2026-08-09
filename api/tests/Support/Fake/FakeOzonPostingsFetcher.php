<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Ingestion\Domain\OzonPostingsFetcher;

/**
 * ADR-005: обращения к внешним API в тестах запрещены. Возвращает
 * зафиксированное тело независимо от параметров — реальный HTTP-клиент
 * (пакет 2) уже проверен на MockHttpClient отдельно.
 */
final readonly class FakeOzonPostingsFetcher implements OzonPostingsFetcher
{
    public function __construct(
        private string $body,
    ) {
    }

    public function fetch(string $clientId, string $apiKey, \DateTimeImmutable $since, \DateTimeImmutable $to): string
    {
        return $this->body;
    }
}
