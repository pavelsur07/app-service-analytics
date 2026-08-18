<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Shared\Domain\ValueObject\Money;

/**
 * Строка выборки снимков каталога (ADR-016). readonly DTO, не сущность:
 * список читается DBAL без гидрации (CLAUDE.md §5).
 */
final readonly class ListingSnapshotRow
{
    public function __construct(
        public string $marketplaceSku,
        public ?string $name,
        public ?int $priceMinor,
        public ?string $currency,
    ) {
    }

    /**
     * null, если на момент наблюдения цены ещё не знали: история
     * начинается с выкладки ADR-015. Ноль вместо null означал бы,
     * что товар отдавали даром.
     */
    public function price(): ?Money
    {
        if (null === $this->priceMinor || null === $this->currency) {
            return null;
        }

        return Money::ofMinor($this->priceMinor, $this->currency);
    }
}
