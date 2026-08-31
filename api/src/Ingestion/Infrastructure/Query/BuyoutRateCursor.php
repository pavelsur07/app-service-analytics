<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/** Keyset position tied to one sort, direction and report window. */
final readonly class BuyoutRateCursor
{
    public function __construct(
        public BuyoutRateSort $sort,
        public BuyoutRateDirection $direction,
        public int $days,
        public ?int $sortValue,
        public string $marketplaceSku,
    ) {
    }

    public static function fromString(string $raw): ?self
    {
        $parts = explode(':', $raw, 5);
        if (5 !== \count($parts)) {
            return null;
        }

        [$sort, $direction, $days, $value, $sku] = $parts;
        if (
            1 !== preg_match('/^\d+$/', $days)
            || ('~' !== $value && 1 !== preg_match('/^\d+$/', $value))
            || '' === $sku
            || 1 !== preg_match('//u', $sku)
            || mb_strlen($sku, 'UTF-8') > 64
            || 1 === preg_match('/[\x00-\x1F\x7F]/u', $sku)
        ) {
            return null;
        }

        $sortCase = BuyoutRateSort::tryFrom($sort);
        $directionCase = BuyoutRateDirection::tryFrom($direction);
        if (null === $sortCase || null === $directionCase) {
            return null;
        }

        $sortValue = '~' === $value ? null : (int) $value;
        if (
            (BuyoutRateSort::Ordered === $sortCase && null === $sortValue)
            || (BuyoutRateSort::ActualBuyout === $sortCase && null !== $sortValue && $sortValue > 10000)
        ) {
            return null;
        }

        return new self(
            $sortCase,
            $directionCase,
            (int) $days,
            $sortValue,
            $sku,
        );
    }

    public function toString(): string
    {
        return implode(':', [
            $this->sort->value,
            $this->direction->value,
            (string) $this->days,
            null === $this->sortValue ? '~' : (string) $this->sortValue,
            $this->marketplaceSku,
        ]);
    }

    public function matches(BuyoutRateSort $sort, BuyoutRateDirection $direction, int $days): bool
    {
        return $this->sort === $sort
            && $this->direction === $direction
            && $this->days === $days;
    }

    /** Condition strictly after this row for "value direction NULLS LAST, SKU ASC". */
    public function after(string $alias = 'rate'): string
    {
        $column = $alias.'.'.$this->sort->column();
        $sku = $alias.'.marketplace_sku';
        if (null === $this->sortValue) {
            return "({$column} IS NULL AND {$sku} > :cursorSku)";
        }

        return \sprintf(
            '(%1$s IS NULL OR %1$s %2$s :cursorValue OR (%1$s = :cursorValue AND %3$s > :cursorSku))',
            $column,
            $this->direction->beyond(),
            $sku,
        );
    }
}
