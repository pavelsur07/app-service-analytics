<?php

declare(strict_types=1);

namespace App\Identity\Domain;

final readonly class AdminMfaEnrollment
{
    /** @var non-empty-string */
    public string $secret;

    public function __construct(string $secret, public int $version)
    {
        if ('' === $secret) {
            throw new \InvalidArgumentException('MFA secret cannot be empty.');
        }
        $this->secret = $secret;
    }
}
