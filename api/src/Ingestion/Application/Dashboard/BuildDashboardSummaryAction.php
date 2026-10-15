<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Dashboard;

use App\Ingestion\Application\Buyout\BuildBuyoutRateReportAction;
use App\Ingestion\Application\Buyout\BuyoutRateSummary;
use App\Ingestion\Infrastructure\Query\Dashboard\DashboardDailyRow;
use App\Ingestion\Infrastructure\Query\Dashboard\DashboardLoadsQuery;
use App\Ingestion\Infrastructure\Query\Dashboard\DashboardMetricsQuery;
use App\Ingestion\Infrastructure\Query\Dashboard\DashboardTotalsRow;
use App\Ingestion\Infrastructure\Query\UnitEconomics\ExpenseCoverageQuery;
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
        // версии данных и ряд другой. Внутри уже открытой транзакции
        // (тесты, вызывающий сценарий) уровень изоляции выбирает её
        // владелец, и SET TRANSACTION после его запросов невозможен.
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
        $currency = self::singleCurrency($totals, $daily);

        $start = $this->loads->dataStart($companyId);
        $dataStartsOn = DashboardLoadsQuery::mapDataStart(
            $this->connection->fetchAssociative($start->getSQL(), $start->getParameters(), $start->getParameterTypes()),
        );

        $current = $this->period($companyId, $from, $to, $asOf, self::totalsOf($totals, true), $daily);
        $comparison = $this->period($companyId, $compareFrom, $compareTo, $asOf, self::totalsOf($totals, false), $daily);

        return new DashboardSummary(
            currency: $currency,
            dataStartsOn: $dataStartsOn,
            current: $current,
            comparison: $comparison,
            changes: $current->dataComplete && $comparison->dataComplete
                ? self::changes($current->metrics, $comparison->metrics)
                : self::noChanges(),
        );
    }

    /**
     * @param list<DashboardDailyRow> $daily
     */
    private function period(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $asOf,
        ?DashboardTotalsRow $totals,
        array $daily,
    ): DashboardPeriodSummary {
        $coverage = $this->coverage->daysWithoutExpenses($companyId, $from, $to);
        $daysWithoutAccruals = self::int($this->connection->fetchOne($coverage->getSQL(), $coverage->getParameters(), $coverage->getParameterTypes()));
        $loads = $this->loads->complete($companyId, $from, $to);
        $loaded = DashboardLoadsQuery::mapComplete(
            $this->connection->fetchAssociative($loads->getSQL(), $loads->getParameters(), $loads->getParameterTypes()),
        );
        $buyout = ($this->buyout)($companyId, $from, $to, $asOf, 1)->summary;

        return new DashboardPeriodSummary(
            from: $from->format('Y-m-d'),
            to: $to->format('Y-m-d'),
            // Полон — каждое подключение загрузило оба источника на всём
            // периоде, и ни одного дня с продажами без начислений.
            dataComplete: $loaded && 0 === $daysWithoutAccruals,
            daysWithoutAccruals: $daysWithoutAccruals,
            metrics: self::metrics($totals, $buyout),
            days: self::days($from, $to, $daily),
        );
    }

    private static function metrics(?DashboardTotalsRow $row, BuyoutRateSummary $buyout): DashboardMetrics
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
        );
    }

    /**
     * Каждый день периода, включая дни без фактов, — иначе спарклайн
     * соединил бы соседние точки через пропуск и нарисовал рост там,
     * где был ноль.
     *
     * @param list<DashboardDailyRow> $daily
     *
     * @return list<DashboardDay>
     */
    private static function days(\DateTimeImmutable $from, \DateTimeImmutable $to, array $daily): array
    {
        $byDate = [];
        foreach ($daily as $row) {
            $byDate[$row->date] = $row;
        }

        $days = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $row = $byDate[$day->format('Y-m-d')] ?? null;
            $days[] = new DashboardDay(
                date: $day->format('Y-m-d'),
                ordersAmountMinor: $row->ordersAmountMinor ?? 0,
                salesAmountMinor: $row->salesAmountMinor ?? 0,
                returnsAmountMinor: $row->returnsAmountMinor ?? 0,
                advertisingMinor: $row->advertisingMinor ?? 0,
                deliveryLogisticsMinor: $row->deliveryLogisticsMinor ?? 0,
                returnLogisticsMinor: $row->returnLogisticsMinor ?? 0,
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
        );
    }

    private static function noChanges(): DashboardChanges
    {
        return new DashboardChanges(null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null);
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
     * @param list<DashboardTotalsRow> $totals
     * @param list<DashboardDailyRow>  $daily
     */
    private static function singleCurrency(array $totals, array $daily): string
    {
        $currencies = array_values(array_unique(array_merge(
            array_map(static fn (DashboardTotalsRow $row): string => $row->currency, $totals),
            array_map(static fn (DashboardDailyRow $row): string => $row->currency, $daily),
        )));

        if (\count($currencies) > 1) {
            // Одна валюта — свойство сводки целиком: суммы разных валют
            // не складываются и не сравниваются (ADR-004).
            throw new \RuntimeException('За периоды сводки встретилось несколько валют — главная не складывает их в одну сумму (ADR-004).');
        }

        // Пустая сводка — валюта коннектора Ozon, как у юнит-экономики.
        return $currencies[0] ?? 'RUB';
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
