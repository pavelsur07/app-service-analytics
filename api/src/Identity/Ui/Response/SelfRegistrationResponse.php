<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response;

final readonly class SelfRegistrationResponse
{
    public function __construct(
        public string $message,
    ) {
    }
}
