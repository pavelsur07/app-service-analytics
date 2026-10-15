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
        // Имя формы в журнале: по нему всплеск отказов отличают от шума
        // соседней формы (config/services.yaml).
        private string $form,
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
            // Отказ — не ошибка, а сигнал: без записи массовый перебор
            // виден только по жалобам и счёту SMTP-провайдера. Адрес и IP
            // в журнал не попадают — только какой лимит сработал.
            $this->logger->warning('Public form rate limit exceeded', [
                'form' => $this->form,
                'limits' => array_keys(array_filter([
                    'email' => !$emailLimit->isAccepted(),
                    'ip' => !$ipLimit->isAccepted(),
                ])),
            ]);

            return new PublicFormProtectionResult(PublicFormProtectionDecision::RateLimited, $retryAfter);
        }

        try {
            $captcha = $this->captchaVerifier->verify($captchaToken, $clientIp);
        } catch (CaptchaUnavailable) {
            // The adapter owns the warning for its external failure.
            return new PublicFormProtectionResult(PublicFormProtectionDecision::Unavailable);
        }

        if (CaptchaVerification::Rejected === $captcha) {
            $this->logger->warning('Public form captcha rejected', ['form' => $this->form]);

            return new PublicFormProtectionResult(PublicFormProtectionDecision::CaptchaRejected);
        }

        return new PublicFormProtectionResult(PublicFormProtectionDecision::Allowed);
    }
}
