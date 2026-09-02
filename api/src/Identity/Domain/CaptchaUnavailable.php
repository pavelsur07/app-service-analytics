<?php

declare(strict_types=1);

namespace App\Identity\Domain;

final class CaptchaUnavailable extends \RuntimeException
{
    public function __construct(
        public readonly CaptchaUnavailableReason $reason,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct('Captcha verification is unavailable.');
    }
}
