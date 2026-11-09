<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Infrastructure\Persistence\RawDocumentBody;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class OzonCampaignCatalogSourceQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function latest(string $companyId, string $accountId): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(RawDocumentBody::COLUMNS, 'id', 'received_at')
            ->from('marketplace_raw_document')
            ->where('company_id=:company')->andWhere('marketplace_account_id=:account')->andWhere('report_type=:type')
            ->setParameter('company', $companyId)->setParameter('account', $accountId)->setParameter('type', MarketplaceReportType::OzonAdCampaigns)
            ->orderBy('received_at', 'DESC')->addOrderBy('id', 'DESC')->setMaxResults(1);
    }
}
