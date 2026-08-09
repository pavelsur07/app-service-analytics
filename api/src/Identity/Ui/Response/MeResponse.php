<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response;

final readonly class MeResponse
{
    /**
     * @param list<MeCompanyResponse> $companies
     */
    public function __construct(
        public string $email,
        public array $companies,
    ) {
    }
}
