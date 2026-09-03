<?php

declare(strict_types=1);

namespace App\Links\Infrastructure\Query;

final readonly class RedirectTarget
{
    public function __construct(
        public string $id,
        public string $code,
        public string $targetUrl,
    ) {
    }
}
