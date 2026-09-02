<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\EmailConfirmationOutcome;

final readonly class EmailConfirmationResult
{
    public function __construct(
        public EmailConfirmationOutcome $outcome,
        public ?User $user = null,
    ) {
    }
}
