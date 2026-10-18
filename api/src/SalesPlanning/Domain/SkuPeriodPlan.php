<?php

declare(strict_types=1);

namespace App\SalesPlanning\Domain;

use App\Shared\Domain\ValueObject\Money;

/**
 * План одного SKU на весь период: по дню на каждый день периода
 * (ADR-040 п. 3, 7). Строки плотные — день с нулём штук тоже день.
 */
final readonly class SkuPeriodPlan
{
    /**
     * Потолок штук SKU за период: день помещается в int колонки,
     * а выручка плана — в bigint (см. SalesPlanLine::MaxPriceMinor).
     */
    public const int MaxTotalQuantity = 1_000_000;

    /**
     * @param list<PlannedDay> $days по порядку дней периода
     */
    private function __construct(
        public string $marketplaceSku,
        public array $days,
    ) {
    }

    /**
     * Ввод SKU за период: штуки раскладываются по дням (п. 7), цена
     * копируется в каждый день — она не делится.
     */
    public static function spread(
        SalesPlan $plan,
        string $marketplaceSku,
        int $totalQuantity,
        Money $price,
        EvenDayDistribution $distribution,
    ): self {
        if ($totalQuantity > self::MaxTotalQuantity) {
            throw new \InvalidArgumentException(\sprintf('Штук за период больше %d — похоже на опечатку.', self::MaxTotalQuantity));
        }

        $sku = SalesPlanLine::validSku($marketplaceSku);
        $priceMinor = SalesPlanLine::validPriceMinor($plan, $price);
        $dates = $plan->days();
        $quantities = $distribution->split($totalQuantity, \count($dates));

        $days = [];
        foreach ($dates as $index => $date) {
            $days[] = new PlannedDay($date, $quantities[$index], $priceMinor);
        }

        return new self($sku, $days);
    }

    /**
     * Дни, уже лежащие в плане (из базы), — для журнала «было».
     *
     * @param list<PlannedDay> $days
     */
    public static function stored(string $marketplaceSku, array $days): self
    {
        usort($days, static fn (PlannedDay $a, PlannedDay $b): int => $a->date <=> $b->date);

        return new self($marketplaceSku, $days);
    }

    /**
     * Точные значения дней для аудит-журнала (ADR-040 п. 10): дата,
     * штуки, цена — день за днём, не итог периода: после ручных правок
     * итог прежнюю раскладку не определяет.
     */
    public function describe(SalesPlan $plan): string
    {
        return json_encode([
            'marketplaceSku' => $this->marketplaceSku,
            'currency' => $plan->currency(),
            'days' => array_map(
                static fn (PlannedDay $day): array => [$day->date->format('Y-m-d'), $day->quantity, $day->priceMinor],
                $this->days,
            ),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }
}
