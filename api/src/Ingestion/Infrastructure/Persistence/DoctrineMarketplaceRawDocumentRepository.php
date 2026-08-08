<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\MarketplaceRawDocument;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use Doctrine\DBAL\Connection;

/**
 * DBAL, не ORM (CLAUDE.md §6: raw — данные пайплайна, не человека).
 * INSERT ... ON CONFLICT DO NOTHING по естественному ключу — идентичный
 * контент того же периода молча пропускается, не ошибка.
 */
final readonly class DoctrineMarketplaceRawDocumentRepository implements MarketplaceRawDocumentRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function add(MarketplaceRawDocument $document): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO marketplace_raw_document
                    (id, company_id, marketplace_account_id, report_type, period, body_hash, body, received_at)
                VALUES
                    (:id, :companyId, :marketplaceAccountId, :reportType, :period, :bodyHash, :body, :receivedAt)
                ON CONFLICT (company_id, marketplace_account_id, report_type, period, body_hash)
                DO NOTHING
                SQL,
            [
                'id' => $document->id()->toRfc4122(),
                'companyId' => $document->companyId()->toRfc4122(),
                'marketplaceAccountId' => $document->marketplaceAccountId()->toRfc4122(),
                'reportType' => $document->reportType(),
                'period' => $document->period()->format('Y-m-d'),
                'bodyHash' => $document->bodyHash(),
                'body' => $document->body(),
                'receivedAt' => $document->receivedAt()->format('Y-m-d H:i:sP'),
            ],
        );
    }
}
