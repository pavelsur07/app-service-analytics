<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Dashboard;

use App\Ingestion\Application\Buyout\BuildBuyoutRateReportAction;
use App\Ingestion\Application\Buyout\BuyoutRateSummary;
use App\Ingestion\Domain\MarketplaceListingCost;
use App\Ingestion\Infrastructure\Query\Dashboard\DashboardDailyRow;
use App\Ingestion\Infrastructure\Query\Dashboard\DashboardLoadsQuery;
use App\Ingestion\Infrastructure\Query\Dashboard\DashboardMetricsQuery;
use App\Ingestion\Infrastructure\Query\Dashboard\DashboardProfitDailyRow;
use App\Ingestion\Infrastructure\Query\Dashboard\DashboardProfitQuery;
use App\Ingestion\Infrastructure\Query\Dashboard\DashboardProfitTotalsRow;
use App\Ingestion\Infrastructure\Query\Dashboard\DashboardTotalsRow;
use App\Ingestion\Infrastructure\Query\UnitEconomics\ExpenseCoverageQuery;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\DBAL\Connection;

/**
 * Сводка главной: показатели выбранного периода и периода сравнения,
 * подневные ряды, полнота данных и изменения
 * (docs/plan/dashboard-widgets.md).
 *
 * Выкуп не пересчитывается здесь заново, а берётся у отчёта выкупа:
 * когортный прогноз (ADR-029) — одна цифра на весь продукт, и главная,
 * разошедшаяся с экраном «Выкуп» на одном и том же периоде, была бы
 * хуже, чем главная без выкупа.
 */
