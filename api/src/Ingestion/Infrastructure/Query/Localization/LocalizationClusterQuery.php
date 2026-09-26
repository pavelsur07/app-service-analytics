<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Localization;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Отчёт «Локализация» по кластеру доставки: показатели и три главных
 * кластера, из которых фактически везли. Кластеров — справочник Ozon
 * (около двадцати пяти), но список всё равно ограничен (CLAUDE.md §5).
 */
final readonly class LocalizationClusterQuery
{
    public const int LIMIT = 50;
    private const int TOP_SOURCES = 3;

    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        $metrics = LocalizationSql::metricsSelect();
        $topSources = self::TOP_SOURCES;
        $source = 'WITH '.LocalizationSql::linesCte().<<<SQL
            ,
            sources AS (
                SELECT cluster_to, cluster_from, SUM(quantity)::bigint AS quantity,
                       ROUND(10000::numeric * SUM(quantity)
                             / SUM(SUM(quantity)) OVER (PARTITION BY cluster_to))::int AS share_bps,
                       ROW_NUMBER() OVER (PARTITION BY cluster_to ORDER BY SUM(quantity) DESC, cluster_from) AS rank
                FROM lines
                WHERE has_clusters
                GROUP BY cluster_to, cluster_from
            ),
            top_sources AS (
                SELECT cluster_to,
                       jsonb_agg(jsonb_build_object('cluster', cluster_from, 'quantity', quantity, 'share_bps', share_bps)
                                 ORDER BY rank) AS top_sources
                FROM sources
                WHERE rank <= {$topSources}
                GROUP BY cluster_to
            ),
            by_cluster AS (
                SELECT cluster_to, {$metrics}
                FROM lines
                WHERE has_clusters
                GROUP BY cluster_to
            )
            SELECT c.*, t.top_sources
            FROM by_cluster c
            JOIN top_sources t ON t.cluster_to = c.cluster_to
            SQL;

        return $this->connection->createQueryBuilder()
            ->select('cluster.*')
            ->from('('.$source.')', 'cluster')
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('cluster.clustered_quantity', 'DESC')
            ->addOrderBy('cluster.cluster_to', 'ASC')
            // +1 — узнать, что список обрезан, без COUNT(*) (CLAUDE.md §5).
            ->setMaxResults(self::LIMIT + 1);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): LocalizationClusterRow
    {
        $sources = [];
        $decoded = json_decode(LocalizationMetrics::string($row['top_sources']), true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \UnexpectedValueException('Localization top sources must be a JSON array.');
        }
        foreach ($decoded as $source) {
            if (!\is_array($source)) {
                throw new \UnexpectedValueException('Localization top source must be an object.');
            }
            $sources[] = new LocalizationSourceCluster(
                cluster: LocalizationMetrics::string($source['cluster'] ?? null),
                quantity: LocalizationMetrics::int($source['quantity'] ?? null),
                shareBps: LocalizationMetrics::int($source['share_bps'] ?? null),
            );
        }

        return new LocalizationClusterRow(
            clusterTo: LocalizationMetrics::string($row['cluster_to']),
            metrics: LocalizationMetrics::fromRow($row),
            topSources: $sources,
        );
    }
}
