<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface EmailVerificationTokenRepository
{
    public function add(EmailVerificationToken $token): void;

    public function confirm(string $tokenHash, \DateTimeImmutable $now): EmailConfirmationTransition;
}
