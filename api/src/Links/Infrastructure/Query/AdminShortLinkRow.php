<?php

declare(strict_types=1);

namespace App\Links\Infrastructure\Query;

final readonly class AdminShortLinkRow
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $targetUrl,
        public string $status,
        public int $version,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
