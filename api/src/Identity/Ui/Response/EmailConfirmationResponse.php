<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response;

final readonly class EmailConfirmationResponse
{
    public function __construct(
        public string $outcome,
        public ?string $next,
    ) {
    }
}
