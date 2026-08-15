<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Ingestion\Domain\OzonFeeTypeNames;
use App\Ingestion\Infrastructure\Query\ExpenseCoverageQuery;
use App\Ingestion\Infrastructure\Query\UnitEconomicsCursor;
use App\Ingestion\Infrastructure\Query\UnitEconomicsExpenseRow;
use App\Ingestion\Infrastructure\Query\UnitEconomicsQuery;
use App\Ingestion\Infrastructure\Query\UnitEconomicsSkuRow;
use App\Shared\Domain\ValueObject\Money;

/**
 * Сборка юнит-экономики за период.
 *
 * Товары приходят одной страницей из запроса, где продажи и расходы уже
 * объединены по артикулу: собирать объединение в PHP значило бы, что
 * артикул с расходами, но без продаж, проходит мимо лимита страницы.
 *
 * Денежная арифметика — через Money (ADR-004): величины разных валют
 * не складываются, и проверка живёт в самом типе, а не в этом сценарии.
 */
final readonly class BuildUnitEconomicsAction
{
    public function __construct(
        private UnitEconomicsQuery $query,
        private ExpenseCoverageQuery $coverage,
    ) {
    }

    public function __invoke(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit,
        ?UnitEconomicsCursor $cursor,
    ): UnitEconomicsReport {
        /** @var list<array<string, mixed>> $skuRows */
        $skuRows = $this->query->skus($companyId, $from, $to, $limit, $cursor)->executeQuery()->fetchAllAssociative();

        $rows = array_map(UnitEconomicsQuery::mapSkuRow(...), $skuRows);
        // +1 строка запрошена ради ответа на «есть ли ещё» — в отчёт
        // она не идёт (§5: COUNT(*) на факт-таблицах не выполняется).
        $hasMore = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);

        /** @var list<array<string, mixed>> $cabinetRows */
        $cabinetRows = $this->query->cabinetExpenses($companyId, $from, $to)->executeQuery()->fetchAllAssociative();
        $cabinet = array_map(UnitEconomicsQuery::mapExpenseRow(...), $cabinetRows);

        $breakdown = $this->breakdown($companyId, $from, $to, $rows);
        $currency = $this->singleCurrency($rows, $cabinet);

        $skus = [];
        foreach ($rows as $row) {
            $skus[] = $this->sku($row, $breakdown[$row->marketplaceSku] ?? []);
        }

        $last = $rows[\count($rows) - 1] ?? null;

        return new UnitEconomicsReport(
            skus: $skus,
            cabinetExpenses: $this->grouped($cabinet),
            cabinetExpensesTotalMinor: $this->total($cabinet),
            currency: $currency,
            // Покрытие считается по всему окну, а не по странице: дыра
            // в расходах — свойство периода, и на второй странице она
            // не должна исчезать.
            daysWithoutExpenses: $this->daysWithoutExpenses($companyId, $from, $to),
            nextCursor: $hasMore && null !== $last
                ? (new UnitEconomicsCursor($last->deliveredAmountMinor, $last->marketplaceSku))->toString()
                : null,
        );
    }

    private function daysWithoutExpenses(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $days = $this->coverage->daysWithoutExpenses($companyId, $from, $to)->executeQuery()->fetchOne();

        // COUNT в PostgreSQL — bigint, и DBAL отдаёт его строкой:
        // приводим явно, тем же приёмом, что UnitEconomicsQuery::intValue.
        if (\is_int($days)) {
            return $days;
        }

        if (\is_string($days) && 1 === preg_match('/^\d+$/', $days)) {
            return (int) $days;
        }

        throw new \UnexpectedValueException('Expected an integer count of days without expenses.');
    }

    /**
     * Разбивка по типам — одним запросом на всю страницу, не запросом
     * на артикул (CLAUDE.md §6: запросов в цикле нет).
     *
     * @param list<UnitEconomicsSkuRow> $rows
     *
     * @return array<string, list<UnitEconomicsExpenseRow>>
     */
    private function breakdown(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, array $rows): array
    {
        if ([] === $rows) {
            return [];
        }

        $skus = array_map(static fn (UnitEconomicsSkuRow $row): string => $row->marketplaceSku, $rows);

        /** @var list<array<string, mixed>> $expenseRows */
        $expenseRows = $this->query->breakdown($companyId, $from, $to, $skus)->executeQuery()->fetchAllAssociative();

        $bySku = [];
        foreach (array_map(UnitEconomicsQuery::mapExpenseRow(...), $expenseRows) as $expense) {
            $bySku[$expense->marketplaceSku][] = $expense;
        }

        return $bySku;
    }

    /**
     * @param list<UnitEconomicsExpenseRow> $expenses
     */
    private function sku(UnitEconomicsSkuRow $row, array $expenses): UnitEconomicsSku
    {
        $revenue = Money::ofMinor($row->deliveredAmountMinor, $row->currency);
        $commission = Money::ofMinor($row->commissionAmountMinor, $row->currency);
        $expensesTotal = Money::ofMinor($row->expensesTotalMinor, $row->currency);

        // Сложение, а не вычитание: комиссия и расходы приходят
        // от площадки отрицательными, и «вычесть расход» означало бы
        // гадать, каким знаком он пришёл.
        $deductions = $commission->plus($expensesTotal);

        return new UnitEconomicsSku(
            marketplaceSku: $row->marketplaceSku,
            deliveredQuantity: $row->deliveredQuantity,
            orderedQuantity: $row->orderedQuantity,
            revenueMinor: $revenue->minorAmount(),
            commissionMinor: $commission->minorAmount(),
            expenses: $this->grouped($expenses),
            expensesTotalMinor: $expensesTotal->minorAmount(),
            deductionsTotalMinor: $deductions->minorAmount(),
            marginMinor: $revenue->plus($deductions)->minorAmount(),
        );
    }

    /**
     * @param list<UnitEconomicsExpenseRow> $expenses
     *
     * @return list<UnitEconomicsExpense>
     */
    private function grouped(array $expenses): array
    {
        /** @var array<int, Money> $byType */
        $byType = [];
        foreach ($expenses as $expense) {
            $amount = Money::ofMinor($expense->amountMinor, $expense->currency);
            $byType[$expense->feeTypeId] = isset($byType[$expense->feeTypeId])
                ? $byType[$expense->feeTypeId]->plus($amount)
                : $amount;
        }

        // По возрастанию суммы: расходы отрицательные, поэтому первым
        // в списке оказывается тот, что съедает больше всего, — ради
        // него экран и открывают.
        uasort($byType, static fn (Money $a, Money $b): int => $a->minorAmount() <=> $b->minorAmount());

        $grouped = [];
        foreach ($byType as $feeTypeId => $amount) {
            $grouped[] = new UnitEconomicsExpense(
                feeTypeId: $feeTypeId,
                name: OzonFeeTypeNames::of($feeTypeId),
                amountMinor: $amount->minorAmount(),
            );
        }

        return $grouped;
    }

    /**
     * @param list<UnitEconomicsExpenseRow> $expenses
     */
    private function total(array $expenses): int
    {
        if ([] === $expenses) {
            return 0;
        }

        return Money::sum(array_map(
            static fn (UnitEconomicsExpenseRow $row): Money => Money::ofMinor($row->amountMinor, $row->currency),
            $expenses,
        ))->minorAmount();
    }

    /**
     * @param list<UnitEconomicsSkuRow>     $rows
     * @param list<UnitEconomicsExpenseRow> $cabinet
     */
    private function singleCurrency(array $rows, array $cabinet): string
    {
        $currencies = array_unique(array_merge(
            array_map(static fn (UnitEconomicsSkuRow $row): string => $row->currency, $rows),
            array_map(static fn (UnitEconomicsExpenseRow $row): string => $row->currency, $cabinet),
        ));

        if (\count($currencies) > 1) {
            // Money бросил бы то же исключение на первом сложении;
            // здесь оно раньше и с понятным сообщением, потому что
            // одна валюта — свойство отчёта целиком, а не одной
            // операции (ADR-004).
            throw new \RuntimeException('За период встретилось несколько валют — отчёт не складывает их в одну сумму (ADR-004).');
        }

        return $currencies[array_key_first($currencies)] ?? 'RUB';
    }
}
