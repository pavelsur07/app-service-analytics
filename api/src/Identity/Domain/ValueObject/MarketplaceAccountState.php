<?php

declare(strict_types=1);

namespace App\Identity\Domain\ValueObject;

/**
 * Жизненный цикл подключения (ADR-007): active -> broken -> revoked.
 * broken не удаляет данные и не считает историю неполной задним числом.
 */
enum MarketplaceAccountState: string
{
    case Active = 'active';
    case Broken = 'broken';
    case Revoked = 'revoked';
}
