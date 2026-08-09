<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\MarketplaceRawDocument;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * DBAL, не ORM (CLAUDE.md §6: raw — данные пайплайна, не человека).
 * INSERT ... ON CONFLICT DO UPDATE (no-op на существующее значение)
 * по естественному ключу — не DO NOTHING: тот не даёт RETURNING id
 * при конфликте, а вызывающему нужен id уже существующей строки,
 * не только успешной вставки.
 */
final readonly class DoctrineMarketplaceRawDocumentRepository implements MarketplaceRawDocumentRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function add(MarketplaceRawDocument $document): Uuid
    {
        $id = $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO marketplace_raw_document
                    (id, company_id, marketplace_account_id, report_type, period, body_hash, body, received_at)
                VALUES
                    (:id, :companyId, :marketplaceAccountId, :reportType, :period, :bodyHash, :body, :receivedAt)
                ON CONFLICT (company_id, marketplace_account_id, report_type, period, body_hash)
                DO UPDATE SET received_at = marketplace_raw_document.received_at
                RETURNING id
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

        \assert(\is_string($id));

        return Uuid::fromString($id);
    }
}
