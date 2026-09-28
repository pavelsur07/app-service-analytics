<?php

declare(strict_types=1);

namespace App\Ingestion\Application\UnitEconomics;

use App\Ingestion\Domain\OzonAccrualGroups;
use App\Ingestion\Domain\OzonFeeTypeNames;
use App\Ingestion\Infrastructure\Query\UnitEconomics\AccrualCategoriesQuery;
use App\Ingestion\Infrastructure\Query\UnitEconomics\AccrualCategoryRow;
use App\Ingestion\Infrastructure\Query\UnitEconomics\ExpenseCoverageQuery;
use App\Shared\Domain\ValueObject\Money;

/**
 * Сверка с кабинетом: начисления Ozon за период по группам и статьям,
 * как отчёт «Начисления», без разбивки по товарам.
 *
 * Итог — «итого к начислению»: сумма всех строк ленты за период, ровно
 * то число, что клиент видит в выгрузке кабинета. Сходится оно — лента
 * загружена полностью (ADR-036); не сходится — искать запоздавшие
 * начисления или день без загрузки.
 *
 * Денежная арифметика — через Money (ADR-004): величины разных валют
 * не складываются, и проверка живёт в типе.
 */
final readonly class BuildAccrualReconciliationAction
{
    public function __construct(
        private AccrualCategoriesQuery $query,
        private ExpenseCoverageQuery $coverage,
    ) {
    }

    public function __invoke(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): AccrualReconciliation
    {
        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $this->query->byType($companyId, $from, $to)->executeQuery()->fetchAllAssociative();
        $rows = array_map(AccrualCategoriesQuery::mapRow(...), $rawRows);

        $currencies = array_values(array_unique(array_map(static fn (AccrualCategoryRow $row): string => $row->currency, $rows)));
        if (\count($currencies) > 1) {
            // Одна валюта — свойство сверки целиком: итог к начислению
            // из двух валют не складывается (ADR-004).
            throw new \RuntimeException('За период встретилось несколько валют — сверка не складывает их в одну сумму (ADR-004).');
        }

        /** @var array<string, list<AccrualReconciliationItem>> $itemsByGroup */
        $itemsByGroup = [];
        foreach ($rows as $row) {
            $group = OzonAccrualGroups::of($row->feeTypeId, $row->negativeRevenue);
            $income = $row->amountMinor > 0;
            $itemsByGroup[$group][] = new AccrualReconciliationItem(
                feeTypeId: $row->feeTypeId,
                // Как в кабинете: отрицательная выручка — «Возврат выручки».
                name: $row->negativeRevenue ? 'Возврат выручки' : OzonFeeTypeNames::of($row->feeTypeId),
                amountMinor: $row->amountMinor,
                accruedMinor: $income ? $row->positiveMinor : $row->negativeMinor,
                reversedMinor: $income ? $row->negativeMinor : $row->positiveMinor,
            );
        }

        $groups = [];
        $totals = [];
        foreach (OzonAccrualGroups::LABELS as $code => $label) {
            $items = $itemsByGroup[$code] ?? [];
            if ([] === $items) {
                continue;
            }

            // Крупная статья первой — ради неё группу и раскрывают.
            usort($items, static fn (AccrualReconciliationItem $a, AccrualReconciliationItem $b): int => [abs($b->amountMinor), $a->feeTypeId] <=> [abs($a->amountMinor), $b->feeTypeId]);

            $currency = $currencies[0] ?? throw new \LogicException('Rows without a currency cannot form a group.');
            $total = Money::sum(array_map(static fn (AccrualReconciliationItem $item): Money => Money::ofMinor($item->amountMinor, $currency), $items));
            $totals[] = $total;

            $groups[] = new AccrualReconciliationGroup(
                code: $code,
                label: $label,
                totalMinor: $total->minorAmount(),
                items: $items,
            );
        }

        return new AccrualReconciliation(
            currency: $currencies[0] ?? null,
            groups: $groups,
            // Пустой период — ноль без валюты: выбирать её за клиента
            // значило бы то самое умолчание, которого ADR-004 не допускает.
            totalMinor: [] === $totals ? 0 : Money::sum($totals)->minorAmount(),
            daysWithoutAccruals: $this->daysWithoutAccruals($companyId, $from, $to),
        );
    }

    private function daysWithoutAccruals(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $days = $this->coverage->daysWithoutExpenses($companyId, $from, $to)->executeQuery()->fetchOne();

        if (\is_int($days)) {
            return $days;
        }

        if (\is_string($days) && 1 === preg_match('/^\d+$/', $days)) {
            return (int) $days;
        }

        throw new \UnexpectedValueException('Expected an integer count of days without accruals.');
    }
}
