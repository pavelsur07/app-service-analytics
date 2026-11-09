<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

/** One atomic snapshot read; the JSONB projection is paginated by SQL. */
final readonly class OzonCampaignCatalogQuery
{
    public const int MAX_LIMIT = 200;

    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, string $accountId, ?string $after, int $limit, bool $includeArchived): QueryBuilder
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException('Invalid catalog limit.');
        }
        $filters = [];
        if (!$includeArchived) {
            $filters[] = "campaign->>'state' <> 'CAMPAIGN_STATE_ARCHIVED'";
        }
        if (null !== $after) {
            $filters[] = "(length(campaign->>'id'), campaign->>'id') > (length(CAST(:after AS text)), CAST(:after AS text))";
        }
        $where = [] === $filters ? '' : 'WHERE '.implode(' AND ', $filters);
        $page = <<<SQL
            LATERAL (
                SELECT jsonb_agg(campaign ORDER BY length(campaign->>'id'), campaign->>'id') AS items
                FROM (
                    SELECT campaign FROM jsonb_array_elements(s.campaigns) AS campaign
                    $where
                    ORDER BY length(campaign->>'id'), campaign->>'id'
                    LIMIT :pageLimit
                ) limited
            )
            SQL;
        $query = $this->connection->createQueryBuilder()
            ->select('s.raw_document_id', 's.received_at', 's.complete', 's.restored', 's.total', 'jsonb_array_length(s.campaigns) AS saved_count', "COALESCE(page.items, '[]'::jsonb) AS items")
            ->from('ozon_campaign_catalog_snapshot', 's')
            ->leftJoin('s', $page, 'page', 'TRUE')
            ->where('s.company_id = :company')->andWhere('s.account_id = :account')
            ->setParameter('company', $companyId)->setParameter('account', $accountId)
            ->setParameter('pageLimit', $limit + 1, ParameterType::INTEGER)
            ->setMaxResults(1);
        if (null !== $after) {
            $query->setParameter('after', $after);
        }

        return $query;
    }
}
