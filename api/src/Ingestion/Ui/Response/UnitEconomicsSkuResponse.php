<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

/**
 * Экономика одного артикула за период.
 *
 * marginMinor — «сколько осталось от продажи после расходов площадки».
 * profitMinor — прибыль, то есть маржа за вычетом себестоимости
 * проданного. Это разные величины, и обе остаются в ответе: первая
 * отвечает на «сколько забрала площадка», вторая — на «сколько
 * заработали».
 *
 * profitMinor равен null, когда цена задана не на все проданные дни.
 * Ноль вместо неизвестной себестоимости завысил бы прибыль ровно
 * на её величину и выглядел бы при этом настоящей цифрой (ADR-013).
 */
final readonly class UnitEconomicsSkuResponse
{
    /**
     * @param list<UnitEconomicsExpenseResponse> $expenses
     */
    public function __construct(
        public string $marketplaceSku,
        /**
         * Название карточки и артикул селлера. null, пока каталог
         * не подтянулся: артикул площадки встречается в фактах раньше,
         * чем карточка, и строка расчёта из-за этого не теряется.
         */
        public ?string $name,
        public ?string $offerId,
        public int $deliveredQuantity,
        public int $orderedQuantity,
        public int $revenueMinor,
        public int $commissionMinor,
        public array $expenses,
        public int $expensesTotalMinor,
        /** Комиссия плюс расходы: считает бэкенд, не компонент (§10). */
        public int $deductionsTotalMinor,
        public int $marginMinor,
        /** Себестоимость проданного, отрицательная — как и прочие вычеты. */
        public int $costTotalMinor,
        /** Сколько проданных штук пришлось на дни без заданной цены. */
        public int $quantityWithoutCost,
        public ?int $profitMinor,
        /**
         * Когда себестоимость этого периода правили. Прибыль считается
         * при чтении, поэтому отчёт за прошлый месяц может измениться
         * между двумя открытиями — и это называется, а не молчится.
         */
        public ?string $costCorrectedAt,
    ) {
    }
}
