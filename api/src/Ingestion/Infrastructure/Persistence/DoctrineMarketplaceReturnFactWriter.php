<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\FactCaptureOrigin;
use App\Ingestion\Domain\MarketplaceReturnFact;
use App\Ingestion\Domain\MarketplaceReturnFactRepository;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

/**
 * DBAL bulk upsert факт-таблицы (CLAUDE.md §6, ADR-019).
 */
final readonly class DoctrineMarketplaceReturnFactWriter implements MarketplaceReturnFactRepository
{
    private const int CHUNK_SIZE = 500;

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    public function upsertAll(array $facts, FactCaptureOrigin $origin = FactCaptureOrigin::Unknown): void
    {
        foreach (array_chunk($facts, self::CHUNK_SIZE) as $chunk) {
            $this->upsertChunk($chunk, $origin);
        }
    }

    /**
     * @param list<MarketplaceReturnFact> $facts
     */
    private function upsertChunk(array $facts, FactCaptureOrigin $origin): void
    {
        if ([] === $facts) {
            return;
        }

        $initial = FactCaptureOrigin::LivePolling === $origin ? 'true' : 'false';
        $values = [];
        $parameters = [];
        foreach ($facts as $index => $fact) {
            $values[] = "(:companyId{$index}, :accountId{$index}, :sourceRowId{$index}, :orderNumber{$index}, "
                .":marketplaceSku{$index}, :returnType{$index}, :returnReasonName{$index}, :postingNumber{$index}, "
                .":sourceId{$index}, :quantity{$index}, :visualStatusId{$index}, :visualStatus{$index}, "
                .":visualStatusChangedAt{$index}, :rawDocumentId{$index}, :rowHash{$index}, "
                .":firstLoadedAt{$index}, :lastUpdatedAt{$index}, 1, {$initial})";

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
                 row_hash, first_loaded_at, last_updated_at, fact_revision, capture_initial)
            VALUES {$this->joinValues($values)}
            ON CONFLICT (company_id, marketplace_account_id, source_row_id)
            DO UPDATE SET
                fact_revision = marketplace_return_fact.fact_revision + 1,
                capture_initial = false,
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

        $this->connection->executeStatement($this->captureSql($sql, $facts, $parameters, $origin), $parameters);
    }

    /**
     * @param list<string> $values
     */
    private function joinValues(array $values): string
    {
        return implode(', ', $values);
    }

    /**
     * Only accepted RETURNING states enter history; ledger error aborts the UPSERT.
     *
     * @param list<MarketplaceReturnFact>    $facts
     * @param array<string, int|string|null> $parameters
     */
    private function captureSql(string $upsert, array $facts, array &$parameters, FactCaptureOrigin $origin): string
    {
        $keys = [];
        foreach ($facts as $i => $fact) {
            $keys[] = "(CAST(:companyId{$i} AS uuid), CAST(:accountId{$i} AS uuid), CAST(:sourceRowId{$i} AS text), CAST(:revisionId{$i} AS uuid))";
            $parameters["revisionId{$i}"] = Uuid::v7()->toRfc4122();
        }
        $parameters['capturedAt'] = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $parameters['captureOrigin'] = $origin->value;
        $parameters['capturePath'] = 'upsert';
        $ids = implode(', ', $keys);

        return <<<SQL
            WITH accepted AS (
                {$upsert}
                RETURNING company_id, marketplace_account_id, source_row_id, order_number, marketplace_sku, return_type, return_reason_name, posting_number, source_id, quantity, visual_status_id, visual_status, visual_status_changed_at, raw_document_id, row_hash, first_loaded_at, last_updated_at, fact_revision, capture_initial
            )
            INSERT INTO marketplace_return_fact_revision (company_id, marketplace_account_id, source_row_id, order_number, marketplace_sku, return_type, return_reason_name, posting_number, source_id, quantity, visual_status_id, visual_status, visual_status_changed_at, raw_document_id, row_hash, first_loaded_at, last_updated_at, fact_revision, id, captured_at, capture_version, capture_origin, capture_path, capture_is_initial)
            SELECT accepted.company_id, accepted.marketplace_account_id, accepted.source_row_id, accepted.order_number, accepted.marketplace_sku, accepted.return_type, accepted.return_reason_name, accepted.posting_number, accepted.source_id, accepted.quantity, accepted.visual_status_id, accepted.visual_status, accepted.visual_status_changed_at, accepted.raw_document_id, accepted.row_hash, accepted.first_loaded_at, accepted.last_updated_at, accepted.fact_revision, keys.id,
                   CAST(:capturedAt AS timestamp), 'accepted_state_v1', :captureOrigin, :capturePath, accepted.capture_initial
            FROM accepted
            JOIN (VALUES {$ids}) AS keys (company_id, marketplace_account_id, source_row_id, id)
              ON keys.company_id = accepted.company_id
             AND keys.marketplace_account_id = accepted.marketplace_account_id
             AND keys.source_row_id = accepted.source_row_id
            SQL;
    }
}
