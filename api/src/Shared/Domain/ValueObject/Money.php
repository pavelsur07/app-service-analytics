<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use Brick\Money\AllocationMode;
use Brick\Money\Money as BrickMoney;

/**
 * Сумма в минорных единицах + код валюты ISO 4217. Конструктор не принимает
 * float — только целые минорные единицы. brick/money — внутренняя реализация,
 * наружу в сигнатуры не выходит. Подробнее — ADR-004.
 */
final readonly class Money
{
    private function __construct(
        private BrickMoney $money,
    ) {
    }

    public static function ofMinor(int $minorAmount, string $currency): self
    {
        return new self(BrickMoney::ofMinor($minorAmount, $currency));
    }

    public function currency(): string
    {
        return $this->money->getCurrency()->getCurrencyCode();
    }

    public function minorAmount(): int
    {
        return $this->money->getMinorAmount()->toInt();
    }

    /**
     * Делит сумму по $ratios с распределением остатка по $rule.
     * Сумма частей всегда равна исходной величине.
     *
     * @param list<int> $ratios
     *
     * @return list<self>
     */
    public function allocate(array $ratios, AllocationRule $rule): array
    {
        $mode = match ($rule) {
            AllocationRule::RemainderToFirst => AllocationMode::FloorToFirst,
        };

        return array_map(
            static fn (BrickMoney $part): self => new self($part),
            $this->money->allocate($ratios, $mode),
        );
    }
}
