<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface AdminTotp
{
    public function generateSecret(): string;

    public function provisioningUri(string $secret, string $email): string;

    public function matchTimeStep(string $secret, string $code, int $timestamp): ?int;
}
