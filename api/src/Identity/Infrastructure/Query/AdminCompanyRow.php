<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

final readonly class AdminCompanyRow
{
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public string $createdAt,
        public bool $hasConfirmedUser,
    ) {
    }
}
