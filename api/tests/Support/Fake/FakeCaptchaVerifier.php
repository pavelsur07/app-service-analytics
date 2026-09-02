<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Identity\Domain\CaptchaUnavailable;
use App\Identity\Domain\CaptchaVerification;
use App\Identity\Domain\CaptchaVerifier;

/** Test double for flows that must never make an external CAPTCHA request. */
final class FakeCaptchaVerifier implements CaptchaVerifier
{
    public int $calls = 0;

    public ?string $receivedToken = null;

    public ?string $receivedClientIp = null;

    public function __construct(
        private readonly CaptchaVerification $outcome = CaptchaVerification::Passed,
        private readonly ?CaptchaUnavailable $unavailable = null,
    ) {
    }

    public function verify(string $token, string $clientIp): CaptchaVerification
    {
        ++$this->calls;
        $this->receivedToken = $token;
        $this->receivedClientIp = $clientIp;

        if (null !== $this->unavailable) {
            throw $this->unavailable;
        }

        return $this->outcome;
    }
}
