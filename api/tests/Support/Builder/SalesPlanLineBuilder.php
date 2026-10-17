<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\SalesPlanning\Domain\EvenDayDistribution;
use App\SalesPlanning\Domain\SalesPlan;
use App\SalesPlanning\Domain\SalesPlanLine;
use App\Shared\Domain\ValueObject\Money;

/**
 * Строки одного SKU на весь период плана — единственный способ, которым
 * домен их создаёт (ADR-040 п. 7). ADR-005: неизменяем, валидные
 * умолчания; штуки и цену задаёт тест — это то, что он проверяет.
 *
 * План обязателен: строка без плана не существует.
 */
final class SalesPlanLineBuilder
{
    private string $marketplaceSku = '1988146647';
    private int $totalQuantity = 31;
    private Money $price;
    private \DateTimeImmutable $changedAt;

    private function __construct(
        private readonly SalesPlan $plan,
    ) {
        $this->price = Money::ofMinor(129_900, $plan->currency());
        $this->changedAt = new \DateTimeImmutable('2026-06-25 10:05:00');
    }

    public static function linesOf(SalesPlan $plan): self
    {
        return new self($plan);
    }

    public function withMarketplaceSku(string $marketplaceSku): self
    {
        $clone = clone $this;
        $clone->marketplaceSku = $marketplaceSku;

        return $clone;
    }

    public function withTotalQuantity(int $totalQuantity): self
    {
        $clone = clone $this;
        $clone->totalQuantity = $totalQuantity;

        return $clone;
    }

    public function withPrice(Money $price): self
    {
        $clone = clone $this;
        $clone->price = $price;

        return $clone;
    }

    public function withChangedAt(\DateTimeImmutable $changedAt): self
    {
        $clone = clone $this;
        $clone->changedAt = $changedAt;

        return $clone;
    }

    /**
     * @return list<SalesPlanLine>
     */
    public function build(): array
    {
        return SalesPlanLine::spread(
            $this->plan,
            $this->marketplaceSku,
            $this->totalQuantity,
            $this->price,
            new EvenDayDistribution(),
            $this->changedAt,
        );
    }
}
