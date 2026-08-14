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
     * Сложение — единственная арифметическая операция, которая нужна
     * сегодня: расходы и комиссии складываются с выручкой при расчёте
     * экономики товара. Вычитания нет намеренно: величины приходят
     * от площадки со своим знаком, и «вычесть расход» означало бы
     * гадать, положительным он пришёл или отрицательным.
     *
     * Разные валюты — исключение, а не приведение по курсу (ADR-004).
     * Проверка живёт здесь, а не у вызывающего: правило одно на весь
     * проект, и повторять его в каждом расчёте значит однажды забыть.
     */
    public function plus(self $other): self
    {
        if ($this->currency() !== $other->currency()) {
            throw new \InvalidArgumentException(\sprintf('Cannot add %s to %s: money of different currencies is never summed (ADR-004).', $other->currency(), $this->currency()));
        }

        return new self($this->money->plus($other->money));
    }

    /**
     * Сумма списка величин одной валюты. Пустой список сложить нельзя:
     * валюта результата неизвестна, а выбирать её за вызывающего —
     * ровно то умолчание, которого ADR-004 не допускает.
     *
     * @param list<self> $values
     */
    public static function sum(array $values): self
    {
        $total = $values[0] ?? throw new \InvalidArgumentException('Cannot sum an empty list of money: the currency of the result would be a guess (ADR-004).');

        foreach (\array_slice($values, 1) as $value) {
            $total = $total->plus($value);
        }

        return $total;
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
