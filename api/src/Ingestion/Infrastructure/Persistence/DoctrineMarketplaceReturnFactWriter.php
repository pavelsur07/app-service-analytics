<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\MarketplaceReturnFact;
use App\Ingestion\Domain\MarketplaceReturnFactRepository;
use Doctrine\DBAL\Connection;

/**
 * DBAL bulk upsert факт-таблицы (CLAUDE.md §6, ADR-019).
 */
final readonly class DoctrineMarketplaceReturnFactWriter implements MarketplaceReturnFactRepository
{
    private const int CHUNK_SIZE = 500;

    public function __construct(private Connection $connection)
    {
    }

    public function upsertAll(array $facts): void
    {
        foreach (array_chunk($facts, self::CHUNK_SIZE) as $chunk) {
            $this->upsertChunk($chunk);
        }
    }

    /**
     * @param list<MarketplaceReturnFact> $facts
     */
    private function upsertChunk(array $facts): void
    {
        if ([] === $facts) {
            return;
        }

        $values = [];
        $parameters = [];
        foreach ($facts as $index => $fact) {
            $values[] = "(:companyId{$index}, :accountId{$index}, :sourceRowId{$index}, :orderNumber{$index}, "
                .":marketplaceSku{$index}, :returnType{$index}, :returnReasonName{$index}, :postingNumber{$index}, "
                .":sourceId{$index}, :quantity{$index}, :visualStatusId{$index}, :visualStatus{$index}, "
                .":visualStatusChangedAt{$index}, :rawDocumentId{$index}, :rowHash{$index}, "
                .":firstLoadedAt{$index}, :lastUpdatedAt{$index})";

            $parameters["companyId{$index}"] = $fact->companyId()->toRfc4122();
            $parameters["accountId{$index}"] = $fact->marketplaceAccountId()->toRfc4122();
            $parameters["sourceRowId{$index}"] = $fact->sourceRowId();
            $parameters["orderNumber{$index}"] = $fact->orderNumber();
            $parameters["marketplaceSku{$index}"] = $fact->marketplaceSku();
            $parameters["returnType{$index}"] = $fact->returnType();
            $parameters["returnReasonName{$index}"] = $fact->returnReasonName();
            $parameters["postingNumber{$index}"] = $fact->postingNumber();
            $parameters["sourceId{$index}"] = $fact->sourceId();
            $parameters["quantity{$index}"] = $fact->quantity();
            $parameters["visualStatusId{$index}"] = $fact->visualStatusId();
            $parameters["visualStatus{$index}"] = $fact->visualStatus();
            $parameters["visualStatusChangedAt{$index}"] = $fact->visualStatusChangedAt()->format('Y-m-d H:i:s');
            $parameters["rawDocumentId{$index}"] = $fact->rawDocumentId()->toRfc4122();
            $parameters["rowHash{$index}"] = $fact->rowHash();
            $parameters["firstLoadedAt{$index}"] = $fact->firstLoadedAt()->format('Y-m-d H:i:sP');
            $parameters["lastUpdatedAt{$index}"] = $fact->lastUpdatedAt()->format('Y-m-d H:i:sP');
        }

        $sql = <<<SQL
            INSERT INTO marketplace_return_fact
                (company_id, marketplace_account_id, source_row_id, order_number, marketplace_sku,
                 return_type, return_reason_name, posting_number, source_id, quantity,
                 visual_status_id, visual_status, visual_status_changed_at, raw_document_id,
                 row_hash, first_loaded_at, last_updated_at)
            VALUES {$this->joinValues($values)}
            ON CONFLICT (company_id, marketplace_account_id, source_row_id)
            DO UPDATE SET
                order_number = EXCLUDED.order_number,
                marketplace_sku = EXCLUDED.marketplace_sku,
                return_type = EXCLUDED.return_type,
                return_reason_name = EXCLUDED.return_reason_name,
                posting_number = EXCLUDED.posting_number,
                source_id = EXCLUDED.source_id,
                quantity = EXCLUDED.quantity,
                visual_status_id = EXCLUDED.visual_status_id,
                visual_status = EXCLUDED.visual_status,
                visual_status_changed_at = EXCLUDED.visual_status_changed_at,
                raw_document_id = EXCLUDED.raw_document_id,
                row_hash = EXCLUDED.row_hash,
                last_updated_at = EXCLUDED.last_updated_at
            WHERE marketplace_return_fact.row_hash IS DISTINCT FROM EXCLUDED.row_hash
              AND (
                  marketplace_return_fact.raw_document_id = EXCLUDED.raw_document_id
                  OR (marketplace_return_fact.visual_status_changed_at, marketplace_return_fact.raw_document_id)
                     < (EXCLUDED.visual_status_changed_at, EXCLUDED.raw_document_id)
              )
            SQL;

        $this->connection->executeStatement($sql, $parameters);
    }

    /**
     * @param list<string> $values
     */
    private function joinValues(array $values): string
    {
        return implode(', ', $values);
    }
}
