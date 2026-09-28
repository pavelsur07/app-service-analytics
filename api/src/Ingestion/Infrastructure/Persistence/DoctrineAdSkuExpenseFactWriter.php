<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\AdSkuExpenseFact;
use App\Ingestion\Domain\AdSkuExpenseFactRepository;
use Doctrine\DBAL\Connection;

/**
 * DBAL, не ORM (CLAUDE.md §6). Устроен как
 * DoctrineMarketplaceExpenseFactWriter, но условие обновления другое:
 * ответ не старше записанного (ADR-035 п. 2). Периоды SKU-отчётов
 * перекрываются, и порядок обработки документов очередью не совпадает
 * с порядком их получения.
 *
 * Более новый ответ с той же суммой тоже продвигает отметку получения
 * и ссылку на raw: иначе ответ, полученный между ними, прошёл бы
 * сравнение с устаревшей отметкой и вернул старую сумму. Время
 * последнего обновления (ADR-006) при этом меняется только вместе
 * с суммой — оно про изменение данных, а не про подтверждение.
 *
 * first_loaded_at в SET не входит вовсе.
 */
final readonly class DoctrineAdSkuExpenseFactWriter implements AdSkuExpenseFactRepository
{
    private const int CHUNK_SIZE = 500;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function upsertAll(array $facts): void
    {
        foreach (array_chunk($facts, self::CHUNK_SIZE) as $chunk) {
            $this->upsertChunk($chunk);
        }
    }

    /**
     * @param list<AdSkuExpenseFact> $facts
     */
    private function upsertChunk(array $facts): void
    {
        if ([] === $facts) {
            return;
        }

        $valuesSql = [];
        $params = [];
        foreach ($facts as $i => $fact) {
            $valuesSql[] = "(:companyId{$i}, :marketplaceAccountId{$i}, :sourceRowId{$i}, :businessDate{$i}, "
                .":campaignId{$i}, :marketplaceSku{$i}, :amountMinor{$i}, :currency{$i}, :rawDocumentId{$i}, "
                .":sourceReceivedAt{$i}, :rowHash{$i}, :firstLoadedAt{$i}, :lastUpdatedAt{$i})";

            $params["companyId{$i}"] = $fact->companyId()->toRfc4122();
            $params["marketplaceAccountId{$i}"] = $fact->marketplaceAccountId()->toRfc4122();
            $params["sourceRowId{$i}"] = $fact->sourceRowIdValue();
            $params["businessDate{$i}"] = $fact->businessDate()->format('Y-m-d');
            $params["campaignId{$i}"] = $fact->campaignId();
            $params["marketplaceSku{$i}"] = $fact->marketplaceSku();
            $params["amountMinor{$i}"] = $fact->amount()->minorAmount();
            $params["currency{$i}"] = $fact->amount()->currency();
            $params["rawDocumentId{$i}"] = $fact->rawDocumentId()->toRfc4122();
            $params["sourceReceivedAt{$i}"] = $fact->sourceReceivedAt()->format('Y-m-d H:i:sP');
            $params["rowHash{$i}"] = $fact->rowHash();
            $params["firstLoadedAt{$i}"] = $fact->firstLoadedAt()->format('Y-m-d H:i:sP');
            $params["lastUpdatedAt{$i}"] = $fact->lastUpdatedAt()->format('Y-m-d H:i:sP');
        }

        $values = implode(', ', $valuesSql);
        $sql = <<<SQL
            INSERT INTO ad_sku_expense_fact
                (company_id, marketplace_account_id, source_row_id, business_date, campaign_id,
                 marketplace_sku, amount_minor, currency, raw_document_id, source_received_at,
                 row_hash, first_loaded_at, last_updated_at)
            VALUES {$values}
            ON CONFLICT (company_id, marketplace_account_id, source_row_id)
            DO UPDATE SET
                amount_minor = EXCLUDED.amount_minor,
                currency = EXCLUDED.currency,
                raw_document_id = EXCLUDED.raw_document_id,
                source_received_at = EXCLUDED.source_received_at,
                row_hash = EXCLUDED.row_hash,
                last_updated_at = CASE
                    WHEN ad_sku_expense_fact.row_hash IS DISTINCT FROM EXCLUDED.row_hash THEN EXCLUDED.last_updated_at
                    ELSE ad_sku_expense_fact.last_updated_at
                END
            WHERE ad_sku_expense_fact.source_received_at <= EXCLUDED.source_received_at
            SQL;

        $this->connection->executeStatement($sql, $params);
    }
}
