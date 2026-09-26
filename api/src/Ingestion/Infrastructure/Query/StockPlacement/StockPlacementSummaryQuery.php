<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\StockPlacement;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Сводка: дата последнего полного снимка, число полных дней снимков в окне
 * спроса (поправка на дефицит работает от DEMAND_WINDOW_DAYS), позиции
 * и штуки по статусам.
 */
final readonly class StockPlacementSummaryQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, \DateTimeImmutable $today, int $targetDays, int $leadDays): QueryBuilder
    {
        $source = 'WITH '.StockPlacementSql::placementCte()
            .', rows_ AS (SELECT '.StockPlacementSql::rowSelect().' FROM measured m)'
            // Самый старый из использованных снимков: свежесть отчёта —
            // по худшему подключению, а не по лучшему.
            .' SELECT (SELECT MIN(snapshot_date) FROM last_run) AS snapshot_date,'
            .' (SELECT complete_days FROM correction) AS complete_days,'
            .' (SELECT applied FROM correction) AS correction_applied,'
            ." COUNT(*) FILTER (WHERE status = 'deficit')::bigint AS deficit_positions,"
            ." COALESCE(SUM(recommended) FILTER (WHERE status = 'deficit'), 0)::bigint AS deficit_units,"
            ." COUNT(*) FILTER (WHERE status = 'surplus')::bigint AS surplus_positions,"
            .' COALESCE(SUM(recommended), 0)::bigint AS recommended_units,'
            .' COUNT(*) FILTER (WHERE recommended > 0)::bigint AS recommended_positions,'
            ." COUNT(*) FILTER (WHERE status = 'unknown_stock')::bigint AS unknown_positions,"
            // Подключения, снимавшие остатки в окне, но без свежего полного
            // снимка: их остаток не учтён — сводка говорит об этом прямо.
            .' (SELECT COUNT(DISTINCT marketplace_account_id) FROM stock_runs'
            .'   WHERE marketplace_account_id NOT IN (SELECT marketplace_account_id FROM last_run))::bigint AS stale_accounts'
            .' FROM rows_';

        return $this->connection->createQueryBuilder()
            ->select('summary.*')
            ->from('('.$source.')', 'summary')
            ->setParameters(StockPlacementSql::parameters($companyId, $today, $targetDays, $leadDays));
    }
}
