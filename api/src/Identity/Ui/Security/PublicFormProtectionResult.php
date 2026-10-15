<?php

declare(strict_types=1);

namespace App\Identity\Ui\Security;

final readonly class PublicFormProtectionResult
{
    public function __construct(
        public PublicFormProtectionDecision $decision,
        public ?\DateTimeImmutable $retryAfter = null,
    ) {
    }
}
