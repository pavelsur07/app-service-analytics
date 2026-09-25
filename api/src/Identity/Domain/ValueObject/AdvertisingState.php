<?php

declare(strict_types=1);

namespace App\Identity\Domain\ValueObject;

/**
 * Состояние рекламного ключа подключения (ADR-026, п. 1). Отсутствие
 * значения (NULL в колонке) — реклама не подключена.
 *
 * Отдельно от MarketplaceAccountState намеренно: отказ рекламного ключа
 * ломает только рекламу, продажи и расходы подключения продолжают
 * грузиться. `revoked` здесь нет — отзывается подключение целиком,
 * и его `state` главный: у отозванного подключения реклама не грузится
 * независимо от этого значения.
 */
enum AdvertisingState: string
{
    case Active = 'active';
    case Broken = 'broken';
}
