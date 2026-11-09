<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\OzonCampaignCatalogRepository;
use App\Ingestion\Domain\OzonCampaignCatalogSnapshot;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DoctrineOzonCampaignCatalogRepository implements OzonCampaignCatalogRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function replace(string $companyId, OzonCampaignCatalogSnapshot $snapshot): void
    {
        if ($companyId !== $snapshot->companyId()->toRfc4122()) {
            throw new \InvalidArgumentException('Catalog company mismatch.');
        }
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO ozon_campaign_catalog_snapshot (company_id, account_id, raw_document_id, received_at, campaigns, total, complete, restored)
                VALUES (:company, :account, :raw, :at, CAST(:campaigns AS jsonb), :total, :complete, :restored)
                ON CONFLICT (company_id, account_id) DO UPDATE SET
                    raw_document_id=EXCLUDED.raw_document_id, received_at=EXCLUDED.received_at,
                    campaigns=EXCLUDED.campaigns, total=EXCLUDED.total, complete=EXCLUDED.complete, restored=EXCLUDED.restored
                WHERE NOT EXCLUDED.restored AND (
                    ozon_campaign_catalog_snapshot.restored
                    OR ozon_campaign_catalog_snapshot.received_at < EXCLUDED.received_at
                    OR (ozon_campaign_catalog_snapshot.received_at = EXCLUDED.received_at
                        AND ozon_campaign_catalog_snapshot.raw_document_id < EXCLUDED.raw_document_id))
                SQL,
            ['company' => $companyId, 'account' => $snapshot->accountId()->toRfc4122(), 'raw' => $snapshot->rawDocumentId()->toRfc4122(), 'at' => $snapshot->receivedAt()->format('Y-m-d H:i:s.uP'), 'campaigns' => json_encode($snapshot->campaigns(), \JSON_THROW_ON_ERROR), 'total' => $snapshot->total(), 'complete' => $snapshot->complete(), 'restored' => $snapshot->restored()],
            ['complete' => ParameterType::BOOLEAN, 'restored' => ParameterType::BOOLEAN],
        );
    }
}
