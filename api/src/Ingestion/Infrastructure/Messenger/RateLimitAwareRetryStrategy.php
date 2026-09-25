<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Messenger;

use App\Ingestion\Infrastructure\Connector\Ozon\OzonSellerRateLimited;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonSellerRateLimitingClient;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * Повторы очереди загрузки, которые знают про лимит площадки (ADR-028).
 *
 * Отказ лимита — 429 от площадки или пауза лимита на нашей стороне
 * (`OzonSellerRateLimited`) — не сбой, а «повторите позже». Такое
 * сообщение повторяется через время, которое назвала площадка, и не жжёт
 * пять повторов за 25 минут: в сентябре 2026 так в `failed` ушли 553
 * загрузки отправлений. Потолок — сутки от первой неудачи сообщения:
 * отказ дольше — уже не лимит, а сбой, и сообщение уходит в `failed`,
 * где его видно.
 *
 * Прочие ошибки — прежняя политика (5 повторов, 30 с × 3, до 10 минут).
 * Номер повтора у сообщения общий: после долгой серии отказов лимита
 * обычная ошибка уже не повторяется и сразу видна в `failed`.
 */
final readonly class RateLimitAwareRetryStrategy implements RetryStrategyInterface
{
    public const int GIVE_UP_AFTER_SECONDS = 86_400;

    /** Разброс задержки: отложенные сообщения не просыпаются одной волной. */
    private const int JITTER_MS = 5_000;

    private MultiplierRetryStrategy $default;

    public function __construct()
    {
        $this->default = new MultiplierRetryStrategy(
            maxRetries: 5,
            delayMilliseconds: 30_000,
            multiplier: 3,
            maxDelayMilliseconds: 600_000,
        );
    }

    public function isRetryable(Envelope $message, ?\Throwable $throwable = null): bool
    {
        $wait = self::rateLimitedFor($throwable);
        if (null === $wait) {
            return $this->default->isRetryable($message, $throwable);
        }

        // Потолок считается вместе с предстоящим ожиданием и наибольшим
        // разбросом — повтор не уходит за сутки.
        $first = self::firstFailureOfCurrentRun($message);
        $elapsed = null === $first ? 0 : time() - $first->getTimestamp();

        return $elapsed + $wait + intdiv(self::JITTER_MS, 1_000) < self::GIVE_UP_AFTER_SECONDS;
    }

    public function getWaitingTime(Envelope $message, ?\Throwable $throwable = null): int
    {
        $seconds = self::rateLimitedFor($throwable);
        if (null === $seconds) {
            return $this->default->getWaitingTime($message, $throwable);
        }

        return $seconds * 1_000 + random_int(0, self::JITTER_MS);
    }

    /**
     * Первая неудача текущего захода сообщения.
     *
     * Первая отметка истории — первая неудача: слушатель повторов при
     * усечении истории сохраняет её явно (`withLimitedHistory`). Но отправка
     * в `failed` добавляет сброс — `RedeliveryStamp(0)`, — и после
     * `messenger:failed:retry` сутки считаются заново, от первой неудачи
     * после сброса; нет её — неудача первая, прошло ноль. Если сброс срезало
     * усечение истории, а сообщение побывало в `failed`, все сохранённые
     * отметки кроме первой — уже после сброса: берётся вторая.
     */
    private static function firstFailureOfCurrentRun(Envelope $message): ?\DateTimeInterface
    {
        $stamps = array_values(array_filter(
            $message->all(RedeliveryStamp::class),
            static fn (object $stamp): bool => $stamp instanceof RedeliveryStamp,
        ));

        $reset = null;
        foreach ($stamps as $index => $stamp) {
            if (0 === $stamp->getRetryCount()) {
                $reset = $index;
            }
        }

        if (null !== $reset) {
            return ($stamps[$reset + 1] ?? null)?->getRedeliveredAt();
        }

        if (null !== $message->last(SentToFailureTransportStamp::class)) {
            return ($stamps[1] ?? $stamps[0] ?? null)?->getRedeliveredAt();
        }

        return ($stamps[0] ?? null)?->getRedeliveredAt();
    }

    /**
     * Через сколько секунд повторять, если причина — лимит; `null`, если нет.
     */
    public static function rateLimitedFor(?\Throwable $throwable): ?int
    {
        foreach (self::chain($throwable) as $failure) {
            if ($failure instanceof OzonSellerRateLimited) {
                return $failure->retryAfterSeconds;
            }

            if ($failure instanceof HttpExceptionInterface) {
                try {
                    $response = $failure->getResponse();
                    if (429 === $response->getStatusCode()) {
                        return OzonSellerRateLimitingClient::retryAfter($response->getHeaders(false)['retry-after'][0] ?? null);
                    }
                } catch (\Throwable) {
                    // Ответ недоступен — это не отказ лимита.
                }
            }
        }

        return null;
    }

    /**
     * Исключение, его причины и исключения обработчиков внутри
     * `HandlerFailedException`.
     *
     * @return iterable<\Throwable>
     */
    private static function chain(?\Throwable $throwable): iterable
    {
        $pending = null === $throwable ? [] : [$throwable];
        $seen = 0;
        while ([] !== $pending && $seen++ < 32) {
            $current = array_shift($pending);
            yield $current;

            if ($current instanceof HandlerFailedException) {
                foreach ($current->getWrappedExceptions() as $wrapped) {
                    $pending[] = $wrapped;
                }
            }
            if (null !== $current->getPrevious()) {
                $pending[] = $current->getPrevious();
            }
        }
    }
}
