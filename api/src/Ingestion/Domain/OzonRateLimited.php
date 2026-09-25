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
        if (!$failure instanceof HttpClientException || !method_exists($failure, 'getResponse')) {
            return false;
        }

        try {
            return 429 === $failure->getResponse()->getStatusCode();
        } catch (\Throwable) {
            return false;
        }
    }
}
