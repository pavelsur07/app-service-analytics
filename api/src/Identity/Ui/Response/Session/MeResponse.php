<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response\Session;

use App\Identity\Ui\Response\MeCompanyResponse;

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
