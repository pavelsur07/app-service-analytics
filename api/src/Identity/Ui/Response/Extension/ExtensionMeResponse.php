<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response\Extension;

use App\Identity\Ui\Response\MeCompanyResponse;

/**
 * Одна компания, не список: токен расширения привязан к одной компании
 * (ADR-010) — в отличие от MeResponse, где пользователь выбирает
 * из доступных ему по членству.
 */
final readonly class ExtensionMeResponse
{
    public function __construct(
        public string $email,
        public MeCompanyResponse $company,
    ) {
    }
}