final readonly class BuildDashboardSummaryAction
{
    public function __construct(
        private Connection $connection,
        private DashboardMetricsQuery $metrics,
        private DashboardLoadsQuery $loads,
        private ExpenseCoverageQuery $coverage,
        private BuildBuyoutRateReportAction $buyout,
        private DashboardProfitQuery $profitQuery,
    ) {
    }

    public function __invoke(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $compareFrom,
        \DateTimeImmutable $compareTo,
        \DateTimeImmutable $asOf,
    ): DashboardSummary {
        $read = fn (): DashboardSummary => $this->read($companyId, $from, $to, $compareFrom, $compareTo, $asOf);

        // Итоги, ряды, полнота и выкуп — из одного снимка: загрузка,
        // идущая параллельно, иначе дала бы в одном ответе итог одной
        // версии данных и ряд другой. Эндпоинт вызывает сценарий вне
        // транзакции и всегда получает снимок. Внутри уже открытой
        // транзакции (тесты под dama, вызывающий сценарий) уровень
        // изоляции выбирает её владелец: SET TRANSACTION после его
        // запросов PostgreSQL запрещает, и обещание снимка — за ним.
        $native = $this->connection->getNativeConnection();
        if ($this->connection->isTransactionActive() || ($native instanceof \PDO && $native->inTransaction())) {
            return $read();
        }

        return $this->connection->transactional(static function (Connection $connection) use ($read): DashboardSummary {
            $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $read();
        });
    }

    private function read(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $compareFrom,
        \DateTimeImmutable $compareTo,
        \DateTimeImmutable $asOf,
    ): DashboardSummary {
        $totals = array_map(
            DashboardMetricsQuery::mapTotalsRow(...),
            $this->fetchAll($this->metrics->totals($companyId, $from, $to, $compareFrom, $compareTo), DashboardMetricsQuery::MAX_TOTAL_ROWS),
        );
        $daily = array_map(
            DashboardMetricsQuery::mapDailyRow(...),
            $this->fetchAll($this->metrics->daily($companyId, $from, $to, $compareFrom, $compareTo), DashboardMetricsQuery::MAX_DAILY_ROWS),
        );
        $profitTotals = array_map(DashboardProfitQuery::mapTotalsRow(...),
            $this->fetchAll($this->profitQuery->totals($companyId, $from, $to, $compareFrom, $compareTo), DashboardProfitQuery::MAX_TOTAL_ROWS));
        $profitDaily = array_map(DashboardProfitQuery::mapDailyRow(...),
            $this->fetchAll($this->profitQuery->daily($companyId, $from, $to, $compareFrom, $compareTo), DashboardProfitQuery::MAX_DAILY_ROWS));
        $currency = self::singleCurrency($totals, $daily, $profitTotals, $profitDaily);

        $current = $this->period($companyId, $from, $to, $asOf, self::totalsOf($totals, true), $daily, self::profitTotalsOf($profitTotals, true), $profitDaily, $currency);
        $comparison = $this->period($companyId, $compareFrom, $compareTo, $asOf, self::totalsOf($totals, false), $daily, self::profitTotalsOf($profitTotals, false), $profitDaily, $currency);

        return new DashboardSummary(
            currency: $currency,
            current: $current,
            comparison: $comparison,
            changes: $current->dataComplete && $comparison->dataComplete
                ? self::changes($current->metrics, $comparison->metrics)
                : self::noChanges(),
        );
    }

    /**
     * @param list<DashboardDailyRow>       $daily
     * @param list<DashboardProfitDailyRow> $profitDaily
     */
    private function period(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $asOf,
        ?DashboardTotalsRow $totals,
        array $daily,
        ?DashboardProfitTotalsRow $profitTotals,
        array $profitDaily,
        string $currency,
    ): DashboardPeriodSummary {
        $coverage = $this->coverage->daysWithoutExpenses($companyId, $from, $to);
        $daysWithoutAccruals = self::int($this->connection->fetchOne($coverage->getSQL(), $coverage->getParameters(), $coverage->getParameterTypes()));
        $loads = $this->loads->complete($companyId, $from, $to);
        $loaded = DashboardLoadsQuery::mapComplete(
            $this->connection->fetchAssociative($loads->getSQL(), $loads->getParameters(), $loads->getParameterTypes()),
        );
        $buyout = ($this->buyout)($companyId, $from, $to, $asOf, 1)->summary;

        $complete = $loaded && 0 === $daysWithoutAccruals;
        $details = new DashboardProfitDetails(
            hasAccruals: null !== $profitTotals,
            accrualsTotalMinor: $profitTotals->accrualsTotalMinor ?? 0,
            costTotalMinor: $profitTotals->costTotalMinor ?? 0,
            quantityWithoutCost: $profitTotals->quantityWithoutCost ?? 0,
            costMethod: MarketplaceListingCost::MethodManual,
            costCorrectedAt: $profitTotals?->costCorrectedAt,
        );
        $profitKnown = $complete && 0 === $details->quantityWithoutCost;
        $profit = $profitKnown ? Money::ofMinor($details->accrualsTotalMinor, $currency)
            ->plus(Money::ofMinor($details->costTotalMinor, $currency))->minorAmount() : null;

        return new DashboardPeriodSummary(
            from: $from->format('Y-m-d'),
            to: $to->format('Y-m-d'),
            // Полон — каждое подключение загрузило оба источника на всём
            // периоде, и ни одного дня с продажами без начислений.
            dataComplete: $complete,
            daysWithoutAccruals: $daysWithoutAccruals,
            metrics: self::metrics($totals, $buyout, $profit),
            days: self::days($from, $to, $daily, $profitDaily, $profitKnown, $currency),
            profitDetails: $details,
        );
    }

    private static function metrics(?DashboardTotalsRow $row, BuyoutRateSummary $buyout, ?int $profit): DashboardMetrics
    {
        return new DashboardMetrics(
            ordersAmountMinor: $row->ordersAmountMinor ?? 0,
            ordersQuantity: $row->ordersQuantity ?? 0,
            salesAmountMinor: $row->salesAmountMinor ?? 0,
            salesQuantity: $row->salesQuantity ?? 0,
            returnsAmountMinor: $row->returnsAmountMinor ?? 0,
            returnsQuantity: $row->returnsQuantity ?? 0,
            advertisingMinor: $row->advertisingMinor ?? 0,
            costPerOrderMinor: $row?->costPerOrderMinor,
            drrOrdersBps: $row?->drrOrdersBps,
            costPerSaleMinor: $row?->costPerSaleMinor,
            drrSalesBps: $row?->drrSalesBps,
            deliveryLogisticsMinor: $row->deliveryLogisticsMinor ?? 0,
            deliveryCount: $row->deliveryCount ?? 0,
            returnLogisticsMinor: $row->returnLogisticsMinor ?? 0,
            returnLogisticsCount: $row->returnLogisticsCount ?? 0,
            buyoutRateBps: $buyout->projectedBuyoutRateBps,
            buyoutQuantity: $buyout->projectedBuyoutQuantity,
            buyoutOrderedQuantity: $buyout->orderedQuantity,
            buyoutResolutionRateBps: $buyout->resolutionRateBps,
            profitMinor: $profit,
        );
    }

    /**
     * Каждый день периода, включая дни без фактов, — иначе спарклайн
     * соединил бы соседние точки через пропуск и нарисовал рост там,
     * где был ноль.
     *
     * @param list<DashboardDailyRow>       $daily
     * @param list<DashboardProfitDailyRow> $profitDaily
     *
     * @return list<DashboardDay>
     */
    private static function days(\DateTimeImmutable $from, \DateTimeImmutable $to, array $daily, array $profitDaily, bool $profitKnown, string $currency): array
    {
        $byDate = [];
        foreach ($daily as $row) {
            $byDate[$row->date] = $row;
        }

        $profitByDate = [];
        foreach ($profitDaily as $row) {
            $profitByDate[$row->date] = $row;
        }
        $days = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $row = $byDate[$day->format('Y-m-d')] ?? null;
            $profitRow = $profitByDate[$day->format('Y-m-d')] ?? null;
            $days[] = new DashboardDay(
                date: $day->format('Y-m-d'),
                ordersAmountMinor: $row->ordersAmountMinor ?? 0,
                salesAmountMinor: $row->salesAmountMinor ?? 0,
                returnsAmountMinor: $row->returnsAmountMinor ?? 0,
                advertisingMinor: $row->advertisingMinor ?? 0,
                deliveryLogisticsMinor: $row->deliveryLogisticsMinor ?? 0,
                returnLogisticsMinor: $row->returnLogisticsMinor ?? 0,
                profitMinor: $profitKnown ? Money::ofMinor($profitRow->accrualsTotalMinor ?? 0, $currency)
                    ->plus(Money::ofMinor($profitRow->costTotalMinor ?? 0, $currency))->minorAmount() : null,
            );
        }

        return $days;
    }

    private static function changes(DashboardMetrics $current, DashboardMetrics $previous): DashboardChanges
    {
        return new DashboardChanges(
            ordersAmountBps: DashboardChange::relativeBps($current->ordersAmountMinor, $previous->ordersAmountMinor),
            ordersQuantityBps: DashboardChange::relativeBps($current->ordersQuantity, $previous->ordersQuantity),
            salesAmountBps: DashboardChange::relativeBps($current->salesAmountMinor, $previous->salesAmountMinor),
            salesQuantityBps: DashboardChange::relativeBps($current->salesQuantity, $previous->salesQuantity),
            returnsAmountBps: DashboardChange::relativeBps($current->returnsAmountMinor, $previous->returnsAmountMinor),
            returnsQuantityBps: DashboardChange::relativeBps($current->returnsQuantity, $previous->returnsQuantity),
            advertisingBps: DashboardChange::relativeBps($current->advertisingMinor, $previous->advertisingMinor),
            costPerOrderBps: DashboardChange::relativeBps($current->costPerOrderMinor, $previous->costPerOrderMinor),
            drrOrdersPointsBps: DashboardChange::pointsBps($current->drrOrdersBps, $previous->drrOrdersBps),
            costPerSaleBps: DashboardChange::relativeBps($current->costPerSaleMinor, $previous->costPerSaleMinor),
            drrSalesPointsBps: DashboardChange::pointsBps($current->drrSalesBps, $previous->drrSalesBps),
            deliveryLogisticsBps: DashboardChange::relativeBps($current->deliveryLogisticsMinor, $previous->deliveryLogisticsMinor),
            deliveryCountBps: DashboardChange::relativeBps($current->deliveryCount, $previous->deliveryCount),
            returnLogisticsBps: DashboardChange::relativeBps($current->returnLogisticsMinor, $previous->returnLogisticsMinor),
            returnLogisticsCountBps: DashboardChange::relativeBps($current->returnLogisticsCount, $previous->returnLogisticsCount),
            buyoutRatePointsBps: DashboardChange::pointsBps($current->buyoutRateBps, $previous->buyoutRateBps),
            buyoutQuantityBps: DashboardChange::relativeBps($current->buyoutQuantity, $previous->buyoutQuantity),
            buyoutOrderedQuantityBps: DashboardChange::relativeBps($current->buyoutOrderedQuantity, $previous->buyoutOrderedQuantity),
            profitBps: DashboardChange::relativeBps($current->profitMinor, $previous->profitMinor),
        );
    }

    private static function noChanges(): DashboardChanges
    {
        return new DashboardChanges(null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null);
    }

    /**
     * @param list<DashboardTotalsRow> $totals
     */
    private static function totalsOf(array $totals, bool $current): ?DashboardTotalsRow
    {
        foreach ($totals as $row) {
            if ($row->current === $current) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param list<DashboardTotalsRow>       $totals
     * @param list<DashboardDailyRow>        $daily
     * @param list<DashboardProfitTotalsRow> $profitTotals
     * @param list<DashboardProfitDailyRow>  $profitDaily
     */
    private static function singleCurrency(array $totals, array $daily, array $profitTotals, array $profitDaily): string
    {
        foreach (array_merge($profitTotals, $profitDaily) as $row) {
            if ($row->costCurrencyMismatchQuantity > 0) {
                throw new \RuntimeException('Валюта себестоимости не совпадает с валютой начисления (ADR-004).');
            }
        }
        $currencies = array_values(array_unique(array_merge(
            array_map(static fn (DashboardTotalsRow $row): string => $row->currency, $totals),
            array_map(static fn (DashboardDailyRow $row): string => $row->currency, $daily),
            array_map(static fn (DashboardProfitTotalsRow $row): string => $row->currency, $profitTotals),
            array_map(static fn (DashboardProfitDailyRow $row): string => $row->currency, $profitDaily),
        )));

        if (\count($currencies) > 1) {
            // Одна валюта — свойство сводки целиком: суммы разных валют
            // не складываются и не сравниваются (ADR-004).
            throw new \RuntimeException('За периоды сводки встретилось несколько валют — главная не складывает их в одну сумму (ADR-004).');
        }

        // Пустая сводка — валюта коннектора Ozon, как у юнит-экономики.
        return $currencies[0] ?? 'RUB';
    }

    /** @param list<DashboardProfitTotalsRow> $totals */
    private static function profitTotalsOf(array $totals, bool $current): ?DashboardProfitTotalsRow
    {
        foreach ($totals as $row) {
            if ($row->current === $current) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAll(\Doctrine\DBAL\Query\QueryBuilder $query, int $ceiling): array
    {
        $rows = $this->connection->fetchAllAssociative($query->getSQL(), $query->getParameters(), $query->getParameterTypes());
        if (\count($rows) >= $ceiling) {
            throw new \RuntimeException('Dashboard query hit its protective row ceiling.');
        }

        return $rows;
    }

    private static function int(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('Expected an integer coverage count.');
    }
}
