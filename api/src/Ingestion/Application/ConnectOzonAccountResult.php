<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

/**
 * Исходы подключения кабинета, которые контроллер обязан различать
 * по-разному: у каждого свой ответ и своё следующее действие клиента
 * (ADR-021).
 */
enum ConnectOzonAccountResult
{
    case Connected;
    /** Площадка не приняла ключ — проверить пару и тип ключа. */
    case Rejected;
    /** Кабинет уже подключён к этой или другой компании. */
    case AlreadyConnected;
    /** Площадка не ответила — подождать, а не выпускать новый ключ. */
    case Unavailable;
}
