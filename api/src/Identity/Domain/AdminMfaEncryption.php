<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface AdminMfaEncryption
{
    public function encrypt(string $secret): string;

    public function decrypt(string $encrypted): string;
}
