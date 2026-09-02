<?php

declare(strict_types=1);

namespace App\Identity\Ui\Security;

use App\Identity\Domain\CaptchaUnavailable;
use App\Identity\Domain\CaptchaVerification;
use App\Identity\Domain\CaptchaVerifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * HTTP abuse-control boundary for public self-registration.
 *
 * Both distributed limits are consumed before either result is inspected, so
 * switching between email and IP cannot be used to avoid accounting.
 */
final readonly class RegistrationProtection
{
    public function __construct(
        private RateLimiterFactoryInterface $registrationEmailLimiter,
        private RateLimiterFactoryInterface $registrationIpLimiter,
        private CaptchaVerifier $captchaVerifier,
        private LoggerInterface $logger,
        private string $kernelSecret,
    ) {
    }

    public function check(string $normalizedEmail, ?string $clientIp, string $captchaToken): RegistrationProtectionResult
    {
        if (null === $clientIp) {
            $this->logger->warning('Registration protection unavailable', ['reason' => 'client_ip_missing']);

            return new RegistrationProtectionResult(RegistrationProtectionDecision::Unavailable);
        }

        $emailKey = hash_hmac('sha256', 'email:'.$normalizedEmail, $this->kernelSecret);
        $ipKey = hash_hmac('sha256', 'ip:'.$clientIp, $this->kernelSecret);

        $emailLimit = $this->registrationEmailLimiter->create($emailKey)->consume();
        $ipLimit = $this->registrationIpLimiter->create($ipKey)->consume();

        $retryAfter = null;
        if (!$emailLimit->isAccepted()) {
            $retryAfter = $emailLimit->getRetryAfter();
        }
        if (!$ipLimit->isAccepted() && (null === $retryAfter || $ipLimit->getRetryAfter() > $retryAfter)) {
            $retryAfter = $ipLimit->getRetryAfter();
        }
        if (null !== $retryAfter) {
            return new RegistrationProtectionResult(RegistrationProtectionDecision::RateLimited, $retryAfter);
        }

        try {
            $captcha = $this->captchaVerifier->verify($captchaToken, $clientIp);
        } catch (CaptchaUnavailable) {
            // The adapter owns the warning for its external failure.
            return new RegistrationProtectionResult(RegistrationProtectionDecision::Unavailable);
        }

        return new RegistrationProtectionResult(match ($captcha) {
            CaptchaVerification::Passed => RegistrationProtectionDecision::Allowed,
            CaptchaVerification::Rejected => RegistrationProtectionDecision::CaptchaRejected,
        });
    }
}
