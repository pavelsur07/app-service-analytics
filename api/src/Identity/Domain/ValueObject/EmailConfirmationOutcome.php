<?php

declare(strict_types=1);

namespace App\Identity\Domain\ValueObject;

enum EmailConfirmationOutcome: string
{
    case Confirmed = 'confirmed';
    case Expired = 'expired';
    case AlreadyConsumed = 'already_consumed';
}
