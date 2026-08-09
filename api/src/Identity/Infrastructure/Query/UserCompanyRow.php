<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

final readonly class UserCompanyRow
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
