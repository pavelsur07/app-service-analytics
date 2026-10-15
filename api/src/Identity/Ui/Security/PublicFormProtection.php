<?php

declare(strict_types=1);

namespace App\Identity\Ui\Security;

use App\Identity\Domain\CaptchaUnavailable;
use App\Identity\Domain\CaptchaVerification;
use App\Identity\Domain\CaptchaVerifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * HTTP abuse-control boundary for public pre-auth forms that send email:
 * self-registration (ADR-021) and password reset request (ADR-037). Each form
 * gets its own instance with its own pair of limiters (config/services.yaml).
 *
 * Both distributed limits are consumed before either result is inspected, so
 * switching between email and IP cannot be used to avoid accounting.
 */
final readonly class PublicFormProtection
{
    public function __construct(
        private RateLimiterFactoryInterface $emailLimiter,
        private RateLimiterFactoryInterface $ipLimiter,
        private CaptchaVerifier $captchaVerifier,
        private LoggerInterface $logger,
        private string $kernelSecret,
    ) {
    }

    public function check(string $normalizedEmail, ?string $clientIp, string $captchaToken): PublicFormProtectionResult
    {
        if (null === $clientIp) {
            $this->logger->warning('Public form protection unavailable', ['reason' => 'client_ip_missing']);

            return new PublicFormProtectionResult(PublicFormProtectionDecision::Unavailable);
        }

        $emailKey = hash_hmac('sha256', 'email:'.$normalizedEmail, $this->kernelSecret);
        $ipKey = hash_hmac('sha256', 'ip:'.$clientIp, $this->kernelSecret);

        $emailLimit = $this->emailLimiter->create($emailKey)->consume();
        $ipLimit = $this->ipLimiter->create($ipKey)->consume();

        $retryAfter = null;
        if (!$emailLimit->isAccepted()) {
            $retryAfter = $emailLimit->getRetryAfter();
        }
        if (!$ipLimit->isAccepted() && (null === $retryAfter || $ipLimit->getRetryAfter() > $retryAfter)) {
            $retryAfter = $ipLimit->getRetryAfter();
        }
        if (null !== $retryAfter) {
            return new PublicFormProtectionResult(PublicFormProtectionDecision::RateLimited, $retryAfter);
        }

        try {
            $captcha = $this->captchaVerifier->verify($captchaToken, $clientIp);
        } catch (CaptchaUnavailable) {
            // The adapter owns the warning for its external failure.
            return new PublicFormProtectionResult(PublicFormProtectionDecision::Unavailable);
        }

        return new PublicFormProtectionResult(match ($captcha) {
            CaptchaVerification::Passed => PublicFormProtectionDecision::Allowed,
            CaptchaVerification::Rejected => PublicFormProtectionDecision::CaptchaRejected,
        });
    }
}
