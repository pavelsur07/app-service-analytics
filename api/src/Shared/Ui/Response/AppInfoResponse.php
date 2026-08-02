<?php

declare(strict_types=1);

namespace App\Shared\Ui\Response;

final readonly class AppInfoResponse
{
    public function __construct(
        public string $app,
        public string $version,
        public string $respondedAt,
    ) {
    }
}
