<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Ingestion\Domain\OzonFeeTypeNames;
use App\Ingestion\Infrastructure\Query\UnitEconomicsExpenseRow;
use App\Ingestion\Infrastructure\Query\UnitEconomicsQuery;
use App\Ingestion\Infrastructure\Query\UnitEconomicsSalesRow;

/**
 * Сборка юнит-экономики за период из двух агрегатов: продаж и расходов.
 *
 * Складывает их PHP, а не JOIN в SQL, и это осознанно: у продажи и её
 * расходов разные бизнес-даты по природе — начисление приходит позже,
 * иногда на недели (ADR-012). Соединение по дате приписывало бы июльской
 * продаже только те расходы, что успели начислиться в июле, и картина
 * менялась бы при каждом открытии экрана.
 *
 * Одна валюта на отчёт: суммы разных валют не складываются (§3).
 * Сегодня у Ozon это всегда RUB; вторая валюта в периоде — отказ,
 * а не молчаливое суммирование.
 */
final readonly class BuildUnitEconomicsAction
{
    public function __construct(
        private UnitEconomicsQuery $query,
    ) {
    }

    public function __invoke(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): UnitEconomicsReport
    {
        /** @var list<array<string, mixed>> $salesRows */
        $salesRows = $this->query->sales($companyId, $from, $to)->executeQuery()->fetchAllAssociative();
        if (\count($salesRows) > UnitEconomicsQuery::MAX_ROWS) {
            // Тот же приём, что в IdentityScheduleFacade: тихая обрезка
            // показала бы неполный отчёт как полный, и клиент считал бы
            // прибыль по части ассортимента.
            throw new \RuntimeException(\sprintf('Артикулов с продажами за период больше защитного потолка %d — экрану нужна пагинация.', UnitEconomicsQuery::MAX_ROWS));
        }

        /** @var list<array<string, mixed>> $expenseRows */
        $expenseRows = $this->query->expenses($companyId, $from, $to)->executeQuery()->fetchAllAssociative();

        $sales = array_map(UnitEconomicsQuery::mapSalesRow(...), $salesRows);
        $expenses = array_map(UnitEconomicsQuery::mapExpenseRow(...), $expenseRows);

        $currency = $this->singleCurrency($sales, $expenses);

        /**
         * Ключ — артикул, но PHP приводит числовые строки к int:
         * '222' в ключе становится 222, и обратное приведение ниже
         * не косметика, а необходимость. PHPStan этого не видит
         * и считает приведение лишним — он неправ.
         *
         * @var array<array-key, list<UnitEconomicsExpenseRow>> $bySku
         */
        $bySku = [];
        $cabinet = [];
        foreach ($expenses as $expense) {
            if ('' === $expense->marketplaceSku) {
                $cabinet[] = $expense;

                continue;
            }
            $bySku[$expense->marketplaceSku][] = $expense;
        }

        $skus = [];
        foreach ($sales as $row) {
            $skuExpenses = $bySku[$row->marketplaceSku] ?? [];
            unset($bySku[$row->marketplaceSku]);
            $skus[] = $this->sku($row, $skuExpenses);
        }

        // Расходы по артикулу, у которого за период не было продаж:
        // возврат обработан в июле, а продан товар в июне. Спрятать их
        // значило бы занизить издержки, и товар с одними расходами
        // на экране обязан быть виден.
        foreach ($bySku as $marketplaceSku => $skuExpenses) {
            $skus[] = $this->sku(
                new UnitEconomicsSalesRow(
                    marketplaceSku: (string) $marketplaceSku,
                    currency: $currency,
                    deliveredQuantity: 0,
                    deliveredAmountMinor: 0,
                    commissionAmountMinor: 0,
                    orderedQuantity: 0,
                ),
                $skuExpenses,
            );
        }

        return new UnitEconomicsReport(
            skus: $skus,
            cabinetExpenses: $this->grouped($cabinet),
            cabinetExpensesTotalMinor: $this->total($cabinet),
            currency: $currency,
        );
    }

    /**
     * @param list<UnitEconomicsExpenseRow> $expenses
     */
    private function sku(UnitEconomicsSalesRow $row, array $expenses): UnitEconomicsSku
    {
        $expensesTotal = $this->total($expenses);

        return new UnitEconomicsSku(
            marketplaceSku: $row->marketplaceSku,
            deliveredQuantity: $row->deliveredQuantity,
            orderedQuantity: $row->orderedQuantity,
            revenueMinor: $row->deliveredAmountMinor,
            commissionMinor: $row->commissionAmountMinor,
            expenses: $this->grouped($expenses),
            expensesTotalMinor: $expensesTotal,
            // Сложение, а не вычитание: комиссия и расходы приходят
            // от площадки отрицательными.
            marginMinor: $row->deliveredAmountMinor + $row->commissionAmountMinor + $expensesTotal,
        );
    }

    /**
     * @param list<UnitEconomicsExpenseRow> $expenses
     *
     * @return list<UnitEconomicsExpense>
     */
    private function grouped(array $expenses): array
    {
        $byType = [];
        foreach ($expenses as $expense) {
            $byType[$expense->feeTypeId] = ($byType[$expense->feeTypeId] ?? 0) + $expense->amountMinor;
        }

        // По убыванию величины расхода: первым в списке то, что съедает
        // больше всего, — ради этого экран и открывают.
        asort($byType);

        $grouped = [];
        foreach ($byType as $feeTypeId => $amountMinor) {
            $grouped[] = new UnitEconomicsExpense(
                feeTypeId: $feeTypeId,
                name: OzonFeeTypeNames::of($feeTypeId),
                amountMinor: $amountMinor,
            );
        }

        return $grouped;
    }

    /**
     * @param list<UnitEconomicsExpenseRow> $expenses
     */
    private function total(array $expenses): int
    {
        $total = 0;
        foreach ($expenses as $expense) {
            $total += $expense->amountMinor;
        }

        return $total;
    }

    /**
     * @param list<UnitEconomicsSalesRow>   $sales
     * @param list<UnitEconomicsExpenseRow> $expenses
     */
    private function singleCurrency(array $sales, array $expenses): string
    {
        $currencies = array_unique(array_merge(
            array_map(static fn (UnitEconomicsSalesRow $row): string => $row->currency, $sales),
            array_map(static fn (UnitEconomicsExpenseRow $row): string => $row->currency, $expenses),
        ));

        if (\count($currencies) > 1) {
            // Молчаливое приведение по курсу запрещено (ADR-004),
            // а сложить разные валюты в одну строку отчёта — то же самое,
            // только без курса.
            throw new \RuntimeException('За период встретилось несколько валют — отчёт не складывает их в одну сумму (ADR-004).');
        }

        return $currencies[array_key_first($currencies)] ?? 'RUB';
    }
}
