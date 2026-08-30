<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Symfony\Component\Uid\Uuid;

final readonly class OzonPostingRawHistoryRow
{
    public function __construct(
        public Uuid $id,
        public string $body,
        public \DateTimeImmutable $receivedAt,
    ) {
    }
}
