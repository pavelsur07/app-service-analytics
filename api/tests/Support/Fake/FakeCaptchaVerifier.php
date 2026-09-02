<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Identity\Domain\CaptchaUnavailable;
use App\Identity\Domain\CaptchaVerification;
use App\Identity\Domain\CaptchaVerifier;
use Symfony\Contracts\Service\ResetInterface;

/** Test double for flows that must never make an external CAPTCHA request. */
final class FakeCaptchaVerifier implements CaptchaVerifier, ResetInterface
{
    public int $calls = 0;

    public ?string $receivedToken = null;

    public ?string $receivedClientIp = null;

    public function __construct(
        private CaptchaVerification $outcome = CaptchaVerification::Passed,
        private ?CaptchaUnavailable $unavailable = null,
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

    public function reject(): void
    {
        $this->outcome = CaptchaVerification::Rejected;
        $this->unavailable = null;
    }

    public function becomeUnavailable(CaptchaUnavailable $unavailable): void
    {
        $this->unavailable = $unavailable;
    }

    public function reset(): void
    {
        $this->calls = 0;
        $this->receivedToken = null;
        $this->receivedClientIp = null;
        $this->outcome = CaptchaVerification::Passed;
        $this->unavailable = null;
    }
}
