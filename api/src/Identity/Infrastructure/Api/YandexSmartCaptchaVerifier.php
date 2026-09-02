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
    private string $expectedHost;

    public function __construct(
        private HttpClientInterface $smartCaptchaClient,
        private string $smartCaptchaServerKey,
        private LoggerInterface $logger,
        string $sellerAppOrigin,
    ) {
        $this->expectedHost = self::authorityHost($sellerAppOrigin);
    }

    public function verify(string $token, string $clientIp): CaptchaVerification
    {
        try {
            $response = $this->smartCaptchaClient->request('POST', '/validate', [
                'max_redirects' => 0,
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

        $status = $payload['status'] ?? null;
        if ('ok' === $status) {
            if (($payload['host'] ?? null) !== $this->expectedHost) {
                throw $this->unavailable(CaptchaUnavailableReason::UnexpectedHost);
            }

            return CaptchaVerification::Passed;
        }

        if ('failed' === $status) {
            return CaptchaVerification::Rejected;
        }

        throw $this->unavailable(CaptchaUnavailableReason::UnexpectedStatus);
    }

    private static function authorityHost(string $sellerAppOrigin): string
    {
        try {
            $parts = parse_url($sellerAppOrigin);
        } catch (\ValueError) {
            throw self::invalidSellerOrigin();
        }

        if (!\is_array($parts)) {
            throw self::invalidSellerOrigin();
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? '';
        if (!\is_string($scheme)
            || !\in_array(strtolower($scheme), ['http', 'https'], true)
            || !\is_string($host)
            || '' === $host
            || (!\is_string($path) || !\in_array($path, ['', '/'], true))
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw self::invalidSellerOrigin();
        }

        $port = $parts['port'] ?? null;

        return $host.(\is_int($port) ? ':'.$port : '');
    }

    private static function invalidSellerOrigin(): \InvalidArgumentException
    {
        return new \InvalidArgumentException('SELLER_APP_ORIGIN must be an absolute HTTP(S) origin.');
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
