<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;

/**
 * Отказ лимита площадки (429). Для асинхронных отчётов Performance API
 * это ещё и «у кабинета уже формируется отчёт» (ADR-026 п. 4): такой
 * отказ не ошибка, а повод повторить позже. Код достаётся из исключения
 * symfony/http-client тем же приёмом, что в `OzonAuthorizationFailure`.
 */
final class OzonRateLimited
{
    private function __construct()
    {
    }

    public static function is(\Throwable $failure): bool
    {
        return 429 === self::statusOf($failure);
    }

    /**
     * Код отказа 4xx из исключения клиента; `null` для сетевых сбоев и 5xx —
     * у них исход неизвестен, и решение о повторе остаётся за очередью.
     */
    public static function clientErrorStatus(\Throwable $failure): ?int
    {
        $status = self::statusOf($failure);

        return null !== $status && $status >= 400 && $status < 500 ? $status : null;
    }

    private static function statusOf(\Throwable $failure): ?int
    {
        if (!$failure instanceof HttpClientException || !method_exists($failure, 'getResponse')) {
            return null;
        }

        try {
            $status = $failure->getResponse()->getStatusCode();
        } catch (\Throwable) {
            return null;
        }

        return \is_int($status) ? $status : null;
    }
}
