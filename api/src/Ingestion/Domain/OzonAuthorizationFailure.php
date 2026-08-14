<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;

/**
 * Отличает «площадка отказала в авторизации» от любого другого отказа.
 *
 * Отдельным классом, а не условием в обработчике: правило одно на оба
 * обработчика, а последствие у него тяжёлое — подключение переводится
 * в broken и синхронизация останавливается (ADR-007). Ошибиться здесь
 * значит либо остановить исправное подключение из-за сетевого сбоя,
 * либо не заметить отозванный ключ.
 *
 * 401 и 403, ничего больше. Лимит запросов (429), неверный период (400),
 * сбой площадки (5xx) лечатся повтором, а не переподключением кабинета,
 * и обязаны оставаться исключениями.
 */
final class OzonAuthorizationFailure
{
    private const array STATUS_CODES = [401, 403];

    private function __construct()
    {
    }

    public static function isAuthorizationFailure(\Throwable $failure): bool
    {
        if (!$failure instanceof HttpClientException) {
            return false;
        }

        // Код ответа достаётся из самого исключения symfony/http-client:
        // у ClientException он есть, у сетевых (TransportException) нет —
        // и это правильный ответ, сетевой сбой не повод объявлять ключ
        // отозванным.
        if (!method_exists($failure, 'getResponse')) {
            return false;
        }

        try {
            $status = $failure->getResponse()->getStatusCode();
        } catch (\Throwable) {
            return false;
        }

        return \in_array($status, self::STATUS_CODES, true);
    }
}
