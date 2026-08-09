<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response;

final readonly class LoginResponse
{
    public function __construct(
        public string $email,
    ) {
    }
}
