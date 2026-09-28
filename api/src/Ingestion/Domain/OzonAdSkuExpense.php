<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use App\Shared\Domain\ValueObject\Money;

/**
 * Строка SKU-отчёта рекламы: расход одной кампании на один SKU за день
 * (ADR-035 п. 1), как его отдала площадка.
 *
 * Сумма уже со знаком расхода — отрицательная, как у всех расходов
 * площадки: экран складывает, а не вычитает (BuildUnitEconomicsAction).
 */
final readonly class OzonAdSkuExpense
{
    public function __construct(
        public string $campaignId,
        public \DateTimeImmutable $businessDate,
        public string $marketplaceSku,
        public Money $amount,
    ) {
    }

    /**
     * Площадка отдаёт расход положительным; в факт он идёт со знаком
     * расхода. Вычитание из нуля — через Money, не через минорные
     * единицы руками.
     */
    public static function spent(string $campaignId, \DateTimeImmutable $businessDate, string $marketplaceSku, Money $spent): self
    {
        return new self($campaignId, $businessDate, $marketplaceSku, Money::ofMinor(0, $spent->currency())->minus($spent));
    }
}
