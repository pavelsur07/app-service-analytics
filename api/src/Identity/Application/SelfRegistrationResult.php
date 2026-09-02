<?php

declare(strict_types=1);

namespace App\Identity\Application;

final readonly class SelfRegistrationResult
{
    public function __construct(
        public bool $created,
    ) {
    }
}
