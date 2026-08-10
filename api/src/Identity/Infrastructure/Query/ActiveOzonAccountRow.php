<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

final readonly class ActiveOzonAccountRow
{
    public function __construct(
        public string $companyId,
        public string $marketplaceAccountId,
    ) {
    }
}
