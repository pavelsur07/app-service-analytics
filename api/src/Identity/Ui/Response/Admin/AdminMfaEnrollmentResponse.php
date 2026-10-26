<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response\Admin;

final readonly class AdminMfaEnrollmentResponse
{
    public function __construct(public string $secret, public string $provisioningUri)
    {
    }
}
