<?php

declare(strict_types=1);

namespace App\Identity\Ui\Security;

enum RegistrationProtectionDecision
{
    case Allowed;
    case RateLimited;
    case CaptchaRejected;
    case Unavailable;
}
