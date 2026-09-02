<?php

declare(strict_types=1);

namespace App\Identity\Domain;

enum CaptchaVerification
{
    case Passed;
    case Rejected;
}
