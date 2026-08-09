<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response;

final readonly class MeCompanyResponse
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
