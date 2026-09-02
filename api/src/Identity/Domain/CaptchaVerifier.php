<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface CaptchaVerifier
{
    public function verify(string $token, string $clientIp): CaptchaVerification;
}
