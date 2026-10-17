<?php

declare(strict_types=1);

namespace App\SalesPlanning\Domain;

/**
 * Статус версии плана (ADR-040 п. 8).
 *
 * Правится только `draft`. Остальные статусы конечные для своей версии:
 * шапки не удаляются никогда, и отказ от черновика — `discarded`,
 * а не удаление строк, иначе ручные правки дней пропали бы
 * без следа (ADR-011).
 */
enum SalesPlanStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Superseded = 'superseded';
    case Discarded = 'discarded';
    case Cancelled = 'cancelled';
}
