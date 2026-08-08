<?php

declare(strict_types=1);

namespace App\Identity\Domain\ValueObject;

/**
 * Дискриминатор в уникальном ключе MarketplaceAccount (ADR-002).
 * Второй case добавляется вместе со вторым коннектором, не раньше.
 */
enum Marketplace: string
{
    case Ozon = 'ozon';
}
