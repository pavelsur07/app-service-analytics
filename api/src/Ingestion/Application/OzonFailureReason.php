<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

/**
 * Причина отказа выгрузки для отметки дня (ADR-038): текст исключения
 * и тело ответа площадки.
 *
 * Инцидент, ради которого класс появился: Ozon двое суток отвечал 400
 * на `/v2/posting/fbo/list` по всем кабинетам и дням, а в отметке, трекере
 * и журнале лежало одно `HTTP/2 400 returned for …` — что площадке
 * не понравилось, сказать было нечем. Причину 400 Ozon пишет в теле.
 *
 * Ключ вычищается из тела до усечения — по той же причине, что
 * в `OzonAccountBrokenLogger`: площадка может отразить параметры запроса
 * в сообщении об ошибке, а усечение первым оставило бы часть ключа.
 */
final class OzonFailureReason
{
    private const string BODY_SEPARATOR = ' Тело ответа: ';

    private function __construct()
    {
    }

    public static function of(\Throwable $failure, string $apiKey): string
    {
        $reason = $failure->getMessage();
        $body = self::bodyOf($failure);
        if (null === $body || '' === trim($body)) {
            return $reason;
        }

        if ('' !== $apiKey) {
            $body = str_replace($apiKey, '[redacted]', $body);
        }

        return $reason.self::BODY_SEPARATOR.trim($body);
    }

    private static function bodyOf(\Throwable $failure): ?string
    {
        // method_exists(), не instanceof: getResponse() есть у исключений
        // ответа http-client, но не в общем интерфейсе исключений —
        // тот же приём, что в OzonAuthorizationFailure.
        if (!method_exists($failure, 'getResponse')) {
            return null;
        }

        try {
            // false — тело при любом коде; без него getContent() бросает
            // то же исключение повторно на 4xx/5xx.
            $body = $failure->getResponse()->getContent(false);
        } catch (\Throwable) {
            return null;
        }

        return \is_string($body) ? $body : null;
    }
}
