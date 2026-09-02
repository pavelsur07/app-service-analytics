<?php

declare(strict_types=1);

namespace App\Identity\Ui\Security;

final readonly class RegistrationProtectionResult
{
    public function __construct(
        public RegistrationProtectionDecision $decision,
        public ?\DateTimeImmutable $retryAfter = null,
    ) {
    }
}
