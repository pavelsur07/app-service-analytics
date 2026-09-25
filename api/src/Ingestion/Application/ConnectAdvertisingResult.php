<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

/**
 * Исходы ввода рекламного ключа (ADR-026, п. 1). Каждому — свой ответ
 * клиенту и своё действие с его стороны.
 */
enum ConnectAdvertisingResult
{
    case Connected;
    /** Площадка не приняла client_id/client_secret — выпустить ключ заново. */
    case Rejected;
    /** Товары рекламных кампаний не нашлись в каталоге этого подключения. */
    case WrongCabinet;
    /** Площадка не ответила — повторить позже, ключ выпускать не нужно. */
    case Unavailable;
    case NotFound;
    /** Отзыв необратим (ADR-011). */
    case Revoked;
    /** Данные изменил кто-то ещё (ADR-008) — перечитать и повторить. */
    case VersionConflict;
}
