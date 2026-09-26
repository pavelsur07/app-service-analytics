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
            .' SELECT (SELECT MAX(snapshot_date) FROM last_run) AS snapshot_date,'
            .' (SELECT complete_days FROM correction) AS complete_days,'
            .' (SELECT applied FROM correction) AS correction_applied,'
            ." COUNT(*) FILTER (WHERE status = 'deficit')::bigint AS deficit_positions,"
            ." COALESCE(SUM(recommended) FILTER (WHERE status = 'deficit'), 0)::bigint AS deficit_units,"
            ." COUNT(*) FILTER (WHERE status = 'surplus')::bigint AS surplus_positions,"
            .' COALESCE(SUM(recommended), 0)::bigint AS recommended_units,'
            .' COUNT(*) FILTER (WHERE recommended > 0)::bigint AS recommended_positions'
            .' FROM rows_';

        return $this->connection->createQueryBuilder()
            ->select('summary.*')
            ->from('('.$source.')', 'summary')
            ->setParameters(StockPlacementSql::parameters($companyId, $today, $targetDays, $leadDays));
    }
}
