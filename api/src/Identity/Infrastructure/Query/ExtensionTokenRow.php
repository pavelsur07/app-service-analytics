<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

final readonly class ExtensionTokenRow
{
    public function __construct(
        public string $id,
        public string $companyId,
        public string $userId,
        public string $userEmail,
    ) {
    }
}
