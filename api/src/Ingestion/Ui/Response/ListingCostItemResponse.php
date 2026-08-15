<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

/**
 * Карточка в списке ввода себестоимости.
 *
 * Поля цены nullable все разом: у карточки она либо есть целиком —
 * с идентификатором, суммой, валютой, датой начала действия и версией, —
 * либо её нет вовсе. Версия здесь не украшение: без неё экран не сможет
 * прислать исправление (ADR-008).
 */
final readonly class ListingCostItemResponse
{
    public function __construct(
        public string $marketplaceSku,
        public string $marketplaceAccountId,
        /** Артикул продавца — то, как товар называет он сам. */
        public ?string $offerId,
        public ?string $name,
        public int $revenueMinor,
        public int $deliveredQuantity,
        public ?string $costId,
        public ?int $unitCostMinor,
        public ?string $costCurrency,
        public ?string $costEffectiveFrom,
        public ?int $costVersion,
        /**
         * Продано штук с даты действия цены — число для предупреждения
         * перед исправлением: оно затронет столько-то проданных единиц.
         */
        public ?int $deliveredSinceCost,
    ) {
    }
}
