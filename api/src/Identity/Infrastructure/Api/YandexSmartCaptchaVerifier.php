<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Api;

use App\Identity\Domain\CaptchaUnavailable;
use App\Identity\Domain\CaptchaUnavailableReason;
use App\Identity\Domain\CaptchaVerification;
use App\Identity\Domain\CaptchaVerifier;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Синхронная граница Stage 2 регистрации: SmartCaptcha должен разрешить
 * или отклонить именно этот запрос, поэтому ретраев и фоновой доставки нет.
 */
final readonly class YandexSmartCaptchaVerifier implements CaptchaVerifier
{
    public function __construct(
        private HttpClientInterface $smartCaptchaClient,
        private string $smartCaptchaServerKey,
        private LoggerInterface $logger,
    ) {
    }

    public function verify(string $token, string $clientIp): CaptchaVerification
    {
        try {
            $response = $this->smartCaptchaClient->request('POST', '/validate', [
                'body' => [
                    'secret' => $this->smartCaptchaServerKey,
                    'token' => $token,
                    'ip' => $clientIp,
                ],
            ]);
            $httpStatus = $response->getStatusCode();
        } catch (TransportExceptionInterface) {
            throw $this->unavailable(CaptchaUnavailableReason::Transport);
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw $this->unavailable(CaptchaUnavailableReason::HttpStatus, $httpStatus);
        }

        try {
            $payload = $response->toArray(false);
        } catch (DecodingExceptionInterface|\TypeError) {
            throw $this->unavailable(CaptchaUnavailableReason::InvalidJson);
        } catch (TransportExceptionInterface) {
            throw $this->unavailable(CaptchaUnavailableReason::Transport);
        }

        return match ($payload['status'] ?? null) {
            'ok' => CaptchaVerification::Passed,
            'failed' => CaptchaVerification::Rejected,
            default => throw $this->unavailable(CaptchaUnavailableReason::UnexpectedStatus),
        };
    }

    private function unavailable(CaptchaUnavailableReason $reason, ?int $httpStatus = null): CaptchaUnavailable
    {
        $context = ['reason' => $reason->value];
        if (null !== $httpStatus) {
            $context['http_status'] = $httpStatus;
        }

        $this->logger->warning('SmartCaptcha verification unavailable', $context);

        return new CaptchaUnavailable($reason, $httpStatus);
    }
}
