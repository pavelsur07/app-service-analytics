<?php

declare(strict_types=1);

namespace App\Shared\Ui\Response;

/**
 * Единый формат ошибки (CLAUDE.md: «HTTP-статус + код + сообщение») —
 * в Shared, не в модуле: конвенция общая для всего API, не для одного
 * эндпоинта. Первый потребитель — Ingestion (пакет 6), следующий эндпоинт
 * переиспользует этот же класс, а не заводит свою копию.
 */
final readonly class ValidationErrorResponse
{
    public function __construct(
        public int $status,
        public string $code,
        public string $message,
    ) {
    }
}
