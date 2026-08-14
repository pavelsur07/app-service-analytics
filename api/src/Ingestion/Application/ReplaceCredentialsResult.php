<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

/**
 * Исходы замены ключей, которые контроллер обязан различать по-разному:
 * у каждого свой ответ и своё следующее действие клиента.
 */
enum ReplaceCredentialsResult
{
    case Replaced;
    /** Площадка не приняла ключ — выпустить новый и повторить. */
    case Rejected;
    /** Ключ от другого кабинета — проверить, тот ли магазин. */
    case WrongCabinet;
    case NotFound;
    /** Отзыв необратим (ADR-011). */
    case Revoked;
    /** Данные изменил кто-то ещё (ADR-008) — перечитать и повторить. */
    case VersionConflict;
}
