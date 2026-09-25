<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Messenger;

use App\Ingestion\Infrastructure\Connector\Ozon\OzonSellerRateLimited;
use App\Ingestion\Infrastructure\Messenger\RateLimitAwareRetryStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Отказ лимита площадки не жжёт повторы очереди (ADR-028): сообщение
 * повторяется через названное площадкой время до суток от первой неудачи
 * и не уходит в `failed`, как 553 загрузки отправлений в сентябре 2026.
 */
final class RateLimitAwareRetryStrategyTest extends TestCase
{
    public function testOzon429IsRetriedAfterRetryAfterEvenPastTheUsualLimit(): void
    {
        $strategy = new RateLimitAwareRetryStrategy();
        $envelope = $this->envelope(retries: 12, firstFailureAgo: 3_600);
        $failure = $this->handlerFailure($envelope, $this->http429('30'));

        self::assertTrue($strategy->isRetryable($envelope, $failure));
        $wait = $strategy->getWaitingTime($envelope, $failure);
        self::assertGreaterThanOrEqual(30_000, $wait);
        self::assertLessThanOrEqual(35_000, $wait);
    }

    public function testOurOwnPauseIsRetriedAfterItsRemainder(): void
    {
        $strategy = new RateLimitAwareRetryStrategy();
        $envelope = $this->envelope(retries: 0, firstFailureAgo: null);
        $failure = $this->handlerFailure($envelope, new OzonSellerRateLimited(7, 'POST', '/v2/posting/fbo/list'));

        self::assertTrue($strategy->isRetryable($envelope, $failure));
        self::assertGreaterThanOrEqual(7_000, $strategy->getWaitingTime($envelope, $failure));
    }

    public function testRateLimitLongerThanADayGoesToFailed(): void
    {
        $strategy = new RateLimitAwareRetryStrategy();
        $envelope = $this->envelope(retries: 900, firstFailureAgo: 25 * 3_600);

        // Отказ дольше суток — уже не лимит, а сбой: его должно быть видно.
        self::assertFalse($strategy->isRetryable($envelope, $this->handlerFailure($envelope, $this->http429('60'))));
    }

    public function testOtherFailuresKeepTheUsualFiveRetries(): void
    {
        $strategy = new RateLimitAwareRetryStrategy();
        $failure = new \RuntimeException('Площадка ответила 500.');

        self::assertTrue($strategy->isRetryable($this->envelope(retries: 4, firstFailureAgo: 600), $failure));
        self::assertFalse($strategy->isRetryable($this->envelope(retries: 5, firstFailureAgo: 600), $failure));

        $wait = $strategy->getWaitingTime($this->envelope(retries: 1, firstFailureAgo: 60), $failure);
        self::assertGreaterThanOrEqual(81_000, $wait);
        self::assertLessThanOrEqual(99_000, $wait);
    }

    private function envelope(int $retries, ?int $firstFailureAgo): Envelope
    {
        $envelope = new Envelope(new \stdClass());
        if (null === $firstFailureAgo || 0 === $retries) {
            return $envelope;
        }

        // История повторов хранит первую отметку (SendFailedMessageForRetryListener),
        // по ней стратегия и считает сутки.
        return $envelope
            ->with(new RedeliveryStamp(1, new \DateTimeImmutable("-{$firstFailureAgo} seconds")))
            ->with(new RedeliveryStamp($retries));
    }

    private function handlerFailure(Envelope $envelope, \Throwable $cause): HandlerFailedException
    {
        return new HandlerFailedException($envelope, [$cause]);
    }

    private function http429(string $retryAfter): \Throwable
    {
        $client = new MockHttpClient(new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['Retry-After' => $retryAfter]]));
        try {
            $client->request('POST', 'https://api-seller.ozon.ru/v2/posting/fbo/list')->getContent();
        } catch (\Throwable $failure) {
            return $failure;
        }

        throw new \LogicException('Ответ 429 обязан бросить исключение.');
    }
}
