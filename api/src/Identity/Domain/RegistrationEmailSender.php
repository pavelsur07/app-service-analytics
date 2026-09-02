<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Identity\Domain\ValueObject\EmailVerificationSecret;

interface RegistrationEmailSender
{
    public function sendConfirmation(string $email, EmailVerificationSecret $secret): void;

    public function sendAlreadyRegistered(string $email): void;
}
