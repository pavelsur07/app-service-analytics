<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/**
 * Строка результата ListingCostsQuery (CLAUDE.md §5: результат
 * DBAL-запроса маппится в readonly DTO, а не разъезжается массивами).
 *
 * Поля себестоимости nullable все разом: у карточки её либо нет вовсе,
 * либо есть целиком — с идентификатором, суммой, валютой, датой начала
 * и версией. Половины не бывает, и «сумма есть, версии нет» означало бы
 * ошибку запроса, а не состояние данных.
 */
final readonly class ListingCostRow
{
    public function __construct(
        public string $marketplaceSku,
        public string $marketplaceAccountId,
        public ?string $offerId,
        public ?string $name,
        public int $revenueMinor,
        public int $deliveredQuantity,
        public ?string $costId,
        public ?int $unitCostMinor,
        public ?string $costCurrency,
        public ?string $costEffectiveFrom,
        public ?int $costVersion,
        /** Продано штук с даты, с которой действует эта цена. */
        public ?int $deliveredSinceCost,
    ) {
    }
}
